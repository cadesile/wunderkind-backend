<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location\LandPlot;
use App\Entity\Location\PlotHold;
use App\Entity\User;
use App\Exception\Location\PlotUnavailableException;
use App\Repository\NpcClubRepository;
use App\Repository\Location\PlotClaimRepository;
use App\Repository\Location\PlotHoldRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Taking, keeping and releasing a fifteen-minute option on a plot.
 *
 * THE RACE THIS EXISTS TO SETTLE is two players tapping HOLD PLOT on the same plot at the same
 * instant, and it is settled by the database, not here. acquire() does no check-then-insert:
 * both requests attempt the insert, PostgreSQL's partial unique lets exactly one through, and the
 * loser is told why. A check-then-insert would let both pass the check.
 *
 * It is a raw INSERT ... ON CONFLICT DO NOTHING rather than an ORM persist specifically because a
 * UniqueConstraintViolationException out of flush() CLOSES the shared EntityManager — on a
 * user-facing write that would turn a losing tap into a failed request for everything else in
 * flight too. The LeaderboardPeriodCloseService::claim() reasoning, on a hot path.
 *
 * THE EXPIRY MECHANISM. The brief asked that expired holds be ignorable on read with no cleanup
 * job. That is delivered, but it could not be done with the index alone: a unique predicate must
 * be IMMUTABLE, so `WHERE expires_at > now()` is illegal and an expired-but-unreleased row still
 * occupies the index. So acquire() first releases exactly the two rows that could block it, which
 * makes the index agree with the read semantics at the only instant that matters. Every read
 * filters on expires_at independently. (An earlier version of this note promised an
 * app:plots:expire-holds command for hygiene. There is no such command, and nothing needs one —
 * correctness never depended on it.)
 *
 * NOTHING RUNS WHEN A HOLD LAPSES, which is why acquire() also reaps surplus plots. A hold is
 * the one thing that protects a plot from LandPlotProvisioner::reapSurplus(), and an expiring
 * hold fires no code to lift that protection — so acquire() does the collecting, at the only
 * moment a surplus plot could be sold.
 */
final class PlotHoldService
{
    public function __construct(
        private readonly PlotHoldRepository     $holdRepository,
        private readonly PlotClaimRepository    $claimRepository,
        private readonly StadiumEstimateService $estimateService,
        private readonly EntityManagerInterface $em,
        private readonly LocationCapacityService $capacityService,
        private readonly LandPlotProvisioner    $plotProvisioner,
        private readonly NpcClubRepository      $npcClubRepository,
    ) {}

    /**
     * Take a hold on a plot, freezing both prices for fifteen minutes.
     *
     * @throws PlotUnavailableException when the plot is unsellable, already claimed, already
     *         held, or the user is already holding something else
     */
    public function acquire(User $user, LandPlot $plot): PlotHold
    {
        // SURPLUS PLOTS DIE HERE, and this is the only place a lapsed hold's plot can be caught.
        // reapSurplus() skips a held plot on purpose — that hold is somebody at the checkout —
        // but a hold that simply expires fires no code, so an over-target town could be left
        // with a free plot above its band's target and no event to collect it. Acquiring is the
        // one moment that surplus could do harm: it is how the plot gets sold. Reaping first
        // means a plot that should be gone cannot be held, and therefore cannot be bought.
        //
        // IT IS NOT A SWEEP. Scoped to this one town, idempotent, and a no-op for any town at or
        // under target — which is every town that has never been demoted.
        // NARROWED to plots a dead hold is hiding. An unconditional reap here enforced the plot
        // target on every town anybody reached for, which would have retired plots an operator
        // left over target on purpose by lowering plotsByCitySize — the one thing
        // LandPlotProvisioningRulesTest pins as never happening.
        $this->plotProvisioner->reapSurplus([(string) $plot->getLocation()->getId()], formerlyHeldOnly: true);

        // The statement above is raw SQL, so this entity still believes whatever it was loaded
        // with. Without the refresh a plot just retired would pass assertSellable() and be held.
        $this->em->refresh($plot);

        // Says "the plot has been retired" if the reap took this one — the existing refusal is
        // already the right answer, so there is no new case for a caller to learn.
        $this->assertSellable($plot);

        $existingClaim = $this->claimRepository->findActiveForPlot($plot);

        if ($existingClaim !== null) {
            throw PlotUnavailableException::plotClaimed($existingClaim->getClaimedByName());
        }

        // AN ESTABLISHED CLUB'S GROUND, refused by name rather than only shrinking a total.
        //
        // The capacity check below already made this town's seated plots unavailable in
        // AGGREGATE — that is what npcOccupied has always done. What it could not do was stop a
        // player buying the SPECIFIC plot a club sits on: a two-plot town with one NPC club and
        // one free plot is not sold out, so a request naming the club's plot sailed past the
        // total and took ground that was already built on.
        //
        // Checked here, between the claim and the capacity test, for the reason the comment
        // below gives about ordering: a refusal that names what is in the way beats a vaguer
        // one about the town.
        $seatedClub = $this->npcClubRepository->findOneBy(['landPlot' => $plot]);

        if ($seatedClub !== null) {
            throw PlotUnavailableException::plotHasClub($seatedClub->getName());
        }

        // TOWN CAPACITY, CHECKED AFTER THE SPECIFIC PLOT AND NOT BEFORE. A town holds as many
        // clubs as its size allows: plots scale with city size, established clubs consume up to
        // NPC_PLOT_CAP of them, and players buy what remains.
        //
        // THE ORDER IS THE POINT. A one-plot town whose plot is already owned is BOTH "this plot
        // is taken" and "this town is full", and the first is the more useful answer — it names
        // the owner and tells the player to look elsewhere for a reason they can act on.
        // Checking capacity first would have replaced every plot_claimed with a vaguer
        // town_sold_out, which a test caught.
        //
        // Not in assertSellable() because that asks only about this plot and its town's own
        // state; this question spans npc_club and plot_claim and is about the town's population
        // of clubs.
        $capacity = $this->capacityService->forLocation((string) $plot->getLocation()->getId());

        if ($capacity['soldOut']) {
            throw PlotUnavailableException::townSoldOut(
                $plot->getLocation()->getTown(),
                $capacity['plots'],
                $capacity['npcOccupied'],
                $capacity['claimed'],
            );
        }

        // The estimate must exist BEFORE the hold, because the hold's whole purpose is to pin a
        // quote — and plot_hold.stadium_estimate_id is NOT NULL and RESTRICT, so there is
        // nothing to point at otherwise.
        $estimate = $this->estimateService->currentFor($plot->getLocation());

        if ($estimate === null) {
            throw PlotUnavailableException::notSellable('no stadium price could be calculated for this town yet');
        }

        $now = new \DateTimeImmutable();

        // Clear only the expired rows that could stand in the way. Auto-commits on its own, and
        // is idempotent, so concurrent callers may all run it harmlessly.
        $this->holdRepository->releaseBlockingExpired($plot, $user, $now);

        $holdId = $this->holdRepository->insertIfFree(
            $plot,
            $user,
            (int) $plot->getEffectiveLandPence(),
            $estimate,
            $now,
        );

        if ($holdId === null) {
            // ON CONFLICT cannot say WHICH unique blocked us, so ask — the two cases mean
            // different things to the player and map to different API responses.
            throw $this->explainConflict($user, $plot, $now);
        }

        // Read the row the raw INSERT just created. No em->clear() here: ORM 3 removed the
        // single-entity form of clear(), so clear(PlotHold::class) detaches EVERYTHING —
        // including the Location this method was handed, which then looks like a new entity to
        // the next flush. find() on an id the identity map has never seen issues a SELECT
        // anyway, which is exactly what is wanted.
        $hold = $this->holdRepository->find($holdId);

        if ($hold === null) {
            // Only reachable if something removed the row between the insert and this read.
            throw PlotUnavailableException::plotHeld();
        }

        return $hold;
    }

