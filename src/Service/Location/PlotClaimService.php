<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Club;
use App\Entity\Location\LandPlot;
use App\Entity\Location\PlotClaim;
use App\Entity\User;
use App\Enum\UserLedgerEntryType;
use App\Exception\Location\PlotUnavailableException;
use App\Repository\Location\PlotClaimRepository;
use App\Repository\Location\PlotHoldRepository;
use App\Repository\Location\StadiumEstimateRepository;
use App\Repository\UserLedgerRepository;
use App\Service\UserLedgerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turning a hold into ownership, and charging for it.
 *
 * ONE TRANSACTION, and the order inside it is deliberate:
 *
 *   1. SELECT ... FOR UPDATE on the hold. This is the serialisation point for a double-tapped
 *      CLAIM: both requests read the same hold row, one gets the lock, and the other then sees
 *      consumed_at already set rather than buying the plot twice.
 *   2. Validate the hold — ours, live, not already spent.
 *   3. Check credit, then charge through UserLedgerService::append(), which takes its own
 *      per-user advisory lock so the balance chain cannot fork.
 *   4. Insert the claim with ON CONFLICT DO NOTHING, so the partial unique settles any remaining
 *      race without an exception closing the EntityManager.
 *   5. Mark the hold consumed.
 *
 * MONEY COMES FROM ACCOUNT CREDIT, NEVER club.balance. The device owns a club's balance and
 * overwrites it absolutely on every sync (SyncService::process()), so a server-side deduction
 * there would survive until the next sync and then vanish. UserLedger is backend-owned and
 * durable, and its balance is deliberately cross-club — which is what makes the intended
 * progression possible at all, since funding a flagship city is expected to take the earnings of
 * ten to fifteen clubs.
 *
 * THE CLAIM OUTLIVES ITS CLUB. plot_claim.club_id is nullable and SET NULL, so a purchase record
 * survives a club being deleted, and `clubNameAtClaim` is frozen so the API can still say who
 * owns it. The owner of record is the USER.
 */
final class PlotClaimService
{
    public function __construct(
        private readonly PlotHoldRepository        $holdRepository,
        private readonly PlotClaimRepository       $claimRepository,
        private readonly StadiumEstimateRepository $estimateRepository,
        private readonly UserLedgerRepository      $ledgerRepository,
        private readonly UserLedgerService         $ledgerService,
        private readonly EntityManagerInterface    $em,
        private readonly LoggerInterface           $logger,
    ) {}

