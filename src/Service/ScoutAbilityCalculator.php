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
 * `experience` is TIER-BANDED: `ScoutGenerationService::build()` draws it from
 * `StaffAbilityRangeService::resolve($country, $tier)` — the authored per-country, per-tier STAFF
 * table a coach is drawn from — whenever a country and tier are passed, which every per-club
 * caller does. (⚠️ **Corrected 2026-11-21, A32.** This used to say "the club's own
 * `league_ability_ranges` window", which was the PLAYER table and a POTENTIAL figure; A25 moved
 * it to the staff table on 2026-11-20 and removed the parameter so no caller can put the player
 * band back.) `judgements` are NOT banded: the same method rolls **all seven** from the flat
 * global `PoolConfig::$scoutJudgementMin/Max` (15-90 on dev, measured 2026-11-21), with no band
 * in sight, whatever the club's tier.
 *
 * 🔴 **A32, 2026-11-21 — THE RISK THIS DOCBLOCK PREDICTED HAS HAPPENED, AND IT IS MEASURED.**
 * `0.3 x mean(judgements)` is a FLAT ~15.75 points on every scout at every tier, and the banded
 * term cannot reach it. On the LIVE EN staff band: t1 is `0.7x42 = 29.40` banded + 15.75 flat =
 * 45.15 against a coach's 42 — the flat term is 34.9% of the figure and the two roles stay
 * comparable. t8 is `0.7x3 = 2.10` + 15.75 = **17.85 against a coach's 3**, and the flat term is
 * 88.2% of it. Priced through `tierStaffWeeklyWagePence` (`ability^k`, k = 2.5 live, times the
 * band ratio) that 5.95x ability gap becomes **65.6x in money** — reproduced to the penny on the
 * owner's own fresh EN t8 save: 12 coaches on GBP 1,141.14/wk beside one ability-19 scout on
 * GBP 9,740.57/wk, 89.5% of the bill. Across the English pyramid the scout/coach WAGE ratio runs
 * 1.19x at t1 and 117.58x at t8 at the band midpoints.
 * ⚠️ **The 70/30 ruling below is NOT the defect and is not to be "tidied" away by a later
 * reader.** It was sound for what it decided, and this docblock said so in advance: *"0.7/0.3
 * gives 61 vs 19"* — **tier 8 landing at 19 was predicted and accepted.** What changed is that
 * A24 then priced the wage off `ability` through a power law, so an accepted compression of the
 * ability ladder became a distortion of the WAGE ladder. Every candidate fix is an owner ruling;
 * the measurements, the four levers and the curve of the outcome against the judgement weight are
 * in `wunderkind-docs/economy-loose-ends/a32-scout-ability-floor-report.md`. **Unruled as of
 * 2026-11-21 at `3ff5a312`; nothing here has been changed.**
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
 *   - `ScoutGenerationService::build()` wrote **5** keys (potential, technical, physical,
 *     mental, personality). It is the only path that has ever written a persisted Scout — the
 *     shared pool and club/world-pack scouts both go through it.
 *   - `GenerateMarketDataCommand::buildScoutJudgements()` wrote **7**, adding `youth` and
 *     `senior`, which is where the app's 7-key `ScoutJudgements` type came from.
 *
 * ⚠️ **BOTH POPULATIONS NOW CARRY SEVEN, AND THIS PASSAGE SAID FIVE UNTIL 2026-11-21 (A32).**
 * A27 added `youth` and `senior` to `ScoutGenerationService` on 2026-11-20 and backfilled the
 * existing rows (`Version20261120120000`), which is what turned `ageGateFor()` on. **Measured on
 * the dev database, 2026-11-21: all 5,278 scouts carry exactly seven keys, none carries five**
 * (`SELECT cardinality(...) , count(*) FROM scout GROUP BY 1` returns the single row `7|5278`).
 * The stale sentence mattered because it is the evidence this class cites for averaging over the
 * keys PRESENT — the rule is right, the population it named had moved.
 *
 * So the mean is taken over the keys the row ACTUALLY HAS, which is what makes this survive a
 * key-set change like A27's at all. A hardcoded `/7` would have divided five stored values by
 * seven and under-rated every pre-A27 scout by about 29% — silently, since the result is still a
 * plausible-looking number — and a hardcoded `/5` would now over-rate every scout by 40%.
 * Non-numeric values are skipped rather than coerced, because a `null` counted as 0 is the same
 * bug in miniature.
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
