<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionRound;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Message\Competition\DrawCompetitionRoundMessage;
use App\Message\Competition\ResolveCompetitionRoundMessage;
use App\MessageHandler\Competition\DrawCompetitionRoundMessageHandler;
use App\MessageHandler\Competition\ResolveCompetitionRoundMessageHandler;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionDrawService;
use App\Service\Competition\CompetitionLockService;
use App\Service\Competition\CompetitionResultsService;
use App\Tests\Support\EntrantFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use App\Message\Competition\WarmCompetitionResponseMessage;
use App\MessageHandler\Competition\WarmCompetitionResponseMessageHandler;
use App\Repository\Competition\CompetitionResponseCacheRepository;
use App\Service\Competition\CompetitionResponseBuilder;
use App\Service\Competition\CompetitionResponseCacheService;

/**
 * Round processing is queued, and the queue is safe to over-dispatch into.
 *
 * The cron ticks used to draw and resolve every due round inline, in one process — a draw
 * writes an inbox message per entrant and a push per fixture, and a resolve runs the match
 * engine plus narrative generation for up to 32 fixtures. With several tournaments reaching a
 * round boundary in the same minute that is unbounded work in a single tick. The ticks now
 * only queue; the work happens on the `competition` transport.
 *
 * The property that makes that safe is tested here directly: **the claim is taken by the
 * handler, not the dispatcher.** So the dispatcher can send a message for every due round on
 * every tick without tracking what it already sent, and a duplicate delivery costs nothing.
 */
class CompetitionRoundQueueTest extends KernelTestCase
{
    use EntrantFixtureTrait;

