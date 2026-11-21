<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Scout;
use App\EventSubscriber\PersonalityLifecycleSubscriber;
use App\Repository\PoolConfigRepository;

/**
 * Builds a single Scout entity, detached and unpersisted — the caller decides
 * whether/when to persist it. Extracted from MarketPoolService::generateScouts()
 * so per-club world-pack generation can reuse the exact same generation logic
 * without going through a pool table at all.
 */
class ScoutGenerationService
{
    public function __construct(
        private readonly PoolConfigRepository $poolConfigRepo,
        private readonly NameGeneratorService $nameGenerator,
        private readonly PersonalityLifecycleSubscriber $personalityLifecycle,
        private readonly ScoutWageCalculator $wageCalculator,
        private readonly ScoutAbilityCalculator $abilityCalculator,
        private readonly StaffAbilityRangeService $staffAbilityRanges,
    ) {}

    /**
     * `$country` and `$tier` are the club this scout is being generated for, and passing them
     * is what keeps a scout at the club's own level. The shared pool passes neither.
     *
     * Without a tier, experience comes from a single global range
     * (`PoolConfig::$scoutExperienceMin/Max`, 4-34 on dev) whatever the club — so a tier-8 side
     * got a scout rated 33 standing next to coaches rated 2-7. That is the same defect staff
     * ability had, and scouts were missed when that was fixed because a Scout is its own entity
     * and never passes through `WageModelResolver::staffWeeklyPence()`.
     *
     * **The visible damage was in the app, not in this figure.** `ClubWageScaleService`
     * divides a club's `wageReservationPence` across its staff IN PROPORTION TO ABILITY, so one
     * rating-33 scout among rating-2-7 coaches takes most of the budget: £2,230/wk against
     * coaches on £13-266. Banding the experience is what makes that split sane, and it fixes
     * the wage here too — ⚠️ **indirectly since A24**: `ScoutWageCalculator` interpolates on
     * `ability`, which is 70% banded experience, so banding the experience still moves the wage,
     * one step further removed.
     *
     * ── WHAT A25 CHANGED, AND WHAT IT DELIBERATELY DID NOT (2026-11-20) ─────────────────
     *
     * ⚠️ **THE BANDED INPUT IS NOW THE AUTHORED STAFF TABLE, NOT THE SQUAD'S PLAYER BAND.**
     * `$abilityRange` used to be a parameter, and every per-club caller filled it with the
     * PLAYER band for that country and tier — a POTENTIAL figure, against a scout whose
     * `ability` is a flat 1-100 quantity with no development curve. It now comes from
     * `StaffAbilityRangeService::resolve()`, the same per-country/per-tier table a coach is
     * drawn from, so a club's scouts and its coaches are banded off ONE authored source.
     * The parameter is gone rather than defaulted, so no caller can put the player band back.
     *
     * ⚠️ **`PoolConfig::$scoutExperienceMin/Max` (4-34 live) IS UNTOUCHED, by ruling.** It is
     * the UNTIERED window — the shared pool, which has no tier to be banded against — and it
     * is a scouting-gameplay input rather than only a wage input, so A25 was told to leave it
     * exactly as it is.
     *
     * ⚠️ **AND NOTE THE SCALE CONFLATION THAT REMAINS, because it is pre-existing and NOT
     * A25's to fix.** The banded figure lands on `experience`, which is years-of-experience on
     * a 0-100 axis, while the staff table is defined against player `current_ability`. The
     * tiered input was already a different quantity from `experience` before A25 (it was
     * player potential); this change makes the two sides of the staff comparison agree without
     * claiming to have settled what a scout's experience axis means. Recorded as an open item
     * in `wunderkind-docs/economy-loose-ends/a25-ability-bands-report.md`.
     */
    public function build(?string $nationality = null, string $country = '', int $tier = 0): Scout
    {
        $cfg = $this->poolConfigRepo->getConfig();

        if ($country !== '' && $tier > 0) {
            $band  = $this->staffAbilityRanges->resolve($country, $tier);
            $expMin = $band['min'];
            $expMax = $band['max'];
            // A32: the JUDGEMENTS are banded off the same resolved row, stretched by the one
            // authored dial. See `judgementWindow()` for the ruling and the arithmetic.
            [$judMin, $judMax] = self::judgementWindow($band, $cfg->getScoutJudgementBandBp());
        } else {
            // The untiered window, by ruling. Ordered rather than trusted: it is two
            // hand-edited admin fields and a transposed pair is a real state of that row.
            $expMin = $cfg->getScoutExperienceMin();
            $expMax = $cfg->getScoutExperienceMax();
            if ($expMin > $expMax) {
                [$expMin, $expMax] = [$expMax, $expMin];
            }
            // ⚠️ **THE UNTIERED JUDGEMENT WINDOW, BY THE SAME RULING THAT KEPT THE EXPERIENCE
            // ONE.** A pool scout has no tier to be banded against, so the flat global pair is
            // what it draws from — which is why `scoutJudgementMin/Max` is NOT dead config
            // after A32: it is the untiered band. Ordered for the same reason as the pair above.
            $judMin = $cfg->getScoutJudgementMin();
            $judMax = $cfg->getScoutJudgementMax();
            if ($judMin > $judMax) {
                [$judMin, $judMax] = [$judMax, $judMin];
            }
        }

        $age        = random_int($cfg->getScoutAgeMin(), $cfg->getScoutAgeMax());
        $experience = random_int($expMin, $expMax);
        $scoutNat   = $nationality ?? $this->nameGenerator->getRandomNationality();
        $scoutName  = $this->nameGenerator->generateName($scoutNat);

        $scout = new Scout($scoutName);
        $scout->setDob($this->dobFromAge($age));
        $scout->setNationality($scoutNat);
        $scout->setExperience($experience);
        // SEVEN KEYS, AND THIS IS THE AUTHORITATIVE SET. Every persisted Scout in the world comes
        // through here — the shared pool via `MarketPoolService::generateScouts()` and club
        // scouts via `WorldPackClubGenerationService::generateScouts()` — so these seven are what
        // `judgements` means in the database. Anything averaging them must still average over the
        // keys PRESENT, never a fixed divisor: `GenerateMarketDataCommand` has its own 7-key
        // builder for a different population, and a pack cached before v13 carries five.
        //
        // ⚠️ **IT WAS FIVE UNTIL 2026-11-20 (A27), AND THE TWO NEW KEYS FIX A DEAD FEATURE.**
        // Owner ruling, asked whether scouting accuracy should vary by the player's age:
        // **"Yes, it should."** The app's `ageGateFor()` reads `judgements.youth` for under-18s
        // and `judgements.senior` otherwise — keys this service had never written, so it resolved
        // to 0 for every scout that has ever existed. With `AGE_GATE_WEIGHT = 0.3` that made
        // every scout's DISPLAYED accuracy 70% of its designed value and meant the real noise
        // band was never tightened at all. Generating the keys here is what turns the gate on;
        // see `wunderkind-docs/economy-loose-ends/a27-scout-age-gate-report.md`.
        //
        // ⚠️ **DELIBERATELY NOT GIVEN THEIR OWN BOUNDS.** They roll from the same window as the
        // other five, because they ARE judgements on the same 0-100 scale: a separate
        // `scoutAgeGateMin/Max` pair would be a second config surface for one quantity, and
        // `ScoutAbilityCalculator`'s 70/30 weighting was measured against judgements drawn from
        // ONE range — banding two of the seven differently would move the tier spread the owner
        // approved without saying so. **That still holds after A32**: all seven moved together
        // onto the tier's band, so the weighting still sees one window.
        //
        // ⚠️ **ADDING A KEY HERE CHANGES EVERY SCOUT'S `ability`**, because the mean below is
        // taken over keys present. Any future change to this set needs
        // `app:backfill-scout-abilities --force` behind it and a `WORLD_PACK_VERSION` bump,
        // exactly as this one did.
        //
        // ✅ **THE ASYMMETRY THIS COMMENT USED TO DESCRIBE IS GONE (A32, 2026-11-21).** It read:
        // *"unlike `$experience` above, judgements are rolled from the flat global
        // `scoutJudgementMin/Max` whatever the club's tier. That asymmetry is exactly why
        // `ScoutAbilityCalculator` weights experience at 70%."* Both halves of `ability` are now
        // banded off the SAME resolved row for a club scout, so `0.3 x mean(judgements)` is no
        // longer a flat 15.75-point term sitting inside a tier-relative figure. The 70/30
        // weighting is UNTOUCHED and is still the owner's ruling — it simply no longer has a
        // flat term to hold back. ⚠️ The asymmetry remains REAL for a POOL scout, which has no
        // tier and draws both halves from the untiered globals, and that is correct.
        $judgements = [
            'potential'   => random_int($judMin, $judMax),
            'technical'   => random_int($judMin, $judMax),
            'physical'    => random_int($judMin, $judMax),
            'mental'      => random_int($judMin, $judMax),
            'personality' => random_int($judMin, $judMax),
            // The two AGE-BAND keys, split at 18 by `ageGateFor()` — accuracy at reading a youth
            // prospect and at reading an established senior, rolled independently so a scout can
            // genuinely be better at one than the other. That independence is the whole point:
            // deriving either from `potential` would collapse the displayed-accuracy blend
            // `potential x 0.7 + ageGate x 0.3` back to `potential` and change nothing for
            // exactly the under-18s the gate exists for.
            'youth'       => random_int($judMin, $judMax),
            'senior'      => random_int($judMin, $judMax),
        ];

        $scout->setJudgements($judgements);

        // Derived from the experience and judgements just rolled, not rolled separately — see
        // ScoutAbilityCalculator for why generation and the backfill must agree.
        $ability = $this->abilityCalculator->abilityFor($experience, $judgements);
        $scout->setAbility($ability);

        // ⚠️ **THE WAGE IS SET HERE, AFTER THE ABILITY, AND THE ORDER IS LOAD-BEARING SINCE A24
        // (2026-11-20).** It used to sit immediately after `setExperience()` above, because it
        // interpolated on experience. The owner's ruling moved the input to `ability`, which does
        // not exist until the judgements are rolled — so pricing before this line would have
        // silently used the entity's floor of 1 and put every generated scout at the very bottom
        // of the band. See ScoutWageCalculator for why generation and the backfill must agree.
        $scout->setWeeklySalary($this->wageCalculator->weeklyWageFor($ability, $cfg));

        // prePersist won't fire for an entity that's never persisted — roll personality now.
        $this->personalityLifecycle->fill($scout);

        return $scout;
    }

