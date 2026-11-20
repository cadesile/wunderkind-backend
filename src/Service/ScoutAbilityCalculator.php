<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Scout;

/**
 * A scout's single 1-100 ABILITY, derived from the `experience` and `judgements` already stored
 * on the row.
 *
 * WHY THIS EXISTS AT ALL. Owner ruling, 2026-11-20: *"it would help if we added an 'ability'
 * property to scouts, as experience doesn't match 1 to one."* They are right, and the conflation
 * was already live rather than hypothetical — `SeasonBudgetService.ts` priced a scout by feeding
 * `scout.experience` into the staff wage band as though experience were a coach's
 * `coachingAbility`, and two app type comments called `Scout.experience` "raw 0-100 ability from
 * backend". This class is what those three sites were assuming existed.
 *
 * THE BACKEND OWNS IT, DELIBERATELY. `experience` and `judgements` are both already in the world
 * pack, so the app *could* compute this on device and no payload would change. That would create
 * a second source for one quantity with nothing saying which is authoritative — the governing
 * defect of the whole economy workstream. So the figure is computed here, stored on the column,
 * and published; the app reads it and never re-derives it.
 *
 * ── THE FORMULA ──────────────────────────────────────────────────────────────────────────────
 *
 *     ability = clamp(1, 100, round(0.7 x experience + 0.3 x mean(judgements)))
 *
 * **The 70/30 split is the owner's ruling (2026-11-20), not a tuning default, and the reason is
 * BANDING — which is the one thing to understand before touching these weights.**
 *
 * `experience` is TIER-BANDED: `ScoutGenerationService::build()` (lines 51-52) draws it from the
 * club's own `league_ability_ranges` window when one is passed. `judgements` are NOT: lines 72-77
 * of the same method roll all five from the flat global `PoolConfig::$scoutJudgementMin/Max`
 * (15-90 on dev), with no band in sight, whatever the club's tier.
 *
 * So every point of weight given to judgements is a point taken away from tier separation. The
 * failure that protects against is recorded, not hypothetical — `ScoutGenerationService`'s own
 * docblock at lines 35-40 describes a rating-33 scout standing in a tier-8 squad of rating-2-7
 * coaches, taking £2,230/wk of a £4,308 wage bill because `ClubWageScaleService` splits a club's
 * reservation across its staff IN PROPORTION TO ABILITY. Banding the experience is what fixed it.
 * **Because A24 will price a scout's wage off `ability`, that is a live risk rather than a
 * historical note:** a judgement-heavy blend would route around the band and re-open it.
 *
 * Measured on EN club scouts, tier 1 (exp 40-90) against tier 8 (exp 2-7), judgement mean ~52.5
 * at both: 0.3/0.7 gives 56 vs 38 — an 18-point spread; 0.5/0.5 gives 59 vs 29; **0.7/0.3 gives
 * 61 vs 19, a 42-point spread** that tracks the tier ladder the rest of the economy is built on.
 *
 * Consequence worth knowing rather than hiding: the SHARED POOL's experience window is 4-34 on
 * dev, so no pool scout reaches above ~48. That is the config's choice about what the market
 * offers, not a property of this formula — the top of the scale belongs to tier-1 club scouts.
 * Widening it is a `PoolConfig` change.
 *
 * ── THE PROPERTIES THIS MUST KEEP ────────────────────────────────────────────────────────────
 *
 * DETERMINISTIC. Same stored inputs, same ability, every time — nothing is rolled here. That is
 * what lets the migration's one-time backfill, this class, and a freshly generated scout all
 * agree, and it is the same reasoning `ScoutWageCalculator` records for `weeklySalary`.
 *
 * MONOTONIC in both inputs. Better experience or better judgements can never lower ability; both
 * weights are positive and the blend is linear. A wage is computed from this downstream, and a
 * non-monotonic wage would make "hire the better scout" sometimes cheaper, which reads as a bug
 * rather than a bargain.
 *
 * CLAMPED TO 1-100, with 1 as the floor and not 0. The column is unsigned and an admin can type
 * anything into `experience`, so the inputs are clamped before blending as well as after. A zero
 * ability is not a legal value: it would price a scout at the very bottom of the wage band, and
 * the app's pack-is-a-census rule names `?? 0` on an ingested value a defect rather than a
 * default.
 *
 * ── THE JUDGEMENT KEY SET: AVERAGE OVER WHAT IS THERE, NEVER A FIXED DIVISOR ─────────────────
 *
 * The two repos appeared to disagree about how many judgement categories exist, and BOTH were
 * right about different populations:
 *
 *   - `ScoutGenerationService::build()` writes **5** keys (potential, technical, physical,
 *     mental, personality). It is the only path that has ever written a persisted Scout — the
 *     shared pool and club/world-pack scouts both go through it — and all 5,278 scouts in the
 *     dev database carry exactly these five.
 *   - `GenerateMarketDataCommand::buildScoutJudgements()` writes **7**, adding `youth` and
 *     `senior`, which is where the app's 7-key `ScoutJudgements` type came from.
 *
 * So the mean is taken over the keys the row ACTUALLY HAS. A hardcoded `/7` would have divided
 * five stored values by seven and under-rated every real scout by about 29% — silently, since
 * the result is still a plausible-looking number. Non-numeric values are skipped rather than
 * coerced, because a `null` counted as 0 is the same bug in miniature.
 */
