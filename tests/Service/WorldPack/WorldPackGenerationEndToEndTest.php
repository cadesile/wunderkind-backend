<?php

declare(strict_types=1);

namespace App\Tests\Service\WorldPack;

use App\Entity\League;
use App\Entity\NpcClub;
use App\Entity\User;
use App\Entity\WorldPack\WorldPackGenerationClubRun;
use App\Enum\CitySize;
use App\Enum\WorldPack\WorldPackGenerationClubRunStatus;
use App\Enum\WorldPack\WorldPackGenerationRunStatus;
use App\Enum\WorldPack\WorldPackGenerationTierRunStatus;
use App\MessageHandler\WorldPack\WarmWorldPackClubMessageHandler;
use App\MessageHandler\WorldPack\WarmWorldPackTierMessageHandler;
use App\Repository\CountryWorldPackCacheRepository;
use App\Repository\WorldPack\WorldPackGenerationClubRunRepository;
use App\Repository\WorldPack\WorldPackGenerationRunRepository;
use App\Repository\WorldPack\WorldPackGenerationTierRunRepository;
use App\Service\WorldPack\WorldPackGenerationAlreadyRunningException;
use App\Service\WorldPack\WorldPackGenerationOrchestrator;
use App\Entity\WorldPack\WorldPackSet;
use App\Enum\WorldPack\WorldPackSetStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Exercises the real country -> tier -> club generation flow end-to-end: orchestrator
 * dispatch, tier handler (claim, club-run creation, club dispatch), club handler
 * (generation, claim idempotency), and tier assembly (real cache write). Handlers are
 * invoked directly rather than through a running Messenger consumer — standard practice
 * for testing a handler, and this repo's own precedent (see
 * tests/Controller/Admin/NotificationDebugControllerTest.php) already reads dispatched
 * envelopes off the in-memory transport rather than actually consuming them.
 */
class WorldPackGenerationEndToEndTest extends KernelTestCase
{
    private const COUNTRY = 'EN';
    private const TIER    = 8;

    private EntityManagerInterface $em;
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    private function track(object $entity): object
    {
        $this->cleanup[] = $entity;
        return $entity;
    }

    /** @return Envelope[] */
    private function drainTransport(): array
    {
        // World Pack Cache messages route to their own `worldpack` transport, separate from
        // `async` (push notifications) — see config/packages/messenger.yaml.
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.worldpack');
        $sent      = $transport->getSent();
        $transport->reset();
        return $sent;
    }