    /**
     * The judgement window for a CLUB scout: the tier's own staff band, stretched by the
     * authored dial. Ordered, clamped to 1-100, and never empty.
     *
     * ── THE RULING (owner, 2026-11-21) ───────────────────────────────────────────────────
     *
     * Asked directly: ***"Lower tier scouts genuinely worse at judging players is the
     * expectation."*** Before A32 all seven categories rolled from the flat global
     * `PoolConfig::$scoutJudgementMin/Max` (15-90 live) whatever the tier, so a tier-8 scout
     * judged players exactly as well as a tier-1 one.
     *
     * ── WHY THE BAND IS THE STAFF TABLE AND NOT A TABLE OF ITS OWN ───────────────────────
     *
     * `$band` is whatever `StaffAbilityRangeService::resolve($country, $tier)` returned for
     * this club — **the same authored row the `$experience` above and a coach's
     * `coachingAbility` both come from.** Banding off it keeps ONE authored source for
     * "how good is a person at this tier", and makes `ability`
     * (`0.7 x experience + 0.3 x mean(judgements)`) tier-consistent with a coach's by
     * construction rather than by coincidence. A per-country, per-tier judgement grid was
     * considered and rejected: it is a second surface for one quantity, which is the defect
     * class this workstream exists to remove, and it would need 26 x 8 new figures authored
     * before anything could ship.
     *
     * ── THE DIAL, AND WHY IT IS A DIAL ───────────────────────────────────────────────────
     *
     * `PoolConfig::$scoutJudgementBandBp`, admin-editable on Staff Pool Rules, 10000 = x1.00 =
     * exactly the tier's staff band. The owner deferred the NUMBER on purpose, the same day:
     * *we can worry about the bands after we've tested that the process of matching the scaling
     * to the tier is correctly implemented, because we can then view the raw data against the
     * scouts' reviews and make a judgment call.* So the shipped 10000 is a starting point, and
     * tuning it is a data edit rather than a release. **Do not replace it with a literal.**
     *
     * ⚠️ **Measured at the TOP of the pyramid, because the ruling only spoke about the
     * bottom:** at 10000 an EN tier-1 scout's judgement mean is 42 against today's 52.5, so
     * displayed accuracy reads 42% rather than 53%. 12500 holds tier 1's mean at exactly 52.5
     * while still banding every tier below it (52.5 / 42, derived not chosen). The curve at
     * both ends is in
     * `wunderkind-docs/economy-loose-ends/a32-scout-ability-floor-report.md`.
     *
     * ── THE FLOOR IS 1 AND IT IS LOAD-BEARING ────────────────────────────────────────────
     *
     * A deep pyramid's bottom band can be 2-4, and a dial below x1 can take that under 1. A
     * judgement of 0 is not a legal value on a 1-100 score and `scoutNoise.ts`'s
     * `clamp01(accuracy / 100)` would read it as a perfectly blind scout — so the window is
     * clamped into 1-100, exactly as `ScoutAbilityCalculator` clamps the figures it blends.
     * The two bounds are also re-ordered after scaling, because a band stored transposed is a
     * real state of a hand-edited admin grid and `resolve()` only normalises what it read.
     *
     * @param array{min: int, max: int, source: string} $band
     * @return array{0: int, 1: int}
     */
    private static function judgementWindow(array $band, int $bandBp): array
    {
        $scale = max(1, $bandBp) / 10000;

        $min = (int) max(1, min(100, round($band['min'] * $scale)));
        $max = (int) max(1, min(100, round($band['max'] * $scale)));

        return $min <= $max ? [$min, $max] : [$max, $min];
    }

    private function dobFromAge(int $age): \DateTimeImmutable
    {
        $year  = (int) date('Y') - $age;
        $month = random_int(1, 12);
        $day   = random_int(1, 28);
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