    /**
     * Buy the land a hold is on.
     *
     * @throws PlotUnavailableException on an invalid hold, a lost race, or insufficient credit
     */
    public function claimFromHold(User $user, string $holdId): PlotClaim
    {
        return $this->em->wrapInTransaction(function () use ($user, $holdId): PlotClaim {
            $now = new \DateTimeImmutable();

            // FOR UPDATE — the serialisation point. Raw, so no entity is hydrated and no
            // identity-map copy can mask another writer's change.
            $row = $this->holdRepository->lockForClaim($holdId);

            if ($row === null) {
                throw PlotUnavailableException::holdExpired();
            }

            if ((string) $row['user_id'] !== (string) $user->getId()) {
                throw PlotUnavailableException::holdNotYours();
            }

            if ($row['consumed_at'] !== null) {
                throw PlotUnavailableException::holdConsumed();
            }

            if ($row['released_at'] !== null || new \DateTimeImmutable((string) $row['expires_at']) <= $now) {
                throw PlotUnavailableException::holdExpired();
            }

            /** @var LandPlot|null $plot */
            $plot = $this->em->find(LandPlot::class, (string) $row['plot_id']);

            if ($plot === null) {
                throw PlotUnavailableException::notSellable('the plot no longer exists');
            }

            // The FROZEN figures, from the hold — not recomputed. This is the promise the hold
            // exists to make, and recomputing here would quietly break it.
            $landPence = (int) $row['land_pence'];

            $balance = $this->ledgerRepository->getCurrentBalance($user);

            if ($balance < $landPence) {
                throw PlotUnavailableException::insufficientCredit($balance, $landPence);
            }

            $claimId = $this->claimRepository->insertIfUnclaimed(
                $plot,
                $user,
                $landPence,
                (string) $row['stadium_estimate_id'],
                $now,
            );

            if ($claimId === null) {
                // Lost the race against another claim on the same plot. Nothing has been charged
                // yet, because the ledger write is below this point.
                $existing = $this->claimRepository->findActiveForPlot($plot);

                throw PlotUnavailableException::plotClaimed($existing?->getClaimedByName());
            }

            if (!$this->holdRepository->markConsumed($holdId, $now)) {
                // Someone consumed or released it between the lock and here. Should be
                // unreachable while FOR UPDATE is held; treated as fatal rather than ignored so
                // it cannot silently produce an unpaid claim.
                throw PlotUnavailableException::holdConsumed();
            }

            // Charged AFTER the claim insert succeeds, so a lost race costs the player nothing.
            // append() is idempotent on (sourceKind, sourceRef, type), so a retried request
            // cannot double-charge.
            $entry = $this->ledgerService->append(
                $user,
                null,
                UserLedgerEntryType::LAND_PURCHASE,
                -$landPence,
                'plot_claim',
                $claimId,
                sprintf('Land purchase: %s', $plot->getLocation()->getTown()),
                $now,
            );

            /** @var PlotClaim $claim */
            $claim = $this->claimRepository->find($claimId);
            $claim->setLandLedgerEntry($entry);

            $this->em->flush();

            $this->logger->info('Plot claimed', [
                'claim'     => $claimId,
                'plot'      => (string) $plot->getId(),
                'town'      => $plot->getLocation()->getTown(),
                'user'      => (string) $user->getId(),
                'landPence' => $landPence,
            ]);

            return $claim;
        });
    }

    /**
     * Attach a club to a claim, freezing its name for later display.
     *
     * Separate from claiming because the brief's flow allows buying land before founding a club.
     */
    public function attachClub(PlotClaim $claim, Club $club): void
    {
        $claim->attachClub($club);
        $this->em->flush();
    }

    /**
     * Charge for the ground on a plot already owned.
     *
     * Uses the estimate the CLAIM was made against where one survives, so the player pays the
     * price they were quoted rather than whatever the model says today. Falls back to the current
     * estimate only if that row has gone.
     *
     * @throws PlotUnavailableException on insufficient credit
     */
    public function buildStadium(PlotClaim $claim): PlotClaim
    {
        if ($claim->hasStadium()) {
            return $claim;
        }

        return $this->em->wrapInTransaction(function () use ($claim): PlotClaim {
            $estimate = $claim->getStadiumEstimate()
                ?? $this->estimateRepository->findCurrentFor(
                    $claim->getLocation(),
                    StadiumEstimateService::MODEL_VERSION,
                );

            if ($estimate === null) {
                throw PlotUnavailableException::notSellable('no stadium price is available for this town');
            }

            $user    = $claim->getUser();
            $pence   = $estimate->getPence();
            $balance = $this->ledgerRepository->getCurrentBalance($user);

            if ($balance < $pence) {
                throw PlotUnavailableException::insufficientCredit($balance, $pence);
            }

            // Same sourceRef as the land charge — which is exactly why `type` is part of
            // uq_user_ledger_backend_source: one claim legitimately writes two charges.
            $entry = $this->ledgerService->append(
                $user,
                $claim->getClub(),
                UserLedgerEntryType::STADIUM_PURCHASE,
                -$pence,
                'plot_claim',
                (string) $claim->getId(),
                sprintf('Stadium build: %s', $claim->getLocation()->getTown()),
            );

            $claim->setStadiumPencePaid($pence);
            $claim->setStadiumLedgerEntry($entry);

            $this->em->flush();

            return $claim;
        });
    }
}