    public function testFullRunAssemblesRealCachePayload(): void
    {
        $league = $this->track(new League(self::COUNTRY, self::TIER, 'E2E Test League'));
        $this->em->persist($league);

        $clubs = [];
        foreach (['E2E Alpha', 'E2E Beta'] as $name) {
            $npc = new NpcClub($name, self::COUNTRY, self::TIER, 20, '#111111', '#eeeeee', 1_000_000, [], citySize: CitySize::MEDIUM);
            $npc->setLeague($league);
            $this->em->persist($npc);
            $clubs[] = $this->track($npc);
        }
        $this->em->flush();

        // No pool fixture, deliberately. A set is a world AND its starter squad, and both
        // halves are now GENERATED — so this run proves the orchestrator works against an
        // empty `player` table, which is the condition that used to make it refuse outright.

        /** @var WorldPackGenerationOrchestrator $orchestrator */
        $orchestrator = self::getContainer()->get(WorldPackGenerationOrchestrator::class);
        $result       = $orchestrator->startRun(self::COUNTRY, [self::TIER], 2);
        $run          = $this->track($result['run']);

        $this->assertSame(WorldPackGenerationRunStatus::IN_PROGRESS, $run->getStatus());

        // Two SETS from one run, each with its own starter squad drawn up front.
        $this->assertCount(2, $result['sets']);
        $this->assertSame(2, $run->getSetCount());
        foreach ($result['sets'] as $set) {
            $this->track($set);
            $this->assertSame(WorldPackSetStatus::BUILDING, $set->getStatus(), 'a set is not claimable until its tiers exist');
            $this->assertNotNull($set->getStarterPackJson());
            $this->assertNotEmpty($set->getStarterPackJson()['players']);
        }

        // The uniqueness guarantee the whole mechanism exists for: two sets of the same
        // country never share a player — for the same reason two NPC clubs cannot, now that
        // the squad is generated rather than taken from a shared population.
        $idsA = array_column($result['sets'][0]->getStarterPackJson()['players'], 'id');
        $idsB = array_column($result['sets'][1]->getStarterPackJson()['players'], 'id');
        $this->assertNotEmpty($idsA);
        $this->assertSame([], array_intersect($idsA, $idsB), 'two sets must never share a starter player');

        // One tier message per (set x tier).
        $tierEnvelopes = $this->drainTransport();
        $this->assertCount(2, $tierEnvelopes);

        /** @var WarmWorldPackTierMessageHandler $tierHandler */
        $tierHandler = self::getContainer()->get(WarmWorldPackTierMessageHandler::class);
        /** @var WarmWorldPackClubMessageHandler $clubHandler */
        $clubHandler = self::getContainer()->get(WarmWorldPackClubMessageHandler::class);
        /** @var WorldPackGenerationTierRunRepository $tierRunRepo */
        $tierRunRepo = self::getContainer()->get(WorldPackGenerationTierRunRepository::class);
        /** @var CountryWorldPackCacheRepository $cacheRepo */
        $cacheRepo = self::getContainer()->get(CountryWorldPackCacheRepository::class);

        foreach ($tierEnvelopes as $tierEnvelope) {
            $tierHandler($tierEnvelope->getMessage());

            $clubEnvelopes = $this->drainTransport();
            $this->assertCount(2, $clubEnvelopes);
            foreach ($clubEnvelopes as $envelope) {
                $clubHandler($envelope->getMessage());
            }
        }

        $tierRuns = $tierRunRepo->findByRun($run);
        $this->assertCount(2, $tierRuns);
        foreach ($tierRuns as $tierRun) {
            $this->em->refresh($tierRun);
            $this->assertSame(WorldPackGenerationTierRunStatus::COMPLETED, $tierRun->getStatus());
            $this->assertSame(2, $tierRun->getCompletedClubCount());
            $this->assertSame(0, $tierRun->getFailedClubCount());
            $this->assertNotNull($tierRun->getSet(), 'the set lives on the tier run, not the run');
        }

        $this->em->refresh($run);
        $this->assertSame(WorldPackGenerationRunStatus::COMPLETED, $run->getStatus());
        $this->assertNotNull($run->getFinishedAt());

        // Each set got its own payload row for the tier, and went READY once complete.
        foreach ($result['sets'] as $set) {
            $this->em->refresh($set);
            $this->assertSame(WorldPackSetStatus::READY, $set->getStatus());
            $this->assertNotNull($set->getReadyAt());

            $cacheEntry = $cacheRepo->findForSetAndTier($set, self::TIER);
            $this->assertNotNull($cacheEntry, 'assembly should have written a payload row for this set');

            $payload = $cacheEntry->getPayload();
            $this->assertCount(2, $payload['clubs']);
            foreach ($payload['clubs'] as $clubSnap) {
                $this->assertNotEmpty($clubSnap['players'], 'each assembled club should carry real generated players');
            }
        }

        // The two sets are genuinely different worlds, not the same payload twice.
        $npcA = $cacheRepo->findForSetAndTier($result['sets'][0], self::TIER)->getPayload();
        $npcB = $cacheRepo->findForSetAndTier($result['sets'][1], self::TIER)->getPayload();
        $this->assertNotSame(
            array_column($npcA['clubs'][0]['players'], 'id'),
            array_column($npcB['clubs'][0]['players'], 'id'),
            'each set must be its own world',
        );

        // snapshotJson should be nulled out post-assembly (already folded into the payload)
        /** @var WorldPackGenerationClubRunRepository $clubRunRepo */
        $clubRunRepo = self::getContainer()->get(WorldPackGenerationClubRunRepository::class);
        foreach ($tierRuns as $tierRun) {
            foreach ($clubRunRepo->findByTierRun($tierRun) as $clubRun) {
                $this->assertNull($clubRun->getSnapshotJson());
                $this->assertSame(WorldPackGenerationClubRunStatus::COMPLETED, $clubRun->getStatus());
            }
        }
    }