    private EntityManagerInterface $em;
    private CompetitionDrawService $drawService;
    private CompetitionResultsService $resultsService;
    private CompetitionRoundRepository $roundRepository;
    private CompetitionFixtureRepository $fixtureRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em                = self::getContainer()->get(EntityManagerInterface::class);
        $this->drawService       = self::getContainer()->get(CompetitionDrawService::class);
        $this->resultsService    = self::getContainer()->get(CompetitionResultsService::class);
        $this->roundRepository   = self::getContainer()->get(CompetitionRoundRepository::class);
        $this->fixtureRepository = self::getContainer()->get(CompetitionFixtureRepository::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_entrant_squad, competition_fixture,
                      competition_result, competition_result_audit, competition_round,
                      competition_response_cache, active_competition, competition_template,
                      entrant_reward_claim CASCADE',
        );
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.competition');
    }

    private function club(string $name): Club
    {
        $user = new User(sprintf('queue-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club($name, $user);
        $this->em->persist($user);
        $this->em->persist($club);

        return $club;
    }

    private function lockedCompetition(int $capacity = 4): ActiveCompetition
    {
        $template = new CompetitionTemplate('Queue Cup', 'queue-cup-' . uniqid('', true), $capacity, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);
        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);

        for ($i = 1; $i <= $capacity; $i++) {
            $club = $this->club("Queue Club {$i}");
            $this->makeEntrantFromEnvelope($this->em, $instance, $club, 0, [
                'club'    => ['id' => (string) $club->getId(), 'name' => "Queue Club {$i}"],
                'players' => [],
            ]);
        }
        $this->em->flush();

        self::getContainer()->get(CompetitionLockService::class)->lock($instance);
        $this->em->flush();

        return $instance;
    }

    private function firstRound(ActiveCompetition $instance): CompetitionRound
    {
        return $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
    }

    public function testDispatchingADueDrawQueuesAMessageAndDrawsNothingYet(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);

        // Far enough ahead to clear round 1's firstRoundLeadTimeSeconds. A 4-entrant bracket
        // holds two draw-pending rounds, so the count is asserted against what was actually
        // due rather than hardcoded — the property under test is one message per due round,
        // and no work done.
        $now  = new \DateTimeImmutable('+1 year');
        $due  = count($this->roundRepository->findDueForDraw($now));
        self::assertGreaterThan(0, $due);

        $queued = $this->drawService->dispatchDueDraws($now);

        self::assertSame($due, $queued);
        self::assertCount($queued, $this->transport()->getSent());
        self::assertInstanceOf(DrawCompetitionRoundMessage::class, $this->transport()->getSent()[0]->getMessage());

        // The tick must not have done the work, and must not have moved the round.
        $this->em->refresh($round);
        self::assertSame(CompetitionRoundStatus::DRAW_PENDING, $round->getStatus());
        self::assertNull($round->getDrawLockedAt(), 'the dispatcher must not take the claim — the handler does');
        self::assertCount(0, $this->fixtureRepository->findByRoundOrderedBySlot($round));
    }

    /**
     * The dispatcher deliberately does not dedupe, so this is the case that has to be free.
     */
    public function testASecondDeliveryOfTheSameDrawIsANoOp(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);
        $handler  = self::getContainer()->get(DrawCompetitionRoundMessageHandler::class);
        $message  = new DrawCompetitionRoundMessage((string) $round->getId());

        $handler($message);
        $this->em->refresh($round);
        self::assertSame(CompetitionRoundStatus::DRAWN, $round->getStatus());
        $fixtureCount = count($this->fixtureRepository->findByRoundOrderedBySlot($round));
        self::assertSame(2, $fixtureCount);

        // Same message again — must not draw a second set of fixtures.
        $handler($message);
        $this->em->refresh($round);

        self::assertCount(
            $fixtureCount,
            $this->fixtureRepository->findByRoundOrderedBySlot($round),
            'a duplicate delivery must not re-draw the round',
        );
    }

    public function testAMessageForADeletedRoundIsNotAnError(): void
    {
        $handler = self::getContainer()->get(DrawCompetitionRoundMessageHandler::class);

        // A competition can be deleted while its messages are still queued.
        $handler(new DrawCompetitionRoundMessage('01a17f00-0000-7000-8000-000000000000'));

        $this->expectNotToPerformAssertions();
    }

    public function testDispatchingDueResultsQueuesAMessageAndResolvesNothingYet(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);
        $this->drawService->forceDrawRound($round);
        $this->em->refresh($round);

        $queued = $this->resultsService->dispatchDueResults(new \DateTimeImmutable('+1 year'));

        self::assertSame(1, $queued);
        $sent = $this->transport()->getSent();
        self::assertInstanceOf(ResolveCompetitionRoundMessage::class, end($sent)->getMessage());

        $this->em->refresh($round);
        self::assertSame(CompetitionRoundStatus::DRAWN, $round->getStatus(), 'the tick must only queue');
        self::assertNull($round->getResolveLockedAt(), 'the dispatcher must not take the claim');
    }

    public function testTheResolveHandlerPublishesTheRoundAndASecondDeliveryIsANoOp(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);
        $this->drawService->forceDrawRound($round);
        $this->em->refresh($round);

        $handler = self::getContainer()->get(ResolveCompetitionRoundMessageHandler::class);
        $message = new ResolveCompetitionRoundMessage((string) $round->getId());

        $handler($message);
        $this->em->refresh($round);
        self::assertSame(CompetitionRoundStatus::RESULTS_PUBLISHED, $round->getStatus());

        // Already published — the claim's status predicate must reject this outright.
        $handler($message);
        $this->em->refresh($round);
        self::assertSame(CompetitionRoundStatus::RESULTS_PUBLISHED, $round->getStatus());
    }

    /**
     * A successful draw must queue a response-cache warm, and must not warm inline.
     *
     * Inline is the thing that was wrong before: this handler has the round's entrants in its
     * identity map, and Doctrine will not re-hydrate them, so building the document here
     * while fingerprinting fresh SQL stored a stale bracket under a fresh key. The warm is a
     * separate message precisely so the worker's EntityManager clear happens in between.
     */
    public function testASuccessfulDrawQueuesAResponseWarm(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);

        self::getContainer()->get(DrawCompetitionRoundMessageHandler::class)(
            new DrawCompetitionRoundMessage((string) $round->getId()),
        );

        $warms = array_filter(
            $this->transport()->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof WarmCompetitionResponseMessage,
        );

        self::assertCount(1, $warms, 'a committed draw must queue exactly one warm');
        self::assertSame(
            (string) $instance->getId(),
            array_values($warms)[0]->getMessage()->competitionId,
        );

        // And nothing was written to the cache by this handler itself.
        self::assertNull(
            self::getContainer()->get(CompetitionResponseCacheRepository::class)->findRow($instance),
            'the round handler must not write the cache row — the warm handler does, on a clean EntityManager',
        );
    }

    public function testASkippedDrawQueuesNoWarm(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);
        $handler  = self::getContainer()->get(DrawCompetitionRoundMessageHandler::class);
        $message  = new DrawCompetitionRoundMessage((string) $round->getId());

        $handler($message);
        $afterFirst = count(array_filter(
            $this->transport()->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof WarmCompetitionResponseMessage,
        ));

        // Second delivery does no work, so it must not queue a warm either.
        $handler($message);

        self::assertCount(
            $afterFirst,
            array_filter(
                $this->transport()->getSent(),
                static fn ($envelope) => $envelope->getMessage() instanceof WarmCompetitionResponseMessage,
            ),
            'a no-op delivery must not queue a redundant rebuild',
        );
    }

    public function testTheWarmHandlerStoresAUsableDocument(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);
        $this->drawService->forceDrawRound($round);

        self::getContainer()->get(WarmCompetitionResponseMessageHandler::class)(
            new WarmCompetitionResponseMessage((string) $instance->getId()),
        );

        $builder = self::getContainer()->get(CompetitionResponseBuilder::class);
        $cache   = self::getContainer()->get(CompetitionResponseCacheService::class);

        self::assertTrue(
            $cache->isCached($instance, $builder->stateFingerprint($instance)),
            'the warm must store a document the current fingerprint accepts',
        );
    }

    public function testTheWarmHandlerToleratesADeletedCompetition(): void
    {
        self::getContainer()->get(WarmCompetitionResponseMessageHandler::class)(
            new WarmCompetitionResponseMessage('01a17f00-0000-7000-8000-000000000000'),
        );

        $this->expectNotToPerformAssertions();
    }
}
