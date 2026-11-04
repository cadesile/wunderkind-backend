<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location\PlotClaim;
use App\Entity\Location\PlotClaimValuation;
use App\Entity\UserLedger;
use App\Enum\Location\ClaimReleaseReason;
use App\Enum\UserLedgerEntryType;
use App\Repository\Location\PlotClaimRepository;
use App\Repository\Location\PlotClaimValuationRepository;
use App\Service\UserLedgerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Releasing a plot and paying the player for it.
 *
 * ONE PATH FOR EVERY RELEASE REASON, and that is the important design decision here rather
 * than an implementation detail. A voluntary sell-back and a 30-day dormancy reclaim pay the
 * SAME figure; only `release_reason` differs. If dormancy paid more than selling, the optimal
 * play would be to stop logging in rather than sell — which is the exact opposite of what a
 * dormancy rule is for. If it paid less, the warnings would have to be provably reliable
 * before that was fair. Equal payouts make the incentive neutral and the rule honest.
 *
 *     payoutPence = round(currentLandPence x LAND_RETURN)
 *                 + round(stadiumPencePaid x STADIUM_RETURN)
 *                 + clubValuePence                             (when a club is attached)
 *
 * LAND IS PAID AT ITS CURRENT VALUE, NOT AT WHAT WAS PAID. That is deliberate: land is
 * revalued over time, so a plot can gain or lose, and the fee below is what stops buying and
 * selling being a free round trip. The consequence to keep in mind is that a revaluation
 * changes every outstanding claim's quote — which is exactly why the full working is written
 * to plot_claim_valuation, so support can reproduce a figure after a later revaluation.
 *
 * THE CLUB IS INCLUDED BECAUSE A RELEASE LIQUIDATES IT. The club loses its ground either way,
 * so paying only for the land would quietly confiscate everything the player built on it. See
 * ClubValuationService for what a club is worth and why the squad is not in it.
 *
 * THE CLUB ROW IS NOT DELETED. Its home_location_id is nulled — it no longer plays there — but
 * deleting a player's club as a side effect of a land transaction is a product decision this
 * service deliberately does not make on its own.
 */
final class PlotSellBackService
{
    /** Bump on ANY change to the coefficients or the formula. */
    public const MODEL_VERSION = 'sellback-v1';

    /**
     * Share of the land's CURRENT value returned. The missing 10% is the transaction fee.
     *
     * A fee rather than a full refund, so churning plots has a cost and holding land is the
     * default. Land can still gain overall, because the base is the current valuation.
     */
    public const LAND_RETURN = 0.9;

    /**
     * Share of what was paid for the stadium, returned.
     *
     * Half, and on the PAID figure rather than a current estimate: a ground is a sunk
     * construction cost on one specific site, not a tradable asset that revalues. Paying a
     * current estimate would also let a player profit from a model retune.
     */
    public const STADIUM_RETURN = 0.5;

    public function __construct(
        private readonly PlotClaimRepository          $claimRepository,
        private readonly PlotClaimValuationRepository $valuationRepository,
        private readonly ClubValuationService         $clubValuation,
        private readonly UserLedgerService            $ledgerService,
        private readonly EntityManagerInterface       $em,
        private readonly LoggerInterface              $logger,
    ) {}

    /**
     * What this claim would pay out, without changing anything.
     *
     * Backs the sell-back quote endpoint, and is the same call execute() uses — so the figure a
     * player is shown and the figure they are paid cannot disagree.
     *
     * @return array<string, mixed>
     */
    public function quote(PlotClaim $claim): array
    {
        $plot     = $claim->getPlot();
        $location = $claim->getLocation();
        $club     = $claim->getClub();

        $currentLandPence = $plot->getEffectiveLandPence();
        $landBasis        = 'current_valuation';

        if ($currentLandPence === null) {
            // An un-enriched or de-valued location has no current price. Falling back to what
            // was PAID is the defensible answer — the player is made whole rather than paid
            // nothing because our own data is incomplete — and the basis is recorded so a
            // support query can see which rule applied.
            $currentLandPence = $claim->getLandPencePaid();
            $landBasis        = 'paid_fallback_no_current_valuation';
        }

        $landComponent    = (int) round($currentLandPence * self::LAND_RETURN);
        $stadiumComponent = (int) round($claim->getStadiumPencePaid() * self::STADIUM_RETURN);

        $clubBreakdown = $club !== null ? $this->clubValuation->breakdown($club) : null;
        $clubComponent = $clubBreakdown['pence'] ?? 0;

        return [
            'modelVersion'  => self::MODEL_VERSION,
            'payoutPence'   => $landComponent + $stadiumComponent + $clubComponent,
            'land' => [
                'component'        => $landComponent,
                'currentLandPence' => $currentLandPence,
                'landPencePaid'    => $claim->getLandPencePaid(),
                'landValuedAt'     => $location->getLandValuedAt()?->format(\DateTimeInterface::ATOM),
                'landSource'       => $location->getLandSource(),
                'basis'            => $landBasis,
                'returnRate'       => self::LAND_RETURN,
            ],
            'stadium' => [
                'component'         => $stadiumComponent,
                'stadiumPencePaid'  => $claim->getStadiumPencePaid(),
                'returnRate'        => self::STADIUM_RETURN,
            ],
            'club' => [
                'component' => $clubComponent,
                'clubId'    => $club !== null ? (string) $club->getId() : null,
                'clubName'  => $club?->getName(),
                // NOTE FOR WHOEVER READS THIS IN A SUPPORT TICKET: club.balance is whatever the
                // device last synced, so this values the club as of its last sync and not live
                // play. Stated here because the figure is paid out as real credit.
                'breakdown' => $clubBreakdown,
            ],
            'totalPaidPence' => $claim->getTotalPaidPence(),
        ];
    }