    /** The user's live hold, or null. */
    public function activeFor(User $user): ?PlotHold
    {
        return $this->holdRepository->findActiveForUser($user);
    }

    /**
     * Release a hold the user owns.
     *
     * Returns false when there was nothing live to release — already expired, already consumed,
     * or already cancelled. Not an error: a player pressing cancel twice is ordinary.
     *
     * @throws PlotUnavailableException if the hold belongs to someone else
     */
    public function release(User $user, string $holdId): bool
    {
        $hold = $this->holdRepository->find($holdId);

        if ($hold === null) {
            return false;
        }

        if ((string) $hold->getUser()->getId() !== (string) $user->getId()) {
            throw PlotUnavailableException::holdNotYours();
        }

        $released = $this->holdRepository->markReleased($holdId, new \DateTimeImmutable());

        if ($released) {
            // The plot was protected from the reap only by this hold. Now that it is gone, an
            // over-target town should not keep the plot merely because the player changed their
            // mind — and the acquire-time reap would otherwise not run until somebody tried to
            // buy it.
            $this->plotProvisioner->reapSurplus([(string) $hold->getPlot()->getLocation()->getId()]);
        }

        return $released;
    }

    /**
     * Why the insert conflicted.
     *
     * Checked after the fact rather than before, deliberately: checking first would be the
     * check-then-insert race the unique index exists to prevent. By the time this runs the
     * decision is already made and this is only explaining it.
     */
    private function explainConflict(User $user, LandPlot $plot, \DateTimeImmutable $now): PlotUnavailableException
    {
        $ownHold = $this->holdRepository->findActiveForUser($user, $now);

        if ($ownHold !== null) {
            return PlotUnavailableException::holdLimit($ownHold->getPlot()->getLocation()->getTown());
        }

        return PlotUnavailableException::plotHeld();
    }

    /** @throws PlotUnavailableException */
    private function assertSellable(LandPlot $plot): void
    {
        if (!$plot->isActive()) {
            throw PlotUnavailableException::notSellable('the plot has been retired');
        }

        $location = $plot->getLocation();

        if (!$location->isActive()) {
            throw PlotUnavailableException::notSellable('the town has been retired');
        }

        if (!$location->getCountry()->isLive()) {
            throw PlotUnavailableException::notSellable('this country is not open to players yet');
        }

        if (!$location->hasCoordinates()) {
            throw PlotUnavailableException::notSellable('the town has not been located yet');
        }

        if ($plot->getEffectiveLandPence() === null) {
            throw PlotUnavailableException::notSellable('the land has not been valued yet');
        }
    }
}
