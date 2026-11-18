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
    ) {}

    /**
     * `$abilityRange` is the club's tier band, and passing it is what keeps a scout at the
     * club's own level.
     *
     * Without it, experience comes from a single global range
     * (`PoolConfig::$scoutExperienceMin/Max`, 4-34 on dev) whatever the club — so a tier-8 side
     * got a scout rated 33 standing next to coaches rated 2-7. That is the same defect staff
     * ability had, and scouts were missed when that was fixed because a Scout is its own entity
     * and never passes through `WageModelResolver::staffWeeklyPence()`.
     *
     * **The visible damage was in the app, not in this figure.** `ClubWageScaleService`
     * divides a club's `wageReservationPence` across its staff IN PROPORTION TO ABILITY, so one
     * rating-33 scout among rating-2-7 coaches takes most of the budget: £2,230/wk against
     * coaches on £13-266. Banding the experience is what makes that split sane, and it fixes
     * the wage here too, since `ScoutWageCalculator` interpolates on the same number.
     *
     * Absent, the global range still applies, which is right for the shared pool: a market
     * scout is one row served to every country and has no tier to be banded against.
     *
     * @param array{min: int, max: int}|null $abilityRange
     */
    public function build(?string $nationality = null, ?array $abilityRange = null): Scout
    {
        $cfg = $this->poolConfigRepo->getConfig();

        $expMin = $abilityRange !== null ? (int) $abilityRange['min'] : $cfg->getScoutExperienceMin();
        $expMax = $abilityRange !== null ? (int) $abilityRange['max'] : $cfg->getScoutExperienceMax();

        // Normalised rather than trusted: `league_ability_ranges` is a hand-editable admin grid
        // and a transposed pair is a real state of that row.
        if ($expMin > $expMax) {
            [$expMin, $expMax] = [$expMax, $expMin];
        }

        $age        = random_int($cfg->getScoutAgeMin(), $cfg->getScoutAgeMax());
        $experience = random_int($expMin, $expMax);
        $scoutNat   = $nationality ?? $this->nameGenerator->getRandomNationality();
        $scoutName  = $this->nameGenerator->generateName($scoutNat);

        $scout = new Scout($scoutName);
        $scout->setDob($this->dobFromAge($age));
        $scout->setNationality($scoutNat);
        $scout->setExperience($experience);
        // Derived from the experience just rolled, not rolled separately — see
        // ScoutWageCalculator for why generation and the backfill must agree.
        $scout->setWeeklySalary($this->wageCalculator->weeklyWageFor($experience, $cfg));
        $scout->setJudgements([
            'potential'   => random_int($cfg->getScoutJudgementMin(), $cfg->getScoutJudgementMax()),
            'technical'   => random_int($cfg->getScoutJudgementMin(), $cfg->getScoutJudgementMax()),
            'physical'    => random_int($cfg->getScoutJudgementMin(), $cfg->getScoutJudgementMax()),
            'mental'      => random_int($cfg->getScoutJudgementMin(), $cfg->getScoutJudgementMax()),
            'personality' => random_int($cfg->getScoutJudgementMin(), $cfg->getScoutJudgementMax()),
        ]);

        // prePersist won't fire for an entity that's never persisted — roll personality now.
        $this->personalityLifecycle->fill($scout);

        return $scout;
    }

    private function dobFromAge(int $age): \DateTimeImmutable
    {
        $year  = (int) date('Y') - $age;
        $month = random_int(1, 12);
        $day   = random_int(1, 28);
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
