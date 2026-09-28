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

        /** @var WorldPackGenerationOrchestrator $orchestrator */
        $orchestrator = self::getContainer()->get(WorldPackGenerationOrchestrator::class);
        $run          = $this->track($orchestrator->startRun(self::COUNTRY, [self::TIER]));

        $this->assertSame(WorldPackGenerationRunStatus::IN_PROGRESS, $run->getStatus());

        $tierEnvelopes = $this->drainTransport();
        $this->assertCount(1, $tierEnvelopes);

        /** @var WarmWorldPackTierMessageHandler $tierHandler */
        $tierHandler = self::getContainer()->get(WarmWorldPackTierMessageHandler::class);
        $tierHandler($tierEnvelopes[0]->getMessage());

        /** @var WorldPackGenerationTierRunRepository $tierRunRepo */
        $tierRunRepo = self::getContainer()->get(WorldPackGenerationTierRunRepository::class);
        $tierRun     = $tierRunRepo->findByRun($run)[0];
        $this->assertSame(2, $tierRun->getTotalClubCount());
        $this->assertSame(WorldPackGenerationTierRunStatus::IN_PROGRESS, $tierRun->getStatus());

        $clubEnvelopes = $this->drainTransport();
        $this->assertCount(2, $clubEnvelopes);

        /** @var WarmWorldPackClubMessageHandler $clubHandler */
        $clubHandler = self::getContainer()->get(WarmWorldPackClubMessageHandler::class);
        foreach ($clubEnvelopes as $envelope) {
            $clubHandler($envelope->getMessage());
        }

        $this->em->refresh($tierRun);
        $this->assertSame(WorldPackGenerationTierRunStatus::COMPLETED, $tierRun->getStatus());
        $this->assertSame(2, $tierRun->getCompletedClubCount());
        $this->assertSame(0, $tierRun->getFailedClubCount());

        $this->em->refresh($run);
        $this->assertSame(WorldPackGenerationRunStatus::COMPLETED, $run->getStatus());
        $this->assertNotNull($run->getFinishedAt());

        /** @var CountryWorldPackCacheRepository $cacheRepo */
        $cacheRepo = self::getContainer()->get(CountryWorldPackCacheRepository::class);
        $cacheEntry = $cacheRepo->findForCountryAndTier(self::COUNTRY, self::TIER);
        $this->assertNotNull($cacheEntry, 'assembly should have written a real CountryWorldPackCache entry');
        $this->track($cacheEntry);

        $payload = $cacheEntry->getPayload();
        $this->assertCount(2, $payload['clubs']);
        foreach ($payload['clubs'] as $clubSnap) {
            $this->assertNotEmpty($clubSnap['players'], 'each assembled club should carry real generated players');
        }

        // snapshotJson should be nulled out post-assembly (already folded into the cache)
        /** @var WorldPackGenerationClubRunRepository $clubRunRepo */
        $clubRunRepo = self::getContainer()->get(WorldPackGenerationClubRunRepository::class);
        foreach ($clubRunRepo->findByTierRun($tierRun) as $clubRun) {
            $this->assertNull($clubRun->getSnapshotJson());
            $this->assertSame(WorldPackGenerationClubRunStatus::COMPLETED, $clubRun->getStatus());
        }
    }

    public function testOnlyOneActiveRunPerCountryEnforced(): void
    {
        /** @var WorldPackGenerationOrchestrator $orchestrator */
        $orchestrator = self::getContainer()->get(WorldPackGenerationOrchestrator::class);

        $first = $this->track($orchestrator->startRun('DE', [1]));
        $this->drainTransport();

        $this->expectException(WorldPackGenerationAlreadyRunningException::class);
        $orchestrator->startRun('DE', [2]);
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
        $tierRun = $this->track(new \App\Entity\WorldPack\WorldPackGenerationTierRun($run, self::COUNTRY, self::TIER));
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
        $cacheEntry = $cacheRepo->findForCountryAndTier(self::COUNTRY, self::TIER);
        $this->assertNotNull($cacheEntry);
        $this->track($cacheEntry);

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
