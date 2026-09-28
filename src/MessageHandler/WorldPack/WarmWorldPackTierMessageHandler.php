<?php

declare(strict_types=1);

namespace App\MessageHandler\WorldPack;

use App\Entity\WorldPack\WorldPackGenerationClubRun;
use App\Entity\WorldPack\WorldPackGenerationTierRun;
use App\Enum\WorldPack\WorldPackGenerationTierRunStatus;
use App\Message\WorldPack\WarmWorldPackClubMessage;
use App\Message\WorldPack\WarmWorldPackTierMessage;
use App\Repository\AgentRepository;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Repository\StarterConfigRepository;
use App\Repository\WorldPack\WorldPackGenerationTierRunRepository;
use App\Service\WorldInitializationService;
use App\Service\WorldPack\WorldPackTierAssemblyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Claims a tier run, creates one WorldPackGenerationClubRun per club in the tier, and
 * dispatches one WarmWorldPackClubMessage per club. The claim guard is loose
 * (status != 'in_progress'/'assembling'/'completed'/'failed', i.e. still PENDING) since
 * the Messenger consumer is flock-guarded to one process at a time (see
 * docker/worldpack-consume.sh) — there's no genuine concurrent delivery to defend
 * against the way overlapping cron ticks can create elsewhere in this codebase.
 */
#[AsMessageHandler]
class WarmWorldPackTierMessageHandler
{
    public function __construct(
        private readonly WorldPackGenerationTierRunRepository $tierRunRepository,
        private readonly NpcClubRepository $npcClubRepository,
        private readonly LeagueRepository $leagueRepository,
        private readonly AgentRepository $agentRepository,
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly WorldInitializationService $worldInitializationService,
        private readonly WorldPackTierAssemblyService $assemblyService,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(WarmWorldPackTierMessage $message): void
    {
        $tierRun = $this->tierRunRepository->find($message->tierRunId);
        if ($tierRun === null) {
            return;
        }

        if (!$this->claim($tierRun)) {
            return; // already claimed by a prior delivery
        }

        $starterConfig = $this->starterConfigRepository->getConfig();
        $tierConf      = $this->worldInitializationService->resolveTierConfig($tierRun->getTier());

        // The tier run only stores country/tier, not a League FK — re-resolved here,
        // same lookup WorldPackTierAssemblyService does at assembly time.
        $league = $this->leagueRepository->findByCountryAndTier($tierRun->getCountry(), $tierRun->getTier());
        if ($league === null) {
            // Defensive — WorldPackGenerationOrchestrator only creates a tier run for a
            // tier whose League was confirmed to exist at dispatch time.
            $this->assemblyService->markTierFailed($tierRun, "League no longer exists for country={$tierRun->getCountry()} tier={$tierRun->getTier()}");

            return;
        }

        $npcClubs = $this->npcClubRepository->findByLeague($league);

        $avgSquad  = ((int) $tierConf['playerMin'] + (int) $tierConf['playerMax']) / 2;
        $estimated = (int) ceil(count($npcClubs) * $avgSquad);
        $agents    = $this->worldInitializationService->selectBoundedAgentPool(
            $this->agentRepository->findAll(),
            $estimated,
            $starterConfig->getWorldPackPlayersPerAgent(),
        );
        $tierRun->setAgentPoolIds(array_map(static fn ($a) => (string) $a->getId(), $agents));

        $clubRuns = [];
        foreach ($npcClubs as $npcClub) {
            $clubRun    = new WorldPackGenerationClubRun($tierRun, $npcClub);
            $clubRuns[] = $clubRun;
            $this->em->persist($clubRun);
        }
        $tierRun->setTotalClubCount(count($clubRuns));

        $this->em->flush();

        foreach ($clubRuns as $clubRun) {
            $this->messageBus->dispatch(new WarmWorldPackClubMessage($clubRun->getId()->toRfc4122()));
        }

        // No clubs in this tier — nothing to wait for, attempt assembly right away
        // (maybeAssemble() is a safe no-op if somehow already progressed past this).
        if ($clubRuns === []) {
            $this->assemblyService->maybeAssemble($tierRun);
        }
    }

    private function claim(WorldPackGenerationTierRun $tierRun): bool
    {
        $now      = new \DateTimeImmutable();
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE world_pack_generation_tier_run
               SET status = :in_progress, started_at = :now, claimed_at = :now
             WHERE id = :id
               AND status = :pending
               AND claimed_at IS NULL
            SQL,
            [
                'in_progress' => WorldPackGenerationTierRunStatus::IN_PROGRESS->value,
                'now'         => $now->format('Y-m-d H:i:s'),
                'id'          => $tierRun->getId()->toRfc4122(),
                'pending'     => WorldPackGenerationTierRunStatus::PENDING->value,
            ],
        );

        if ($affected === 0) {
            return false;
        }

        $this->em->refresh($tierRun);

        return true;
    }
}