    public function testOnlyOneActiveRunPerCountryEnforced(): void
    {
        /** @var WorldPackGenerationOrchestrator $orchestrator */
        $orchestrator = self::getContainer()->get(WorldPackGenerationOrchestrator::class);

        // Still per COUNTRY, deliberately: the set lives on the TIER RUN, so moving to a
        // buffer of sets per country did not need this index — or this guarantee — to change.

        $league = $this->track(new League('DE', 1, 'Single Flight Test League'));
        $this->em->persist($league);
        $this->em->flush();

        $first = $orchestrator->startRun('DE', [1], 1);
        $this->track($first['run']);
        foreach ($first['sets'] as $set) {
            $this->track($set);
        }
        $this->drainTransport();

        $this->expectException(WorldPackGenerationAlreadyRunningException::class);
        $orchestrator->startRun('DE', [1], 1);
    }

    /**
     * A set whose tier failed must never become claimable.
     *
     * This is the half-built state the whole buffer exists to prevent: a set that is READY but
     * missing a tier would be handed to a real player, who would then be unable to finish
     * initialising and would have no way to ask for a different world. A tier is never
     * assembled partial, so the only way this can arise is a tier that failed outright — and
     * the set has to follow it to FAILED rather than sitting in BUILDING until the staleness
     * window expires, so it leaves the buffer count immediately and the admin screen says why.
     */
    public function testASetWhoseTierFailedGoesFailedAndIsNotClaimable(): void
    {
        $run = $this->track(new \App\Entity\WorldPack\WorldPackGenerationRun('ZY', [7], 1));
        $run->setStatus(WorldPackGenerationRunStatus::IN_PROGRESS);
        $this->em->persist($run);

        $set = $this->track(new WorldPackSet(
            'ZY',
            \App\Service\WorldInitializationService::WORLD_PACK_VERSION,
            ['players' => [['id' => 'p']], 'staff' => [], 'scouts' => []],
            1,
        ));
        $this->em->persist($set);

        // No League for ZY tier 7, so the handler takes its own defensive failure path.
        $tierRun = $this->track(new \App\Entity\WorldPack\WorldPackGenerationTierRun($run, 'ZY', 7, $set));
        $this->em->persist($tierRun);
        $this->em->flush();

        /** @var WarmWorldPackTierMessageHandler $tierHandler */
        $tierHandler = self::getContainer()->get(WarmWorldPackTierMessageHandler::class);
        $tierHandler(new \App\Message\WorldPack\WarmWorldPackTierMessage($tierRun->getId()->toRfc4122()));

        $this->em->refresh($tierRun);
        $this->em->refresh($set);

        $this->assertSame(WorldPackGenerationTierRunStatus::FAILED, $tierRun->getStatus());
        $this->assertSame(WorldPackSetStatus::FAILED, $set->getStatus());
        $this->assertNotNull($set->getErrorMessage());
        $this->assertFalse(
            $set->isClaimable(\App\Service\WorldInitializationService::WORLD_PACK_VERSION),
            'a set with a failed tier must never be handed to a club',
        );
    }

    public function testTierHandlerFailsGracefullyWhenLeagueIsMissing(): void
    {
        // Bypass the orchestrator (which itself skips tiers with no League) to exercise
        // the handler's own defensive check directly. The run is manually set
        // IN_PROGRESS to match what startRun() would already have done by the time a
        // tier message is being processed — the run-completion claim SQL requires it.
        $run = $this->track(new \App\Entity\WorldPack\WorldPackGenerationRun('ZZ', [7]));
        $run->setStatus(WorldPackGenerationRunStatus::IN_PROGRESS);
        $this->em->persist($run);
        $tierRun = $this->track(new \App\Entity\WorldPack\WorldPackGenerationTierRun($run, 'ZZ', 7));
        $this->em->persist($tierRun);
        $this->em->flush();

        /** @var WarmWorldPackTierMessageHandler $tierHandler */
        $tierHandler = self::getContainer()->get(WarmWorldPackTierMessageHandler::class);
        $tierHandler(new \App\Message\WorldPack\WarmWorldPackTierMessage($tierRun->getId()->toRfc4122()));

        $this->em->refresh($tierRun);
        $this->assertSame(WorldPackGenerationTierRunStatus::FAILED, $tierRun->getStatus());
        $this->assertStringContainsString('League no longer exists', $tierRun->getErrorMessage());

        $this->em->refresh($run);
        $this->assertSame(WorldPackGenerationRunStatus::COMPLETED_WITH_ERRORS, $run->getStatus());
    }

