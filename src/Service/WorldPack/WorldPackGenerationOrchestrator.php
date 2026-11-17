<?php

declare(strict_types=1);

namespace App\Service\WorldPack;

use App\Entity\WorldPack\WorldPackGenerationRun;
use App\Entity\WorldPack\WorldPackGenerationTierRun;
use App\Entity\WorldPack\WorldPackSet;
use App\Enum\WorldPack\WorldPackGenerationRunStatus;
use App\Message\WorldPack\WarmWorldPackTierMessage;
use App\Repository\LeagueRepository;
use App\Repository\StarterConfigRepository;
use App\Repository\WorldPack\WorldPackGenerationRunRepository;
use App\Service\StarterPackService;
use App\Service\WorldInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The entry point the admin "Regenerate Country Cache" action calls: rebuilds a country's
 * whole buffer of WorldPackSets from scratch.
 *
 * One run covers every set it is asked for. Each set gets its starter squad drawn here, up
 * front, then one WorldPackGenerationTierRun per (set x tier) and one
 * WarmWorldPackTierMessage per tier run. Single-flight per country is still enforced by the
 * run table's own partial unique index — unchanged by the move to sets, because the run is
 * still the per-country unit and it is the TIER RUN that carries which set it belongs to.
 *
 * ── Why the starter squad is generated here rather than at the end ───────────────────────
 *
 * It is in-memory generation, sub-second, with nothing to parallelise and nothing to fail on:
 * StarterPackService::generatePack() builds the squad from tier config through the same
 * generator NPC clubs use and touches no pool. Doing it last — after the tiers are published —
 * would mean a failure left a tier-complete set with no squad, which is the one state the
 * buffer exists to make impossible. Doing it in its own message would add a hop and a second
 * failure mode and buy nothing, since a set is unusable until both halves exist.
 *
 * It used to be a POOL DRAW, which is why the code around it once handled a supply failure
 * part-way through a run. Generation removed that whole class of outcome: two sets can no more
 * share a player than two NPC clubs can, because neither takes from a shared population.
 */
class WorldPackGenerationOrchestrator
{
    public function __construct(
        private readonly WorldPackGenerationRunRepository $runRepository,
        private readonly WorldPackSetService              $setService,
        private readonly StarterPackService               $starterPackService,
        private readonly StarterConfigRepository          $starterConfigRepository,
        private readonly LeagueRepository                 $leagueRepository,
        private readonly MessageBusInterface              $messageBus,
        private readonly EntityManagerInterface           $em,
        private readonly LoggerInterface                  $logger,
    ) {}

    /**
     * @param int[] $tiers    Tiers to generate. Tiers with no League for $country are skipped.
     * @param ?int  $setCount How many sets to build; defaults to the configured buffer target.
     *
     * @return array{run: WorldPackGenerationRun, sets: WorldPackSet[], requestedSets: int}
     *         `sets` always has `requestedSets` entries: the squads are generated, so there is
     *         no supply that can run out part-way through a run.
     *
     * @throws WorldPackGenerationAlreadyRunningException
     */
    public function startRun(string $country, array $tiers, ?int $setCount = null): array
    {
        $setCount ??= $this->targetSetCount();

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

        $tiers = array_values(array_filter(
            $tiers,
            fn(int $tier): bool => $this->leagueRepository->findByCountryAndTier($country, $tier) !== null,
        ));

        // Clear the old stock BEFORE drawing, so the country is not briefly holding four sets
        // and so the pool rows the outgoing sets consumed are not counted against the new
        // draw. Claimed sets survive — a device mid-initialisation still needs its tiers.
        $this->setService->deleteUnclaimed($country);

        $bottomTier = $this->starterPackService->resolveStarterTier($country);

        $sets = [];
        for ($i = 0; $i < $setCount; $i++) {
            $sets[] = $this->buildSet($country, $bottomTier, count($tiers));
        }

        $run = new WorldPackGenerationRun($country, $tiers, count($sets));
        $this->em->persist($run);
        $this->em->flush();

        $run->setStatus(WorldPackGenerationRunStatus::IN_PROGRESS);
        $run->setStartedAt(new \DateTimeImmutable());

        $tierRuns = [];
        foreach ($sets as $set) {
            foreach ($tiers as $tier) {
                $tierRun    = new WorldPackGenerationTierRun($run, $country, $tier, $set);
                $tierRuns[] = $tierRun;
                $this->em->persist($tierRun);
            }
        }

        $this->em->flush();

        foreach ($tierRuns as $tierRun) {
            $this->messageBus->dispatch(new WarmWorldPackTierMessage($tierRun->getId()->toRfc4122()));
        }

        $this->logger->info('world_pack_set.generate.started', [
            'country'       => $country,
            'runId'         => (string) $run->getId(),
            'requestedSets' => $setCount,
            'startedSets'   => count($sets),
            'tiers'         => $tiers,
            'tierRuns'      => count($tierRuns),
        ]);

        return ['run' => $run, 'sets' => $sets, 'requestedSets' => $setCount];
    }

    /**
     * Generates one set's starter squad and persists the set row carrying it.
     *
     * The squad is built in memory and nothing is consumed, so this row is simply the first
     * place those snapshots are written rather than the only surviving record of a pool that
     * has been spent. The atomicity this used to need — removes and the recording row in one
     * flush — no longer has anything to be atomic about.
     *
     * The set is still created BUILDING: it has a squad but no tiers yet, and a set is not
     * servable until it has both.
     */
    private function buildSet(string $country, int $bottomTier, int $tierCount): WorldPackSet
    {
        $pack = $this->starterPackService->generatePack($country, $bottomTier);

        $set = new WorldPackSet(
            $country,
            WorldInitializationService::WORLD_PACK_VERSION,
            $pack,
            $tierCount,
        );

        $this->em->persist($set);
        $this->em->flush();

        return $set;
    }

    private function targetSetCount(): int
    {
        $configured = $this->starterConfigRepository->getConfig()->getWorldPackSetTarget();

        return $configured > 0 ? $configured : WorldPackSetService::DEFAULT_TARGET_SETS;
    }
}
