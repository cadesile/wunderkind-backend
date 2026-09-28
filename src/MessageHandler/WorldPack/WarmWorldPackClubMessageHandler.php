<?php

declare(strict_types=1);

namespace App\MessageHandler\WorldPack;

use App\Entity\WorldPack\WorldPackGenerationClubRun;
use App\Enum\WorldPack\WorldPackGenerationClubRunStatus;
use App\Message\WorldPack\WarmWorldPackClubMessage;
use App\Repository\AgentRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\WorldPack\WorldPackGenerationClubRunRepository;
use App\Service\ClubInitializationService;
use App\Service\WorldInitializationService;
use App\Service\WorldPackClubGenerationService;
use App\Service\WorldPack\WorldPackTierAssemblyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Generates one club's players/staff/scouts (WorldPackClubGenerationService) and stores
 * the built snapshot on the club-run row. Never lets an exception escape: on failure it
 * self-manages retry via the row's own `attempts` column (up to MAX_ATTEMPTS, with a
 * growing delay) rather than relying on Messenger's own retry_strategy — one source of
 * truth for what the admin progress UI shows, not two independently-counting retry
 * mechanisms that could disagree. After every outcome (success, self-retry, or terminal
 * failure) it asks WorldPackTierAssemblyService whether the parent tier is now ready to
 * assemble.
 *
 * Claim guard is loose (status != 'completed') rather than a strict single-prior-status
 * gate — see WarmWorldPackTierMessageHandler's docblock for why that's sufficient here
 * (single-consumer, flock-guarded Messenger drain).
 */
#[AsMessageHandler]
class WarmWorldPackClubMessageHandler
{
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY_MS = 5000;

    public function __construct(
        private readonly WorldPackGenerationClubRunRepository $clubRunRepository,
        private readonly WorldPackClubGenerationService $clubGenerator,
        private readonly WorldPackTierAssemblyService $assemblyService,
        private readonly WorldInitializationService $worldInitializationService,
        private readonly PoolConfigRepository $poolConfigRepository,
        private readonly AgentRepository $agentRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(WarmWorldPackClubMessage $message): void
    {
        $clubRun = $this->clubRunRepository->find($message->clubRunId);
        if ($clubRun === null) {
            return;
        }

        if (!$this->claim($clubRun)) {
            return; // already completed by a prior delivery
        }

        try {
            $this->generate($clubRun);
            $clubRun->setStatus(WorldPackGenerationClubRunStatus::COMPLETED);
            $clubRun->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->handleFailure($clubRun, $e);
        }

        $this->assemblyService->maybeAssemble($clubRun->getTierRun());
    }

    private function generate(WorldPackGenerationClubRun $clubRun): void
    {
        $tierRun = $clubRun->getTierRun();
        $npcClub = $clubRun->getNpcClub();

        $tierConf     = $this->worldInitializationService->resolveTierConfig($tierRun->getTier());
        $abilityRange = $this->worldInitializationService->resolveAbilityRange($tierRun->getCountry(), $tierRun->getTier());
        $nationality  = ClubInitializationService::countryToNationality($tierRun->getCountry()) ?? $tierRun->getCountry();
        $poolConfig   = $this->poolConfigRepository->getConfig();
        $agents       = $this->resolveAgents($tierRun->getAgentPoolIds() ?? []);

        $clubRun->setStatus(WorldPackGenerationClubRunStatus::GENERATING_PLAYERS);
        $this->em->flush();
        $players = $this->clubGenerator->generatePlayers($tierConf, $abilityRange, $nationality, $poolConfig, $agents);

        $clubRun->setStatus(WorldPackGenerationClubRunStatus::GENERATING_STAFF);
        $this->em->flush();
        $staff = $this->clubGenerator->generateStaff($tierConf, $nationality);

        $clubRun->setStatus(WorldPackGenerationClubRunStatus::GENERATING_SCOUTS);
        $this->em->flush();
        $scouts = $this->clubGenerator->generateScouts($tierConf, $nationality);

        $clubRun->setStatus(WorldPackGenerationClubRunStatus::ASSIGNING);
        $this->em->flush();
        $snapshot = $this->clubGenerator->buildSnapshot($npcClub, $players, $staff, $scouts);

        $clubRun->setSnapshotJson($snapshot);
    }

    /** @param string[] $agentIds */
    private function resolveAgents(array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }

        return $this->agentRepository->findBy(['id' => $agentIds]);
    }

    private function handleFailure(WorldPackGenerationClubRun $clubRun, \Throwable $e): void
    {
        $clubRun->setErrorMessage($e->getMessage());

        if ($clubRun->getAttempts() < self::MAX_ATTEMPTS) {
            $clubRun->setStatus(WorldPackGenerationClubRunStatus::PENDING);
            $clubRun->setClaimedAt(null);
            $this->em->flush();

            $this->messageBus->dispatch(
                new WarmWorldPackClubMessage($clubRun->getId()->toRfc4122()),
                [new DelayStamp(self::RETRY_DELAY_MS * $clubRun->getAttempts())],
            );

            return;
        }

        $clubRun->setStatus(WorldPackGenerationClubRunStatus::FAILED);
        $clubRun->setFinishedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function claim(WorldPackGenerationClubRun $clubRun): bool
    {
        $now      = new \DateTimeImmutable();
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE world_pack_generation_club_run
               SET status = :generating_players, claimed_at = :now, started_at = COALESCE(started_at, :now), attempts = attempts + 1
             WHERE id = :id
               AND status != :completed
            SQL,
            [
                'generating_players' => WorldPackGenerationClubRunStatus::GENERATING_PLAYERS->value,
                'now'                => $now->format('Y-m-d H:i:s'),
                'id'                 => $clubRun->getId()->toRfc4122(),
                'completed'          => WorldPackGenerationClubRunStatus::COMPLETED->value,
            ],
        );

        if ($affected === 0) {
            return false;
        }

        $this->em->refresh($clubRun);

        return true;
    }
}
