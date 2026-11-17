<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\League;
use App\Repository\GameConfigRepository;

/**
 * What a division pays its champion and its placings, scaled for the country it is in.
 *
 * ── WHY THIS RESOLVER EXISTS ──────────────────────────────────────────────────────────────
 *
 * `League::$prizeMoney` and `League::$leaguePositionPot` were the last two money figures in the
 * league economy that the overhaul never re-authored, and the only two with no resolver at all:
 * five publish sites read the column directly. Measured against the turnover ladder authored on
 * dev 2026-11-13, the EN ladder INVERTED at tier 3 —
 *
 *   tier:            1       2       3       4       5       6        7        8
 *   prize/turnover   0.26x   0.62x   1.41x   1.53x   3.61x   6.41x   17.16x   30.33x
 *   pot/turnover     0.26x   0.67x   1.41x   1.51x   1.41x   3.72x    8.58x   12.13x
 *
 * — while TV ran monotone the other way (0.350x down to 0.030x). A tier-8 champion was paid
 * £5,000,000 against £164,852 of authored annual turnover, so a bottom-tier club had more
 * financial reason to win its division than to be promoted out of it, and the division as a
 * whole paid out 5.42x its combined authored turnover.
 *
 * ── WHAT THE TWO FIGURES ACTUALLY MEAN ────────────────────────────────────────────────────
 *
 * Both are PER-CLUB figures that read like pools, which is most of how the inversion survived
 * review. `tvDeal` has `tvPoolPence` beside it and `tvPoolPence / slots === tvDeal` holds, so it
 * is unambiguous. These two have no such pair and no divisor anywhere in the app:
 *
 *  - `prizeMoney` goes WHOLE to the champion, and to nobody else;
 *  - `leaguePositionPot` is the FIRST-PLACE amount, and a club finishing position p is paid
 *    `pot x (1 - decreasePercent/100 x (p - 1))`, floored at 0. At the authored 10% taper that
 *    pays the top ten places and the division as a whole is paid 5.5x the pot, for any league
 *    of 11 clubs or more.
 *
 * A champion receives the prize AND the first-place pot. That is locked design, kept.
 *
 * ── RESOLUTION ORDER, AND WHY NULL IS THE INTERESTING CASE ────────────────────────────────
 *
 *  1. the league's own column when it is NOT NULL — an OVERRIDE, used verbatim, no ratio and
 *     no country factor, so a division authored by hand keeps exactly the figure it was given;
 *  2. the tier-1 base (`GameConfig::$economyLeaguePrizeTier1Pence` /
 *     `$economyLeaguePositionPotTier1Pence`) x this tier's ratio x the country factor;
 *  3. null, meaning "unauthored" — which the publish sites already render as `—` rather than £0.
 *
 * NULL MEANS INHERIT, and that is why the release includes a one-time normalisation nulling the
 * columns on every league still holding a cascaded tier default. Measured before writing this:
 * all 24 eight-tier countries held a BYTE-IDENTICAL prize/pot ladder and SC held rows 1-4 of the
 * same one, so there was not a single genuine per-league override anywhere in the world. Had the
 * column simply won whenever it was populated, this ratio table would have been dead on arrival
 * for all 196 leagues and an operator would have had to re-author 392 figures by hand — the
 * exact failure the sponsor/ticket ladder was written to stop.
 *
 * NULL = inherit is also already this entity's convention for the two sibling fields:
 * `League::$tvPoolPence` and `League::$leaguePositionDecreasePercent` both read "NULL = use
 * GameConfig".
 *
 * ── THE COUNTRY FACTOR, ONCE AND LAST ─────────────────────────────────────────────────────
 *
 * As `SponsorBandResolver::bandFor()` does, and never through `EconomyScaleResolver::scalePence()`
 * or `resolve()->combinedFactorBp`, both of which already contain the country factor and would
 * square it. `economyTierFactorsBp` is deliberately absent: tier variation is this ratio table's
 * whole job, and applying the wage tier factor on top would re-tier an already-tiered ladder —
 * the same argument `SponsorBandResolverTest::testTheTierFactorIsDeliberatelyNOTApplied()` pins.
 *
 * Every `country_profile.economy_factor_bp` is at parity today, so the factor is inert on day
 * one. It is nonetheless the first country variation these two figures have ever had: the
 * columns were cascaded identically to every country, so "an operator authors them per country"
 * described something nobody had done.
 *
 * ── PARTS PER MILLION, AND AN int64 TRAP THE SIBLING RESOLVERS DO NOT HAVE ────────────────
 *
 * The sponsor and ticket resolvers end in ONE `intdiv` over `BP * BP`. That cannot be copied
 * here. With a ppm ratio and a £100,000,000 tier-1 base the numerator would be
 * `1e10 x 1e6 x 1e4 = 1e20`, against an int64 ceiling of 9.223e18 — PHP does not raise, it
 * silently becomes a float and loses pence. Sponsors never meet it because their bands are
 * around 1e7 pence.
 *
 * Combining the two dimensionless factors first (`intdiv(ratio * factor, BP)`) avoids the
 * overflow but is WRONG, and was written that way once: at tier 8 a ratio of 424 ppm against a
 * 20% country truncates to 84 ppm instead of 84.8, which is a 0.94% error on the figure — and
 * the error grows as the ladder steepens, exactly where it matters most.
 *
 * So the division is done by SPLITTING THE MONEY, which is exact. Let `D = PPM * BP`. The
 * answer wanted is `floor(base * ratio * factor / D)`. Take `n = base * ratio` (at most 1e16,
 * safe), then split it as `n = a*D + b` with `a = intdiv(n, D)` and `b = n % D`. Then
 *
 *     base * ratio * factor / D  ==  a * factor  +  b * factor / D
 *
 * where `a * factor` is at most 1e10 and `b * factor` at most 1e14 — both comfortably inside
 * int64 — and only the second term needs truncating. ONE truncation, on the exact quantity,
 * with no intermediate ever exceeding 1e16. That preserves the "one division per money figure"
 * invariant the sibling resolvers are built on: a figure that loses a penny per step disagrees
 * with itself when resolved in a different order.
 *
 * ── BASE-YEAR, LIKE EVERYTHING ELSE ───────────────────────────────────────────────────────
 *
 * Resolved figures are base-year pence. The app compounds `inflationRatePerSeasonBp` itself and
 * must apply NO country factor of its own.
 */
