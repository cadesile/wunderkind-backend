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
    ) {}

    public function build(?string $nationality = null): Scout
    {
        $cfg = $this->poolConfigRepo->getConfig();

        $age        = random_int($cfg->getScoutAgeMin(), $cfg->getScoutAgeMax());
        $experience = random_int($cfg->getScoutExperienceMin(), $cfg->getScoutExperienceMax());
        $scoutNat   = $nationality ?? $this->nameGenerator->getRandomNationality();
        $scoutName  = $this->nameGenerator->generateName($scoutNat);

        $scout = new Scout($scoutName);
        $scout->setDob($this->dobFromAge($age));
        $scout->setNationality($scoutNat);
        $scout->setExperience($experience);
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
