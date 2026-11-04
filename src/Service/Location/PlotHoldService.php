<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location\LandPlot;
use App\Entity\Location\PlotHold;
use App\Entity\User;
use App\Exception\Location\PlotUnavailableException;
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
 * filters on expires_at independently. app:plots:expire-holds exists for hygiene and is NOT
 * required for correctness.
 */
final class PlotHoldService
{
    public function __construct(
        private readonly PlotHoldRepository     $holdRepository,
        private readonly PlotClaimRepository    $claimRepository,
        private readonly StadiumEstimateService $estimateService,
        private readonly EntityManagerInterface $em,
        private readonly LocationCapacityService $capacityService,
    ) {}

    /**
     * Take a hold on a plot, freezing both prices for fifteen minutes.
     *
     * @throws PlotUnavailableException when the plot is unsellable, already claimed, already
     *         held, or the user is already holding something else
     */
    public function acquire(User $user, LandPlot $plot): PlotHold
    {
        $this->assertSellable($plot);

        $existingClaim = $this->claimRepository->findActiveForPlot($plot);

        if ($existingClaim !== null) {
            throw PlotUnavailableException::plotClaimed($existingClaim->getClaimedByName());
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

        return $this->holdRepository->markReleased($holdId, new \DateTimeImmutable());
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