final class LeaguePayoutResolver
{
    private const BP  = 10_000;
    private const PPM = 1_000_000;

    public function __construct(
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly EconomyScaleResolver $scaleResolver,
    ) {}

    /**
     * The resolved base-year champion's prize in pence, or null when nothing is authored.
     *
     * Null is not £0: the publish sites render it as `—`, and a division that pays its champion
     * nothing is a different statement from a division nobody has priced.
     */
    public function prizeFor(League $league, ?GameConfig $config = null): ?int
    {
        $explicit = $league->getPrizeMoney();

        if ($explicit !== null) {
            return $explicit;
        }

        $config = $config ?? $this->gameConfigRepository->getConfig();

        return $this->resolve(
            $config->getEconomyLeaguePrizeTier1Pence(),
            $config->getEconomyPrizeTierRatioPpm(),
            $league->getTier(),
            $league->getCountry(),
        );
    }

    /**
     * The resolved base-year FIRST-PLACE position amount in pence, or null when nothing is
     * authored. Every lower placing is this figure tapered — see the class docblock.
     */
    public function positionPotFor(League $league, ?GameConfig $config = null): ?int
    {
        $explicit = $league->getLeaguePositionPot();

        if ($explicit !== null) {
            return $explicit;
        }

        $config = $config ?? $this->gameConfigRepository->getConfig();

        return $this->resolve(
            $config->getEconomyLeaguePositionPotTier1Pence(),
            $config->getEconomyPositionPotTierRatioPpm(),
            $league->getTier(),
            $league->getCountry(),
        );
    }

