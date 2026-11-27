<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location\PlotClaim;
use App\Enum\Country;
use App\Repository\GameConfigRepository;
use App\Repository\Location\PlotClaimRepository;

/**
 * The plot rows on the homepage Rich List (Courtney, 2026-11-27): each listed owner's live
 * plots, with the town, what the land is worth today, what selling it back would pay, and
 * when the inactivity rule would take it.
 *
 * NO NEW MATHS HERE, on purpose. The sell-back figure is PlotSellBackService::quote(), the call
 * the sell-back itself makes, and the deadline uses the same clocks as
 * PlotClaimController::present() and PlotClaimRepository's dormancy queries. A second copy of
 * either would let the homepage and the app disagree about the same plot.
 *
 * WHAT IS PUBLIC: town, region, country, the club on the plot and money figures about the
 * LAND. Nothing that identifies the account behind it.
 */
class RichListPlotService
{
    public function __construct(
        private readonly PlotClaimRepository $claimRepository,
        private readonly PlotSellBackService $sellBack,
        private readonly GameConfigRepository $configRepository,
    ) {}

    /**
     * @param  string[] $userIds
     * @return array<string, list<array{town: string, region: string, countryCode: string,
     *     countryLabel: ?string, clubName: ?string, landValuePence: int, sellBackPence: int,
     *     reclaimAtTs: int}>> keyed by user id
     */
    public function forUsers(array $userIds): array
    {
        $claims = $this->claimRepository->findActiveForUserIds($userIds);

        if ($claims === []) {
            return [];
        }

        $reclaimDays = $this->configRepository->getConfig()->getPlotDormancyReclaimDays();

        $byUser = [];
        foreach ($claims as $claim) {
            $byUser[(string) $claim->getUser()->getId()][] = $this->present($claim, $reclaimDays);
        }

        return $byUser;
    }

    /** @return array<string, mixed> */
    private function present(PlotClaim $claim, int $reclaimDays): array
    {
        $location = $claim->getLocation();
        $code     = $location->getCountryCode();
        $club     = $claim->getClub();

        return [
            'town'           => $location->getTown(),
            'region'         => $location->getRegion(),
            'countryCode'    => $code,
            'countryLabel'   => Country::tryFrom($code)?->label(),
            // Null for an owned but empty plot (its club was liquidated and nothing is
            // built yet); the row then counts down to the build deadline, not a sync.
            'clubName'       => $club?->getName(),
            // Today's price, falling back to what was paid for a town that has lost its
            // valuation — the same fallback the sell-back uses.
            'landValuePence' => $claim->getPlot()->getEffectiveLandPence() ?? $claim->getLandPencePaid(),
            'sellBackPence'  => (int) $this->sellBack->quote($claim)['payoutPence'],
            'reclaimAtTs'    => self::reclaimAt($claim, $reclaimDays)->getTimestamp(),
        ];
    }

    /**
     * The same clocks PlotClaimController::present() reports as `reclaimAt`: the club's last
     * sync (or creation, if it never synced) plus the window, or for an empty plot the day it
     * went empty plus the window.
     */
    private static function reclaimAt(PlotClaim $claim, int $days): \DateTimeImmutable
    {
        $club  = $claim->getClub();
        $start = $club === null
            ? $claim->getEmptySince()
            : ($club->getLastSyncedAt() ?? $club->getCreatedAt());

        return $start->modify(sprintf('+%d days', $days));
    }
}