    public function testClubHandlerClaimIsIdempotentOnAnAlreadyCompletedRow(): void
    {
        $league = $this->track(new League(self::COUNTRY, self::TIER, 'Idempotency Test League'));
        $this->em->persist($league);

        $npc = new NpcClub('Idempotent FC', self::COUNTRY, self::TIER, 20, '#111111', '#eeeeee', 1_000_000, [], citySize: CitySize::MEDIUM);
        $npc->setLeague($league);
        $this->em->persist($npc);
        $this->track($npc);

        // Both run and tier are manually set IN_PROGRESS to match what the orchestrator
        // + tier handler would already have done by the time a club message is being
        // processed — the club-level test below exercises the club handler in
        // isolation, bypassing that normal claim chain.
        $run = $this->track(new \App\Entity\WorldPack\WorldPackGenerationRun(self::COUNTRY, [self::TIER]));
        $run->setStatus(WorldPackGenerationRunStatus::IN_PROGRESS);
        $this->em->persist($run);
        // The tier run needs its SET: assembly writes the payload to the set, and a tier run
        // without one is failed on purpose rather than silently discarding the payload.
        $set = $this->track(new WorldPackSet(
            self::COUNTRY,
            \App\Service\WorldInitializationService::WORLD_PACK_VERSION,
            ['players' => [['id' => 'seeded']], 'staff' => [], 'scouts' => []],
            1,
        ));
        $this->em->persist($set);
        $tierRun = $this->track(new \App\Entity\WorldPack\WorldPackGenerationTierRun($run, self::COUNTRY, self::TIER, $set));
        $tierRun->setStatus(WorldPackGenerationTierRunStatus::IN_PROGRESS);
        $tierRun->setTotalClubCount(1);
        $this->em->persist($tierRun);
        $clubRun = $this->track(new WorldPackGenerationClubRun($tierRun, $npc));
        $this->em->persist($clubRun);
        $this->em->flush();

        /** @var WarmWorldPackClubMessageHandler $clubHandler */
        $clubHandler = self::getContainer()->get(WarmWorldPackClubMessageHandler::class);
        $message     = new \App\Message\WorldPack\WarmWorldPackClubMessage($clubRun->getId()->toRfc4122());

        $clubHandler($message);
        $this->em->refresh($clubRun);
        $this->assertSame(WorldPackGenerationClubRunStatus::COMPLETED, $clubRun->getStatus());
        // This tier has exactly 1 club, so completion already triggered full tier
        // assembly inline — snapshotJson is nulled post-assembly by design (already
        // folded into the cache), confirming the whole pipeline actually ran, not
        // just the club-generation step in isolation.
        $this->assertNull($clubRun->getSnapshotJson());
        $attemptsAfterFirst = $clubRun->getAttempts();

        /** @var CountryWorldPackCacheRepository $cacheRepo */
        $cacheRepo  = self::getContainer()->get(CountryWorldPackCacheRepository::class);
        $cacheEntry = $cacheRepo->findForSetAndTier($set, self::TIER);
        $this->assertNotNull($cacheEntry);

        // Second delivery of the same message must be a genuine no-op — claim() guards
        // on status != 'completed', so it never re-enters generation or re-triggers
        // assembly a second time (which would otherwise try to replace an already-fresh
        // cache entry unnecessarily).
        $clubHandler($message);
        $this->em->refresh($clubRun);

        $this->assertSame(WorldPackGenerationClubRunStatus::COMPLETED, $clubRun->getStatus());
        $this->assertSame($attemptsAfterFirst, $clubRun->getAttempts());
    }
}
