<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\InboxMessage;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionFixtureStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Message\SendPushNotificationMessage;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionDrawService;
use App\Service\Competition\CompetitionLockService;
use App\Service\Competition\CompetitionResultsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use App\Tests\Support\EntrantFixtureTrait;
use App\Repository\Competition\CompetitionResultAuditRepository;
use App\Service\Competition\MatchQueryService;

class CompetitionResultsServiceTest extends KernelTestCase
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
            'TRUNCATE competition_entrant, competition_fixture, competition_result, competition_round,
                      active_competition, competition_template, entrant_reward_claim, inbox_message CASCADE',
        );
    }

    private function transport(): mixed
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    private function createClub(string $name): Club
    {
        $user = new User('results-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club($name, $user);

        $this->em->persist($user);
        $this->em->persist($club);

        return $club;
    }

    private function buildDrawnCompetition(int $capacity = 4, int $victorPrize = 100_000): ActiveCompetition
    {
        $template = new CompetitionTemplate('Results Cup', 'results-cup-' . uniqid('', true), $capacity, CompetitionDuration::TEN_HOURS);
        $template->setVictorPrize($victorPrize);
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);

        for ($i = 1; $i <= $capacity; $i++) {
            $club    = $this->createClub("Club {$i}");
            $entrant = $this->makeEntrantFromEnvelope($this->em, $instance, $club, 0, [
                'club'    => ['id' => (string) $club->getId(), 'name' => "Club {$i}"],
                'players' => [['id' => 'p1', 'position' => 'MID', 'currentAbility' => 10]],
            ]);
        }
        $this->em->flush();

        self::getContainer()->get(CompetitionLockService::class)->lock($instance);
        $this->em->flush();

        $round1 = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $this->drawService->forceDrawRound($round1);

        return $instance;
    }

    public function testPublishDueResultsIsNotDueBeforeMatchesResolveAt(): void
    {
        $instance = $this->buildDrawnCompetition(capacity: 4);
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];

        // The reveal window hasn't elapsed yet — matchesResolveAt is hours in the future.
        $published = $this->resultsService->publishDueResults(new \DateTimeImmutable());
        $this->assertSame(0, $published);

        $this->em->refresh($round1);
        $this->assertSame(CompetitionRoundStatus::DRAWN, $round1->getStatus());
    }

    public function testPublishingResultsSendsMatchResultPushToBothSides(): void
    {
        $instance = $this->buildDrawnCompetition(capacity: 4);
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $round1->setMatchesResolveAt(new \DateTimeImmutable('-1 second'));
        $this->em->flush();
        $this->transport()->reset();

        $published = $this->resultsService->publishDueResults(new \DateTimeImmutable());
        $this->assertSame(1, $published);

        $matchResultMessages = array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof SendPushNotificationMessage && ($m->data['type'] ?? null) === 'MATCH_RESULT');

        // 2 fixtures x 2 sides = 4 pushes.
        $this->assertCount(4, $matchResultMessages);
    }

    /**
     * Capacity 8 (QF -> SF -> FINAL) on purpose: at capacity 4 the round after round 1 IS the
     * final, which now skips its intermission entirely (see the test below), so a 4-entrant
     * bracket can no longer demonstrate the ordinary intermission path this test is about.
     */
    public function testNonFinalRoundPublishSchedulesNextRoundDrawWithoutCreatingItsFixtures(): void
    {
        $instance = $this->buildDrawnCompetition(capacity: 8);
        $rounds   = $this->roundRepository->findByCompetitionOrderedByIndex($instance);
        [$round1, $round2] = $rounds;

        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($round1) as $fixture) {
            $this->resultsService->forceResolveFixture($fixture);
        }

        $this->em->refresh($round1);
        $this->em->refresh($round2);

        $this->assertSame(CompetitionRoundStatus::RESULTS_PUBLISHED, $round1->getStatus());
        $this->assertNotNull($round1->getCompletedAt());

        $this->assertSame(CompetitionRoundStatus::DRAW_PENDING, $round2->getStatus(), 'Drawing round 2 is CompetitionDrawService\'s job, on a later tick.');
        $this->assertGreaterThan($round1->getCompletedAt(), $round2->getScheduledAt(), 'An intermediate round keeps its intermission — its draw still decides who meets whom.');
        $this->assertCount(0, $this->fixtureRepository->findByRoundOrderedBySlot($round2));
    }

    /**
     * The final is the one round whose draw reveals nothing: once the semi-final has resolved
     * there are two entrants left and exactly one possible fixture, so making the finalists sit
     * out an intermission first is dead time. It is scheduled for the publishing instant, which
     * makes the very next draw tick (cron, every minute) pick it up.
     *
     * Still DRAW_PENDING rather than drawn here: scheduling is all CompetitionResultsService
     * does: the draw stays behind CompetitionDrawService's own claim-lock.
     */
    public function testSemiFinalPublishMakesTheFinalDueImmediatelyInsteadOfAfterAnIntermission(): void
    {
        $instance = $this->buildDrawnCompetition(capacity: 4);
        [$semiFinal, $final] = $this->roundRepository->findByCompetitionOrderedByIndex($instance);

        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($semiFinal) as $fixture) {
            $this->resultsService->forceResolveFixture($fixture);
        }

        $this->em->refresh($semiFinal);
        $this->em->refresh($final);

        $this->assertSame('FINAL', $final->getLabel());
        $this->assertSame(CompetitionRoundStatus::DRAW_PENDING, $final->getStatus());
        $this->assertEquals(
            $semiFinal->getCompletedAt(),
            $final->getScheduledAt(),
            'The final must be due the moment the semi-final publishes, not an intermission later.',
        );
        $this->assertLessThanOrEqual(
            new \DateTimeImmutable(),
            $final->getScheduledAt(),
            'Due now means the next draw tick draws it, rather than it sitting pending.',
        );
        $this->assertCount(0, $this->fixtureRepository->findByRoundOrderedBySlot($final), 'Seeding the fixture is still the draw service\'s job.');
    }

    public function testFinalRoundPublishCompletesTheCompetitionAndDeliversThePrize(): void
    {
        $instance = $this->buildDrawnCompetition(capacity: 4, victorPrize: 250_000);
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];

        $winners = [];
        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($round1) as $fixture) {
            $result    = $this->resultsService->forceResolveFixture($fixture);
            $fixture   = $this->fixtureRepository->findByRoundOrderedBySlot($round1)[$fixture->getSlotIndex()];
            $winners[] = $fixture->getWinnerEntrant();
        }

        $round2 = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[1];
        $this->em->refresh($round2);
        $this->drawService->forceDrawRound($round2);

        $this->transport()->reset();
        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($round2) as $fixture) {
            $this->resultsService->forceResolveFixture($fixture);
        }

        $this->em->refresh($instance);
        $this->em->refresh($round2);

        $this->assertSame(CompetitionRoundStatus::RESULTS_PUBLISHED, $round2->getStatus());
        $this->assertSame(ActiveCompetitionStatus::COMPLETED, $instance->getStatus());
        $this->assertNotNull($instance->getCompletedAt());

        $championMessages = array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof SendPushNotificationMessage && ($m->data['type'] ?? null) === 'COMPETITION_COMPLETED' && ($m->data['result'] ?? null) === 'WON');
        $this->assertCount(1, $championMessages);

        $winnerEntrant = $this->fixtureRepository->findByRoundOrderedBySlot($round2)[0]->getWinnerEntrant();
        $messages      = $this->em->getRepository(InboxMessage::class)->findBy(['club' => $winnerEntrant->getClub()]);
        $wonMessages   = array_values(array_filter(
            $messages,
            static fn (InboxMessage $m) => ($m->getOfferData()['type'] ?? null) === 'COMPETITION_WON',
        ));
        $this->assertCount(1, $wonMessages, 'The victor prize should be delivered as a single COMPETITION_WON inbox offer.');
        $this->assertSame(250_000, $wonMessages[0]->getOfferData()['effects'][0]['amountPence']);
    }

    public function testResolveLockedAtClaimGuardsAnOverlappingTick(): void
    {
        $instance = $this->buildDrawnCompetition();
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $round1->setMatchesResolveAt(new \DateTimeImmutable('-1 second'));
        $round1->setResolveLockedAt(new \DateTimeImmutable());
        $this->em->flush();

        $published = $this->resultsService->publishDueResults(new \DateTimeImmutable());
        $this->assertSame(0, $published);

        $this->em->refresh($round1);
        $this->assertSame(CompetitionRoundStatus::DRAWN, $round1->getStatus(), 'Still drawn — the claim was rejected, not the publish itself skipped.');
    }

    public function testForceResolveFixtureRejectsAnAlreadyResolvedFixture(): void
    {
        $instance = $this->buildDrawnCompetition();
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $fixture  = $this->fixtureRepository->findByRoundOrderedBySlot($round1)[0];

        $this->resultsService->forceResolveFixture($fixture);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This fixture has already been resolved.');
        $this->resultsService->forceResolveFixture($fixture);
    }

    public function testForceResolveFixtureRejectsAFixtureWithNoOpposingEntrant(): void
    {
        $instance = $this->buildDrawnCompetition();
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $fixture  = $this->fixtureRepository->findByRoundOrderedBySlot($round1)[0];
        $fixture->setAwayEntrant(null);
        $this->em->flush();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nothing to simulate');
        $this->resultsService->forceResolveFixture($fixture);
    }

    public function testForceResolveFixtureRejectsADrawPendingRound(): void
    {
        $template = new CompetitionTemplate('Results Cup', 'results-cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);
        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);
        for ($i = 1; $i <= 4; $i++) {
            $club    = $this->createClub("Club {$i}");
            $entrant = $this->makeEntrantFromEnvelope($this->em, $instance, $club, 0, ['club' => ['id' => (string) $club->getId(), 'name' => "Club {$i}"], 'players' => []]);
        }
        $this->em->flush();
        self::getContainer()->get(CompetitionLockService::class)->lock($instance);
        $this->em->flush();

        $round1  = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $fixture = new \App\Entity\Competition\CompetitionFixture($round1, 0, null, null);
        // A DRAW_PENDING round has no fixtures in practice — construct one directly to
        // exercise the guard rather than relying on an unreachable natural state.
        $this->em->persist($fixture);
        $entrants = self::getContainer()->get(\App\Repository\Competition\CompetitionEntrantRepository::class)
            ->findByCompetitionOrderedByRegistration($instance);
        $fixture->setHomeEntrant($entrants[0]);
        $fixture->setAwayEntrant($entrants[1]);
        $this->em->flush();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This round is no longer processable.');
        $this->resultsService->forceResolveFixture($fixture);
    }

    /**
     * A knockout fixture must never advance a bracket while looking like a draw — a tied
     * fixture must be settled by extra time and/or a penalty shootout, never silently.
     * Equal ability (10 vs 10 for every entrant) makes level scores common, so looping over
     * independent competitions reliably surfaces both outcomes without mocking the engine —
     * same idiom as the pre-split CompetitionRoundProcessorServiceTest this replaces.
     */
    public function testATiedFixtureIsResolvedByExtraTimeAndOrPenaltiesNotLeftAsADraw(): void
    {
        $foundExtraTime = false;
        $foundPenalties = false;

        for ($i = 0; $i < 60 && !($foundExtraTime && $foundPenalties); $i++) {
            $instance = $this->buildDrawnCompetition(capacity: 4);
            $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
            $fixtures = $this->fixtureRepository->findByRoundOrderedBySlot($round1);

            foreach ($fixtures as $fixture) {
                $result = $this->resultsService->forceResolveFixture($fixture);
                $this->em->refresh($fixture);

                $this->assertSame(CompetitionFixtureStatus::COMPLETE, $fixture->getStatus());
                $this->assertNotNull($fixture->getWinnerEntrant(), 'Every fixture must produce a decisive winner, even when the scoreline stays level.');

                if ($result->isWentToExtraTime()) {
                    $foundExtraTime = true;
                }

                if ($result->isWentToPenalties()) {
                    $foundPenalties = true;
                    $this->assertTrue($result->isWentToExtraTime(), 'Penalties can only follow extra time.');
                    $this->assertSame($result->getHomeScore(), $result->getAwayScore(), 'The recorded score must stay the true (level) AET score even when penalties decide the tie.');
                    $this->assertNotNull($result->getPenaltyHomeScore());
                    $this->assertNotNull($result->getPenaltyAwayScore());
                    $this->assertNotSame($result->getPenaltyHomeScore(), $result->getPenaltyAwayScore(), 'A penalty shootout must always produce a decisive tally.');
                }
            }
        }

        $this->assertTrue($foundExtraTime, 'Expected at least one fixture to go to extra time across 60 competitions.');
        $this->assertTrue($foundPenalties, 'Expected at least one fixture to go to penalties across 60 competitions.');
    }

    public function testPersistedResultStoresComputationAuditJsonAndDoesNotLeakItToClientSummary(): void
    {
        $instance = $this->buildDrawnCompetition(capacity: 4);
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $fixture  = $this->fixtureRepository->findByRoundOrderedBySlot($round1)[0];

        $result = $this->resultsService->forceResolveFixture($fixture);

        // The audit is its own row now, reached through its repository rather than the
        // result — CompetitionResult holds no association to it on purpose, because a
        // OneToOne inverse is always fetched eagerly and would put admin-only forensics back
        // on every public read path.
        $auditRow = self::getContainer()->get(CompetitionResultAuditRepository::class)->findForResult($result);
        $this->assertNotNull($auditRow, 'resolving a fixture must store its derivation audit');
        $audit = $auditRow->getComputationAuditJson();

        $this->assertIsArray($audit);
        $this->assertSame(1, $audit['schemaVersion']);
        $this->assertSame('deterministic', $audit['engine']['requestedEngine']);
        $this->assertSame('deterministic', $audit['engine']['resolvedBy']);
        $this->assertSame(\App\Service\MatchEngine\DeterministicEngine::class, $audit['engine']['engineClass']);
        $this->assertSame((string) $instance->getId(), $audit['round']['competitionId']);
        $this->assertSame((string) $round1->getId(), $audit['round']['roundId']);
        $this->assertSame(1, $audit['round']['roundIndex']);
        $this->assertArrayHasKey('dominance', $audit);
        $this->assertArrayHasKey('regulation', $audit);
        $this->assertArrayHasKey('playerRatings', $audit);
        $this->assertSame((string) $fixture->getWinnerEntrant()?->getId(), $audit['outcome']['winnerEntrantId']);
        $this->assertContains($audit['outcome']['winnerSide'], ['home', 'away']);
        $this->assertContains($audit['outcome']['resolutionMethod'], ['regulation', 'extra_time', 'penalties']);

        // Admin accessors expose the audit, while client summary keeps it internal
        $this->assertNotNull($auditRow->getComputationAuditJsonPretty());
        $this->assertNotEmpty($auditRow->getEventLogJson(), 'the event log moved to the audit row with it');
        $fullPayload = json_decode($result->getFullPayloadPretty($auditRow), true);
        $this->assertIsArray($fullPayload['computationAuditJson'] ?? null);
        // Asserted against the payload the client now actually receives. This used to check
        // CompetitionResult::toClientSummary(), which was the bracket's per-fixture `result`
        // block; the bracket no longer carries one, so the client-facing shape for a played
        // fixture is the match detail endpoint's.
        $clientPayload = self::getContainer()->get(MatchQueryService::class)->serializeCupMatchDetail($fixture);
        $this->assertArrayNotHasKey('computationAudit', $clientPayload);
        $this->assertArrayNotHasKey('computationAuditJson', $clientPayload);
        $this->assertArrayNotHasKey('eventLogJson', $clientPayload);
    }

    /**
     * A played fixture's client payload must build with no audit row present at all.
     *
     * This is the invariant the audit split protects, and the only way to prove it: an
     * assertion that `computationAudit` is absent from the payload passes whether or not the
     * forensics were decoded to produce it. Deleting the row and then serializing is what
     * actually shows no public path reaches for them.
     */
    public function testAClientSummaryBuildsWithNoAuditRow(): void
    {
        $instance = $this->buildDrawnCompetition(capacity: 4);
        $round1   = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $fixture  = $this->fixtureRepository->findByRoundOrderedBySlot($round1)[0];

        $result = $this->resultsService->forceResolveFixture($fixture);

        $this->em->getConnection()->executeStatement(
            'DELETE FROM competition_result_audit WHERE result_id = :id',
            ['id' => $result->getId()->toRfc4122()],
        );
        $this->em->clear();

        $result = $this->em->getRepository(\App\Entity\Competition\CompetitionResult::class)->find($result->getId());
        $this->assertNotNull($result);

        $summary = self::getContainer()->get(MatchQueryService::class)->serializeCupMatchDetail($fixture);
        $this->assertSame($result->getHomeScore(), $summary['score']['home']);
        // Counted against the stored lineup rather than a fixed number: this fixture seeds a
        // minimal squad, and the point is that the lineup survives the audit row's absence,
        // not how big it is.
        $this->assertCount(count($result->getHomeLineupJson()), $summary['homeLineup']);
        $this->assertNotEmpty($summary['homeLineup']);
        $this->assertArrayNotHasKey('eventLogJson', $summary);
        $this->assertArrayNotHasKey('computationAuditJson', $summary);
    }
}
