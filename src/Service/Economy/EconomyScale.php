<?php

declare(strict_types=1);

namespace App\Service\Economy;

/**
 * One resolved (country, tier) point of the economy, with its working shown.
 *
 * THE BREAKDOWN IS THE PRODUCT, NOT A DEBUGGING EXTRA. An operator about to turn country
 * scaling on for a country needs to see which factor contributed what before they believe the
 * answer, and the admin preview screen renders exactly these fields. A resolver that returned
 * a single integer would be impossible to sanity-check and so would not get used.
 *
 * Everything here is integer: basis points where it is a factor, pence where it is money.
 * `wageScaleMicroPence` is the one fixed-point value — see EconomyScaleResolver for why it is
 * scaled rather than rounded to pence at this stage.
 */
final readonly class EconomyScale
{
    public function __construct(
        public string $country,
        public int $tier,
        /** This country's factor, already normalised against the baseline country's own. */
        public int $countryFactorBp,
        /** This tier's factor, raw row lookup. */
        public int $tierFactorBp,
        /** countryFactorBp x tierFactorBp, as basis points. The number everything multiplies by. */
        public int $combinedFactorBp,
        /** Baseline-country turnover for this tier, in pence, before the country factor. */
        public int $baselineTurnoverPence,
        /** Turnover for this country and tier, in pence. The scaled figure. */
        public int $turnoverPence,
        /** S_tier in pence x 10^6 per unit of ability^k. Zero when the model cannot be resolved. */
        public int $wageScaleMicroPence,
        /** How deep this country's pyramid is declared to be. */
        public int $tierCount,
        /** False when the turnover table is unauthored for this tier — the turnover model is unusable. */
        public bool $isResolvable,
    ) {}
}
