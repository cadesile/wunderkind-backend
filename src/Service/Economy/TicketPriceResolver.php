<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\GameConfig;
use App\Repository\GameConfigRepository;

/**
 * What a ticket costs in a division, scaled for the country the division is in.
 *
 * ── WHY THE BACKEND HOLDS A TICKET PRICE AT ALL ───────────────────────────────────────────
 *
 * It did not, until now: the app shipped a flat 2500p starting price for every club in every
 * division of every country, compiled into the release. With elasticity measuring a club's
 * price against an advised one, that constant is not a cosmetic default — a bottom-tier club
 * starts roughly 39% under its own attendance-maximising price, which is a tuning decision
 * nobody made, taken by a literal in a TypeScript file.
 *
 * So the price per division becomes an operator figure, for the same reason
 * `matchdayFoodUptakePercent` and `npcSolvencyThresholds` are operator figures.
 *
 * ── SAME SHAPE AS `SponsorBandResolver`, ON PURPOSE ───────────────────────────────────────
 *
 * Resolution order is the same three steps, in the same order, for the same reason:
 *
 *  1. the explicit per-tier price (`economyTicketPricePerTierPence`) when it is greater than 0
 *     — an OVERRIDE, used verbatim, so a division authored by hand keeps its figure;
 *  2. the tier-1 base x this tier's ratio;
 *  3. 0, meaning "unauthored — use your own figure".
 *
 * The country factor applies once, last, to whichever of those won. Two resolvers with the
 * same shape can be read side by side; two with different shapes is how the next person
 * concludes one of them is wrong.
 *
 * ── 0 STAYS 0 AT EVERY STEP ───────────────────────────────────────────────────────────────
 *
 * 0 is not a price, it is the absence of one, and it has to survive the whole chain to stay
 * readable as that. A base of 0 resolves to 0 whatever the ratios say; a ratio of 0 resolves
 * that tier to 0 whatever the base says; and a country factor never turns a real price into 0
 * (it floors at 1p once there is a price to scale) nor a 0 into a price. That is what makes
 * the ratio table safe to author before the base figure is agreed — step 6 of this pass does
 * exactly that, and nothing moves until the base lands.
 *
 * ── BASE-YEAR, LIKE EVERYTHING ELSE ───────────────────────────────────────────────────────
 *
 * The resolved figure is base-year pence. The app compounds `inflationRatePerSeasonBp` itself,
 * and must apply NO country factor of its own — this is the resolved, country-scaled price.
 */
final class TicketPriceResolver
{
    private const BP = 10_000;

    public function __construct(
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly EconomyScaleResolver $scaleResolver,
    ) {}

    /**
     * The resolved base-year ticket price in pence for one tier of one country.
     *
     * 0 means the operator has authored nothing for this division and the app should use its
     * own figure. There is no flat predecessor to fall back to — the backend has never held a
     * ticket price — which is why the zero has to carry that meaning rather than "free".
     */
    public function priceFor(int $tier, string $country = '', ?GameConfig $config = null): int
    {
        $config = $config ?? $this->gameConfigRepository->getConfig();

        // TIER 0 MEANS "NO TIER", as in `SponsorBandResolver::bandFor()`. There is no
        // division-less ticket price, so it resolves to unauthored rather than to tier 1's.
        if ($tier < 1 || $tier > 8) {
            return 0;
        }

        $override = $config->getEconomyTicketPricePerTierPence()[$tier - 1] ?? 0;

        if ($override > 0) {
            $basePence = $override;
            $ratioBp   = self::BP;
        } else {
            $basePence = $config->getEconomyTicketPriceTier1Pence();
            $ratioBp   = $config->getEconomyTicketPriceTierRatioBp()[$tier - 1] ?? self::BP;
        }

        // 0 IN, 0 OUT, BEFORE ANY ARITHMETIC. Not an optimisation: it is the one guarantee
        // that makes a published 0 mean "unauthored" everywhere, including at a country factor
        // that would otherwise floor it to 1p below.
        if ($basePence <= 0 || $ratioBp <= 0) {
            return 0;
        }

        $countryFactorBp = $country === '' ? self::BP : $this->scaleResolver->countryFactorBp($country);

        if ($countryFactorBp <= 0) {
            return 0;
        }

        // ONE intdiv, AT THE END OF THE CHAIN, for the reason `SponsorBandResolver` carries:
        // truncating after the ratio and again after the country factor loses a penny twice,
        // and the same price resolved in a different order then disagrees with itself.
        $resolved = intdiv($basePence * $ratioBp * $countryFactorBp, self::BP * self::BP);

        // A REAL PRICE NEVER TRUNCATES TO "UNAUTHORED". A cheap country x a steep lower-tier
        // ratio can land under a penny, and a 0 there would not read as "very cheap", it would
        // read as "nobody authored this division" and hand the app its own 2500p constant
        // back — the most expensive possible reading of the cheapest possible price.
        return max(1, $resolved);
    }

    /**
     * Every tier's resolved price for one country, indexed by tier number (1-based).
     *
     * @return array<int, int>
     */
    public function pricesFor(string $country = '', ?GameConfig $config = null, int $tierCount = 8): array
    {
        $config = $config ?? $this->gameConfigRepository->getConfig();
        $out    = [];

        for ($tier = 1; $tier <= max(0, min(8, $tierCount)); $tier++) {
            $out[$tier] = $this->priceFor($tier, $country, $config);
        }

        return $out;
    }
}
