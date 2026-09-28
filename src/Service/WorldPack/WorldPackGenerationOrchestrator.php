<?php

declare(strict_types=1);

namespace App\Service\WorldPack;

use App\Entity\WorldPack\WorldPackGenerationRun;
use App\Entity\WorldPack\WorldPackGenerationTierRun;
use App\Enum\WorldPack\WorldPackGenerationRunStatus;
use App\Enum\WorldPack\WorldPackGenerationTierRunStatus;
use App\Message\WorldPack\WarmWorldPackTierMessage;
use App\Repository\LeagueRepository;
use App\Repository\WorldPack\WorldPackGenerationRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The entry point the admin "Regenerate Country Cache" action calls. Creates the
 * WorldPackGenerationRun + one WorldPackGenerationTierRun per requested tier, then
 * dispatches one WarmWorldPackTierMessage per tier. Single-flight per country is
 * enforced by the run table's own partial unique index — a second call while one is
 * already active throws AlreadyRunningException rather than a raw DB exception.
 */
class WorldPackGenerationOrchestrator
{
    public function __construct(
        private readonly WorldPackGenerationRunRepository $runRepository,
        private readonly LeagueRepository $leagueRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @param int[] $tiers Tiers to regenerate. Tiers with no League for $country are skipped.
     * @throws WorldPackGenerationAlreadyRunningException
     */
    public function startRun(string $country, array $tiers): WorldPackGenerationRun
    {
        // Pre-check rather than catch-after-flush: a failed flush closes the
        // EntityManager (documented repo-wide gotcha — see CLAUDE.md's Testing
        // section), which would make every Doctrine call for the rest of this
        // request throw too, including the ones needed to render a clean error
        // response. The DB's own partial unique index is still the real
        // authority (a genuine two-admins-at-once race still throws — that rare
        // case is allowed to surface as a 500, it's not the path this guards),
        // this just makes the common "already running" case clean.
        $existing = $this->runRepository->findActiveForCountry($country);
        if ($existing !== null) {
            throw new WorldPackGenerationAlreadyRunningException($country, $existing);
        }

        $run = new WorldPackGenerationRun($country, $tiers);
        $this->em->persist($run);
        $this->em->flush();

        $run->setStatus(WorldPackGenerationRunStatus::IN_PROGRESS);
        $run->setStartedAt(new \DateTimeImmutable());

        $tierRuns = [];
        foreach ($tiers as $tier) {
            if ($this->leagueRepository->findByCountryAndTier($country, $tier) === null) {
                continue;
            }
            $tierRun    = new WorldPackGenerationTierRun($run, $country, $tier);
            $tierRuns[] = $tierRun;
            $this->em->persist($tierRun);
        }

        $this->em->flush();

        foreach ($tierRuns as $tierRun) {
            $this->messageBus->dispatch(new WarmWorldPackTierMessage($tierRun->getId()->toRfc4122()));
        }

        return $run;
    }
}
