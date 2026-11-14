<?php

declare(strict_types=1);

namespace App\Service\Club;

use App\Entity\Club;
use App\Repository\Location\PlotClaimRepository;
use App\Service\Location\ClubValuationService;
use App\Service\Location\TownNameMatcher;

/**
 * What we offer a player for their club when an admin buys it out.
 *
 * WHY THIS IS NOT ClubValuationService. That one answers "what is this club worth", for a plot
 * release, and answers it conservatively: balance plus sunk facility investment, squad and
 * reputation deliberately excluded because neither is priced anywhere in this codebase. This one
 * answers a different question — "what do we PAY to thank someone for the club they built, ahead
 * of switching to the paid-plot model" — and the honest answer to that is an over-estimate. The
 * two must not be merged: a sell-back that paid this figure would make churning plots profitable.
 *
 *     base      = balance + facilities + land + stadium
 *     repFactor = min(REPUTATION_UPLIFT_CAP, reputation x REPUTATION_UPLIFT_PER_POINT)
 *     estimate  = round(base x (1 + repFactor) x GENEROSITY_MULTIPLIER)
 *
 * REPUTATION IS AN UPLIFT, NOT A PRICE PER POINT, and that is the one modelling decision here.
 * Nothing in this codebase prices a reputation point in money — ClubValuationService says so
 * explicitly and excludes it. A flat pence-per-point would therefore be an invented constant
 * applied to an unbounded `bigint`, worth the same to a tier-8 side with a big number as to a
 * tier-1 one. As a share of what the club otherwise holds it scales with the club, which is the
 * property we actually want, and the cap stops an outlier reputation dominating a figure that is
 * supposed to be grounded in assets.
 *
 * THE ADMIN'S FIGURE OVERRIDES THIS, ALWAYS. Everything here is an opening offer written onto a
 * ClubSale row that an operator then edits. So the formula does not need to be right, it needs to
 * be EXPLAINABLE — which is why breakdown() returns the whole working and why the overlay shows
 * it line by line. A figure an operator cannot account for is one they cannot defend in a support
 * ticket six months later.
 *
 * VERSIONED, like ClubValuationService and StadiumEstimateService, because a payout computed
 * under one formula has to stay explainable after the formula changes. The working is stored
 * verbatim on `club_sale.working_json`, so retuning is a version bump rather than archaeology.
 *
 * TWO FIGURES IN HERE ARE DEVICE-REPORTED AND STALE BY DESIGN. SyncService::process() assigns
 * `club.balance` and `club.reputation` absolutely from the device on every sync, so this values
 * the club as of its LAST SYNC and not live play. Say so wherever the figure is shown; a player
 * who earned after their last sync and then went quiet is valued on the synced numbers. There is
 * no server-side authority to correct them from.
 */
final class ClubSaleValuationService
{
    /** Bump on ANY change to the formula or the coefficients below. */
    public const MODEL_VERSION = 'clubsale-v1';

    /**
     * Uplift per reputation point, as a fraction of the asset base.
     *
     * 0.0002 puts 1,000 reputation at +20% and 2,500 at the cap. Chosen against the live spread
     * rather than derived: reputation has no monetary anchor anywhere (see the class docblock),
     * so any figure here is authored, and an authored figure should at least be a round one an
     * operator can do in their head.
     */
    public const REPUTATION_UPLIFT_PER_POINT = 0.0002;

    /**
     * Ceiling on the reputation term.
     *
     * `club.reputation` is an unsigned bigint with no clamp in the entity and is device-reported,
     * so it is one client bug away from being enormous. Without a cap that bug becomes a payout.
     */
    public const REPUTATION_UPLIFT_CAP = 0.50;

    /**
     * The "weighted in favour of the player" term, applied last.
     *
     * Separate from the components on purpose, so the generosity is one number an operator can
     * see and change rather than being baked into each line. Deliberately NOT applied to the
     * reputation factor alone — it multiplies the whole figure, which keeps the arithmetic in
     * breakdown() reproducible by hand.
     */
    public const GENEROSITY_MULTIPLIER = 1.25;

    public function __construct(
        private readonly ClubValuationService $clubValuation,
        private readonly PlotClaimRepository  $claimRepository,
        private readonly TownNameMatcher      $townMatcher,
    ) {}

    /** The headline figure. */
    public function estimatePence(Club $club): int
    {
        return $this->breakdown($club)['estimatePence'];
    }