    /**
     * Release the plot and credit the player.
     *
     * THE CONDITIONAL UPDATE IS THE LOCK. releaseIfActive() only matches a claim whose
     * released_at is still NULL, and the affected-row count is checked rather than a prior read
     * being trusted — so two concurrent sell-backs of one plot cannot both pay out. Returns
     * null when the claim was already released, which is a legitimate race outcome and not an
     * error.
     *
     * Not wrapped in one transaction with the ledger write on purpose: append() opens its own
     * transaction and takes a per-user advisory lock, and the release must be committed and
     * visible before the credit is attempted, so a crash leaves a released plot with a missing
     * credit (recoverable, and the idempotency key below makes the retry safe) rather than a
     * credit for a plot still owned.
     */
    public function execute(PlotClaim $claim, ClaimReleaseReason $reason): ?UserLedger
    {
        $quote = $this->quote($claim);
        $now   = new \DateTimeImmutable();

        $payout = $reason->paysOut() ? $quote['payoutPence'] : 0;

        if (!$this->claimRepository->releaseIfActive((string) $claim->getId(), $reason, $payout, $now)) {
            $this->logger->info('Sell-back skipped: claim already released', [
                'claimId' => (string) $claim->getId(),
                'reason'  => $reason->value,
            ]);

            return null;
        }

        $club = $claim->getClub();

        if ($club !== null && $club->getHomeLocation() !== null) {
            // The club no longer plays here. Leaving home_location_id pointing at land the club
            // does not own would make uq_club_name_per_location keep reserving a name at a town
            // that is back on the market.
            $club->setHomeLocation(null);
        }

        // The working, stored before the credit so a crash leaves the arithmetic on record even
        // if the money did not land.
        $this->em->persist(new PlotClaimValuation($claim, $quote, self::MODEL_VERSION));
        $this->em->flush();

        if ($payout <= 0) {
            return null;
        }

        // IDEMPOTENT on (plot_claim_sell_back, claimId, SELL_BACK_CREDIT). A retry after a
        // crash between the release and the credit pays once, not twice.
        $entry = $this->ledgerService->append(
            $claim->getUser(),
            // Attributed to no club: the club is being liquidated, and the credit is an
            // ACCOUNT-level fact that must outlive it.
            null,
            UserLedgerEntryType::SELL_BACK_CREDIT,
            $payout,
            'plot_claim_sell_back',
            (string) $claim->getId(),
            sprintf('%s — %s', $reason->label(), $claim->getLocation()->getTown()),
        );

        $this->logger->info('Plot released and credited', [
            'claimId'     => (string) $claim->getId(),
            'reason'      => $reason->value,
            'payoutPence' => $payout,
        ]);

        return $entry;
    }

    /**
     * Undo a release, for support.
     *
     * Writes a REVERSAL entry with the exact negation and never edits or deletes the original —
     * a ledger whose history can be rewritten cannot be used to answer a question about money.
     * The claim is reinstated by a conditional raw UPDATE, so if someone has bought the plot in
     * the meantime the partial unique refuses it as a catchable error rather than a 500 with a
     * closed EntityManager.
     */
    public function reverse(PlotClaim $claim, string $reason): void
    {
        $valuation = $this->valuationRepository->findForClaim($claim);
        $credited  = $claim->getSellBackPence() ?? 0;

        $reversalEntry = null;

        if ($credited > 0) {
            $reversalEntry = $this->ledgerService->append(
                $claim->getUser(),
                null,
                UserLedgerEntryType::REVERSAL,
                -$credited,
                'plot_claim_sell_back_reversal',
                (string) $claim->getId(),
                sprintf('Reversal: %s', $reason),
            );
        }

        $this->em->getConnection()->executeStatement(
            'UPDATE plot_claim
                SET released_at = NULL, release_reason = NULL, sell_back_pence = NULL, updated_at = :now
              WHERE id = :id AND released_at IS NOT NULL',
            ['now' => new \DateTimeImmutable(), 'id' => (string) $claim->getId()],
            ['now' => 'datetime_immutable'],
        );

        $valuation?->markReversed($reason, $reversalEntry);

        $this->em->flush();
    }
}