final class ScoutAbilityCalculator
{
    /**
     * ⚠️ **AN OWNER DECISION, DATED 2026-11-20, NOT A TUNING DEFAULT — PLEASE DO NOT "TIDY" IT.**
     * Proposed with measured alternatives (50/50 and 30/70) and confirmed at 70/30; the class
     * docblock carries the reasoning. Changing it is a product decision and needs
     * `app:backfill-scout-abilities --force` behind it to re-derive the world.
     *
     * The two weights are stated separately and must sum to 1.0.
     */
    private const WEIGHT_EXPERIENCE = 0.7;
    private const WEIGHT_JUDGEMENTS = 0.3;

    /** Both inputs are documented 0-100 scores; the blend needs a fixed ceiling. */
    private const SCORE_MIN = 1;
    private const SCORE_MAX = 100;

    /**
     * @param array<string, mixed> $judgements
     */
    public function abilityFor(int $experience, array $judgements): int
    {
        $clampedExperience = (int) $this->clamp((float) $experience);
        $values            = $this->numericJudgements($judgements);

        // NO JUDGEMENTS IS NOT ZERO JUDGEMENTS. A row with an empty or wholly non-numeric
        // `judgements` blob carries no information about that half of the blend, so experience
        // becomes the whole estimate rather than being averaged against a fabricated 0 — which
        // would have more than halved the ability of a scout whose data was merely incomplete.
        if ($values === []) {
            return $clampedExperience;
        }

        $count = count($values);
        $sum   = array_sum($values);
        $mean  = $sum / $count;

        // ── EXACT INTEGER PATH, AND IT IS NOT A MICRO-OPTIMISATION ──────────────────────────
        //
        // `0.7` and `0.3` are not representable in binary floating point, so computing
        // `0.7 * e + 0.3 * m` accumulates error, and on inputs whose true result is exactly
        // k.5 the error decides the rounding. `Version20261120100000` does the same arithmetic
        // in Postgres NUMERIC, which is exact decimal — so the two disagreed on 6 of the 5,278
        // scouts in the dev database, every one of them an exact-.5 case (experience 33 against
        // a judgement mean of 38 gives exactly 34.5; SQL rounded to 35, PHP to 34).
        //
        // Six rows is small; the defect is not. It is this workstream's governing defect in
        // miniature — one quantity with two implementations and nothing saying which is right —
        // so it is removed rather than tolerated. Multiplying through by the weights'
        // denominator makes the numerator an exact integer:
        //
        //     ability = round( (7·e·n + 3·Σj) / 10n )
        //
        // and ONE IEEE division of exact integers is correctly rounded, so a true quotient of
        // k.5 arrives as exactly k.5 (every k.5 below 2^52 is representable) and `round()`'s
        // half-away-from-zero then matches Postgres exactly. The error only ever came from the
        // intermediate products, which this path does not form.
        //
        // The integer path needs the judgement values to be integral and the clamp not to bind.
        // Both hold for every scout that exists — judgements are rolled by `random_int` and the
        // generated range is well inside 1-100 — so the float fallback below is for a
        // hand-edited `judgements` blob holding decimals or out-of-range values. Parity with the
        // migration is guaranteed on the integer path and best-effort on the fallback, which is
        // the right way round: the fallback's inputs are already outside what generation makes.
        if ($this->isIntegral($sum) && $mean >= self::SCORE_MIN && $mean <= self::SCORE_MAX) {
            $numerator   = 7 * $clampedExperience * $count + 3 * (int) $sum;
            $denominator = 10 * $count;

            return (int) $this->clamp(round($numerator / $denominator));
        }

        return (int) $this->clamp(round(
            self::WEIGHT_EXPERIENCE * $clampedExperience
            + self::WEIGHT_JUDGEMENTS * $this->clamp($mean),
        ));
    }

    /** Convenience for generation and the backfill, which both hold the entity. */
    public function abilityForScout(Scout $scout): int
    {
        return $this->abilityFor($scout->getExperience(), $scout->getJudgements());
    }

    /**
     * The numeric judgement values present, in order. Empty when the row has none.
     *
     * @param array<string, mixed> $judgements
     * @return list<float>
     */
    private function numericJudgements(array $judgements): array
    {
        $values = [];

        foreach ($judgements as $value) {
            if (is_int($value) || is_float($value)) {
                $values[] = (float) $value;
                continue;
            }

            // Stored as JSON, so a value that round-tripped through a string is still a real
            // judgement; anything genuinely non-numeric is skipped, not counted as 0. The
            // migration's SQL applies the same filter with a numeric regex, so the two agree on
            // which values are in the mean as well as on the arithmetic over them.
            if (is_string($value) && is_numeric($value)) {
                $values[] = (float) $value;
            }
        }

        return $values;
    }

    /** Whether a float holds a whole number, i.e. whether the exact integer path applies. */
    private function isIntegral(float $value): bool
    {
        return $value === floor($value) && abs($value) <= (float) PHP_INT_MAX;
    }

    private function clamp(float $score): float
    {
        return max((float) self::SCORE_MIN, min((float) self::SCORE_MAX, $score));
    }
}