    /**
     * The whole calculation, as data.
     *
     * Returned rather than applied so it can be stored verbatim as the sale's working — the
     * figure and its derivation are produced by the same call and cannot drift apart. Same
     * contract as ClubValuationService::breakdown(), whose output is nested under `club` here
     * rather than flattened, so a reader can tell which model produced which term.
     *
     * @return array{modelVersion: string, estimatePence: int, basePence: int,
     *     balancePence: int, facilitiesPence: int, landPence: int, stadiumPence: int,
     *     reputation: int, reputationFactor: float, reputationUpliftPence: int,
     *     reputationCapped: bool, generosityMultiplier: float, generosityPence: int,
     *     land: array<string, mixed>, club: array<string, mixed>, stale: list<string>}
     */
    public function breakdown(Club $club): array
    {
        $clubBreakdown = $this->clubValuation->breakdown($club);
        $land          = $this->landComponent($club);

        $balancePence    = (int) $clubBreakdown['balancePence'];
        $facilitiesPence = (int) $clubBreakdown['facilitiesPence'];
        $landPence       = (int) $land['pence'];
        $stadiumPence    = (int) $land['stadiumPence'];

        // Floored at zero before anything is multiplied: `club.balance` is the device's figure
        // and goes negative when a client overspends, and a negative base would turn the
        // generosity multiplier into a larger debt rather than a larger gift.
        $basePence = max(0, $balancePence + $facilitiesPence + $landPence + $stadiumPence);

        $reputation       = max(0, $club->getReputation());
        $reputationFactor = min(
            self::REPUTATION_UPLIFT_CAP,
            $reputation * self::REPUTATION_UPLIFT_PER_POINT,
        );

        $upliftedPence = (int) round($basePence * (1 + $reputationFactor));
        $estimatePence = (int) round($upliftedPence * self::GENEROSITY_MULTIPLIER);

        return [
            'modelVersion'    => self::MODEL_VERSION,
            'estimatePence'   => $estimatePence,
            'basePence'       => $basePence,

            'balancePence'    => $balancePence,
            'facilitiesPence' => $facilitiesPence,
            'landPence'       => $landPence,
            'stadiumPence'    => $stadiumPence,

            'reputation'            => $reputation,
            'reputationFactor'      => $reputationFactor,
            'reputationUpliftPence' => $upliftedPence - $basePence,
            'reputationCapped'      => $reputation * self::REPUTATION_UPLIFT_PER_POINT
                                        > self::REPUTATION_UPLIFT_CAP,

            'generosityMultiplier' => self::GENEROSITY_MULTIPLIER,
            'generosityPence'      => $estimatePence - $upliftedPence,

            'land' => $land,
            // Nested whole, not cherry-picked: the facility rows and the `excluded` note are the
            // audit trail for the single largest term, and that model has its own version.
            'club' => $clubBreakdown,

            // Stated in the data, not only in a docblock, because the overlay renders it and a
            // support reader months later sees this blob and not this file.
            'stale' => [
                'club.balance and club.reputation are device-reported; this values the club as '
                . 'of its last sync, not live play',
            ],
        ];
    }

    /**
     * What the ground under this club is worth, and on what authority.
     *
     * FOUR BASES, IN DESCENDING ORDER OF CONFIDENCE, because a player club's link to a town is
     * not guaranteed to exist. `Club::$homeLocation` is NULLABLE and expected to stay so: every
     * club founded before the land economy has no plot, and the entity's own docblock refuses to
     * backfill one because that would mean inventing a town for a live save. Those clubs are
     * precisely the ones this feature is rewarding, so "no link" is the common case and not an
     * edge one.
     *
     *   active_claim  — the club holds a plot. The price is the plot's, authoritative.
     *   home_location — founded on a plot since released, or linked without a live claim.
     *   name_match    — no link at all; the town is recovered from the name (TownNameMatcher).
     *   unmatched     — nothing. Land contributes 0 and the UI flags it for the operator.
     *
     * LAND AT 100%, STADIUM AT 100% OF WHAT WAS PAID — not PlotSellBackService's 0.9 and 0.5.
     * Those coefficients exist to make churning plots cost something; a buyout is a one-way exit
     * from the old model, so there is no round trip to discourage.
     *
     * @return array{pence: int, stadiumPence: int, basis: string, locationId: ?string,
     *     town: ?string, claimId: ?string, note: ?string}
     */
    private function landComponent(Club $club): array
    {
        $claims = $this->claimRepository->findActiveForClub($club);

        if ($claims !== []) {
            // One active claim per club in practice (a claim is taken when the club is founded),
            // but the repository returns a list and the schema does not forbid more, so take the
            // most valuable rather than whichever came back first — the generous reading, and
            // the only one that cannot quietly under-pay.
            $best = null;

            foreach ($claims as $claim) {
                $pence = $claim->getPlot()->getEffectiveLandPence() ?? $claim->getLandPencePaid();

                if ($best === null || $pence > $best[0]) {
                    $best = [$pence, $claim];
                }
            }

            [$pence, $claim] = $best;

            return [
                'pence'        => (int) $pence,
                'stadiumPence' => $claim->getStadiumPencePaid(),
                'basis'        => 'active_claim',
                'locationId'   => (string) $claim->getLocation()->getId(),
                'town'         => $claim->getLocation()->getTown(),
                'claimId'      => (string) $claim->getId(),
                'note'         => count($claims) > 1
                    ? sprintf('Club holds %d active claims; valued on the highest.', count($claims))
                    : null,
            ];
        }

        $home = $club->getHomeLocation();

        if ($home !== null) {
            return [
                'pence'        => $home->getLandPence() ?? 0,
                'stadiumPence' => 0,
                'basis'        => 'home_location',
                'locationId'   => (string) $home->getId(),
                'town'         => $home->getTown(),
                'claimId'      => null,
                'note'         => $home->getLandPence() === null
                    ? 'Town is linked but has no land valuation yet; land counted as zero.'
                    : null,
            ];
        }

        $town = $this->townMatcher->matchClubName($club->getName(), $club->getCountry());

        if ($town !== null) {
            return [
                'pence'        => (int) ($town['land_pence'] ?? 0),
                'stadiumPence' => 0,
                'basis'        => 'name_match',
                'locationId'   => (string) $town['id'],
                'town'         => (string) $town['town'],
                'claimId'      => null,
                // A player typed this name; the match is evidence, not proof. See
                // TownNameMatcher's closing paragraph.
                'note'         => sprintf(
                    'No plot link. Town "%s" inferred from the club name — check before paying.',
                    (string) $town['town'],
                ),
            ];
        }

        return [
            'pence'        => 0,
            'stadiumPence' => 0,
            'basis'        => 'unmatched',
            'locationId'   => null,
            'town'         => null,
            'claimId'      => null,
            'note'         => 'No plot link and no town matched the club name. Land counted as zero — set the figure by hand.',
        ];
    }
}