    /**
     * Both figures for one tier of one country, without a `League` row — for the admin preview
     * and the gate, which price a tier that may have no league in the country being previewed.
     *
     * This is the INHERITED figure only. It deliberately cannot see a per-league override,
     * because there is no league to ask; the preview labels its own rows accordingly.
     *
     * @return array{prize:?int, positionPot:?int}
     */
    public function inheritedFor(int $tier, string $country = '', ?GameConfig $config = null): array
    {
        $config = $config ?? $this->gameConfigRepository->getConfig();

        return [
            'prize' => $this->resolve(
                $config->getEconomyLeaguePrizeTier1Pence(),
                $config->getEconomyPrizeTierRatioPpm(),
                $tier,
                $country,
            ),
            'positionPot' => $this->resolve(
                $config->getEconomyLeaguePositionPotTier1Pence(),
                $config->getEconomyPositionPotTierRatioPpm(),
                $tier,
                $country,
            ),
        ];
    }

    /**
     * The sum of the taper multipliers a division pays out, in units of the first-place pot.
     *
     * `pot x (1 - decreasePercent/100 x (p - 1))`, floored at 0, summed over the division's
     * clubs. At the authored 10% taper this is 5.5 for any league of 11 clubs or more, which is
     * why it must be COMPUTED rather than assumed: a 10-club league at 15% is a different sum,
     * and the preview's league-total column would otherwise be wrong for four of the countries
     * in the world.
     *
     * Returned scaled by PPM so the caller stays in integers.
     */
    public function taperSumPpm(int $clubs, int $decreasePercent): int
    {
        $clubs    = max(0, $clubs);
        $decrease = max(0, min(100, $decreasePercent));
        $sum      = 0;

        for ($position = 1; $position <= $clubs; $position++) {
            $multiplierPpm = self::PPM - intdiv($decrease * (int) ($position - 1) * self::PPM, 100);

            if ($multiplierPpm <= 0) {
                // FLOORED AT 0, NOT NEGATIVE. The app floors each club's payment at 0, so a
                // taper steep enough to go negative pays the rest of the division nothing
                // rather than clawing money back.
                break;
            }

            $sum += $multiplierPpm;
        }

        return $sum;
    }

    /**
     * @param list<int> $ratioPpm
     */
    private function resolve(int $basePence, array $ratioPpm, int $tier, string $country): ?int
    {
        // TIER OUTSIDE THE TABLE RESOLVES TO UNAUTHORED, as in `TicketPriceResolver::priceFor()`
        // and `SponsorBandResolver::bandFor()` — never to tier 1's figure, and never by reading
        // past the end of the table.
        if ($tier < 1 || $tier > 8) {
            return null;
        }

        $ratio = $ratioPpm[$tier - 1] ?? self::PPM;

        // NULL/0 IN, NULL OUT, BEFORE ANY ARITHMETIC. The same guarantee `TicketPriceResolver`
        // carries for its zero: it is what keeps "unauthored" readable through a country factor
        // that would otherwise floor the figure to 1p below.
        if ($basePence <= 0 || $ratio <= 0) {
            return null;
        }

        $countryFactorBp = $country === '' ? self::BP : $this->scaleResolver->countryFactorBp($country);

        if ($countryFactorBp <= 0) {
            return null;
        }

        // A BASE SO LARGE THAT `base * ratio` ITSELF OVERFLOWS is not a figure anybody can have
        // meant — PHP_INT_MAX / PPM is about £92 billion at tier-1 parity — and the admin takes
        // free text. Refusing it reads as "unauthored", which the preview and the gate both
        // report, rather than silently resolving through a float.
        if ($basePence > intdiv(PHP_INT_MAX, self::PPM)) {
            return null;
        }

        // ONE TRUNCATION, ON THE EXACT QUANTITY, WITHOUT FORMING base x ratio x factor. See the
        // class docblock: that product is 1e20 at tier-1 parity and does not fit in int64, while
        // pre-combining the two dimensionless factors loses 0.94% at tier 8 against a 20%
        // country. Splitting the money is exact and overflows nothing.
        $divisor   = self::PPM * self::BP;
        $n         = $basePence * $ratio;
        $whole     = intdiv($n, $divisor);
        $remainder = $n % $divisor;

        $resolved = $whole * $countryFactorBp
            + intdiv($remainder * $countryFactorBp, $divisor);

        // A REAL FIGURE NEVER TRUNCATES TO "UNAUTHORED". A cheap country x a steep bottom-tier
        // ratio can land under a penny, and a null there would not read as "very small", it
        // would read as "nobody priced this division".
        return max(1, $resolved);
    }
}
