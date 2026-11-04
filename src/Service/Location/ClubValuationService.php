<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Club;
use App\Entity\ClubFacility;
use App\Repository\FacilityTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a club is worth, for the purpose of paying a player out when its plot is released.
 *
 * WHY THIS EXISTS. Selling a plot back, or having it reclaimed after 30 days of inactivity,
 * liquidates the club standing on it — so the payout has to include the club, not just the
 * land. Nothing in this codebase had any notion of a club's worth before this: no
 * `clubValue`, no `valuation`, no market value anywhere. It had to be defined, and this is
 * the definition.
 *
 *     clubValuePence = club.balance
 *                    + SUM over each facility: baseCost x L(L+1)/2
 *
 * Both terms are reproducible from server-side persisted data, which is the whole constraint:
 * a figure that pays out real credit must be recomputable by support months later, so it may
 * not depend on anything the server does not store.
 *
 * THE SQUAD IS DELIBERATELY EXCLUDED, and this is the part most likely to be "fixed" by
 * someone later. `Player::$contractValue` reads like an asset value and is not one — every
 * writer sets it from a WAGE (`$baseWage * $multiplier`, see WorldPackClubGenerationService
 * and MarketPoolService). There is no transfer or market value on Player at all, so any squad
 * term would be invented rather than derived. If squad value should count, a real market-value
 * field has to exist first. Summing contractValue here would be summing wages and calling them
 * assets.
 *
 * Club::$totalCareerEarnings is also excluded: it is a client-supplied accumulator that is
 * being phased out (see LeaderboardCategory::CAREER_EARNINGS and ClubCrudController), and it
 * counts income the club has already spent.
 *
 * CLUB.BALANCE IS DEVICE-REPORTED. SyncService::process() assigns it absolutely from the
 * device, so this valuation reflects the club as of its LAST SYNC, not live play. That is the
 * honest best available and must be stated wherever a payout is shown — a player who earned
 * money after their last sync and then went dormant is paid on the synced figure.
 *
 * VERSIONED, like StadiumEstimateService, because a payout computed under one formula has to
 * stay explainable after the formula changes. The working is written into
 * plot_claim_valuation.working_json at payout time, so retuning is a version bump rather than
 * an archaeology exercise.
 */
final class ClubValuationService
{
    /** Bump on ANY change to the formula or the terms below. */
    public const MODEL_VERSION = 'club-v1';

    public function __construct(
        private readonly FacilityTemplateRepository $templateRepository,
        private readonly EntityManagerInterface     $em,
    ) {}

    /** The headline figure. */
    public function valuePence(Club $club): int
    {
        return $this->breakdown($club)['pence'];
    }

    /**
     * The whole calculation, as data.
     *
     * Returned rather than applied so it can be stored verbatim as the payout's working — the
     * figure and its derivation are produced by the same call and cannot drift apart.
     *
     * @return array{modelVersion: string, pence: int, balancePence: int,
     *     facilitiesPence: int, facilities: list<array{slug: string, level: int,
     *     baseCost: int, investedPence: int}>, excluded: list<string>}
     */
    public function breakdown(Club $club): array
    {
        // Cast on read: balance is a plain int column, but it is the device's figure and may be
        // negative if the client overspent, so the total is floored at zero rather than paying
        // a negative valuation into a credit.
        $balancePence = $club->getBalance();

        $facilities      = $this->facilityInvestment($club);
        $facilitiesPence = array_sum(array_column($facilities, 'investedPence'));

        return [
            'modelVersion'    => self::MODEL_VERSION,
            'pence'           => max(0, $balancePence + $facilitiesPence),
            'balancePence'    => $balancePence,
            'facilitiesPence' => $facilitiesPence,
            'facilities'      => $facilities,
            // Recorded so a later reader knows these were considered and rejected, rather than
            // overlooked — see the class docblock.
            'excluded'        => ['squad (contractValue is a wage, not a market value)', 'totalCareerEarnings (phased out)'],
        ];
    }

    /**
     * Sunk investment in this club's facilities.
     *
     * EXACTLY REPRODUCIBLE, because FacilityTemplate::$baseCost documents its own cost curve:
     * "Frontend formula: (currentLevel + 1) x baseCost" per upgrade. Reaching level L therefore
     * costs baseCost x (1 + 2 + ... + L) = baseCost x L(L+1)/2. That is arithmetic on stored
     * values, not an estimate.
     *
     * A facility whose template has gone (an admin deleted it) contributes nothing and is
     * skipped rather than guessed at.
     *
     * @return list<array{slug: string, level: int, baseCost: int, investedPence: int}>
     */
    private function facilityInvestment(Club $club): array
    {
        $rows = $this->em->getRepository(ClubFacility::class)->findBy(['club' => $club]);

        if ($rows === []) {
            return [];
        }

        $baseCosts = [];
        foreach ($this->templateRepository->findAllOrderedByCategoryAndSort() as $template) {
            $baseCosts[$template->getSlug()] = $template->getBaseCost();
        }

        $out = [];

        foreach ($rows as $facility) {
            $slug  = $facility->getFacilitySlug();
            $level = $facility->getLevel();

            if ($level <= 0 || !isset($baseCosts[$slug])) {
                continue;
            }

            $baseCost = $baseCosts[$slug];

            $out[] = [
                'slug'          => $slug,
                'level'         => $level,
                'baseCost'      => $baseCost,
                'investedPence' => (int) ($baseCost * $level * ($level + 1) / 2),
            ];
        }

        return $out;
    }
}
