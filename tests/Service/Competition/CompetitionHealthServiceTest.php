<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\Competition\CompetitionRound;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionHealthService;
use App\Service\Competition\CompetitionLockService;
use App\Tests\Support\EntrantFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every check in CompetitionHealthService, driven from state deliberately made unhealthy.
 *
 * Written this way because the opposite is worthless: on an empty or healthy database the
 * service returns zeros and `healthy: true` whether or not a single query is correct, so a
 * test that only asserts "it ran and said fine" would pass just as happily with every
 * predicate inverted. Each case here seeds the specific condition, asserts it is detected,
 * and — where the distinction is the whole point — asserts a near-miss is *not* detected.
 */
class CompetitionHealthServiceTest extends KernelTestCase
{
    use EntrantFixtureTrait;

    private EntityManagerInterface $em;
    private CompetitionHealthService $health;
    private CompetitionRoundRepository $roundRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em              = self::getContainer()->get(EntityManagerInterface::class);
        $this->health          = self::getContainer()->get(CompetitionHealthService::class);
        $this->roundRepository = self::getContainer()->get(CompetitionRoundRepository::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_entrant_squad, competition_fixture,
                      competition_result, competition_result_audit, competition_round,
                      competition_response_cache, active_competition, competition_template,
                      entrant_reward_claim CASCADE',
        );
        // The Doctrine transport creates this lazily on first dispatch, so wunderkind_test
        // (built by schema:create, which only knows entities) has no such table until
        // something queues a message. Created here so the queue assertions below run against
        // the real table and the real SQL rather than against a stub — the absent-table path
        // has its own case.
        $this->em->getConnection()->executeStatement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS messenger_messages (
                id BIGSERIAL PRIMARY KEY,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
            )
            SQL,
        );
        $this->em->getConnection()->executeStatement(
            'DELETE FROM messenger_messages WHERE queue_name IN (:queues)',
            ['queues' => [CompetitionHealthService::TRANSPORT_QUEUE, 'failed']],
            ['queues' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    private function lockedCompetition(int $capacity = 4): ActiveCompetition
    {
        $template = new CompetitionTemplate('Health Cup', 'health-cup-' . uniqid('', true), $capacity, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);
        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);

        for ($i = 1; $i <= $capacity; $i++) {
            $user = new User(sprintf('health-%s@example.com', uniqid('', true)));
            $user->setPassword('x');
            $club = new Club("Health Club {$i}", $user);
            $this->em->persist($user);
            $this->em->persist($club);
            $this->makeEntrantFromEnvelope($this->em, $instance, $club, 0, [
                'club'    => ['id' => (string) $club->getId(), 'name' => "Health Club {$i}"],
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

    /** Writes a UTC timestamp straight onto a round's column, bypassing the ORM. */
    private function setRoundColumn(CompetitionRound $round, string $column, ?string $modify): void
    {
        $this->em->getConnection()->executeStatement(
            sprintf('UPDATE competition_round SET %s = :value WHERE id = :id', $column),
            [
                'value' => $modify === null ? null : (new \DateTimeImmutable($modify, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'id'    => $round->getId()->toRfc4122(),
            ],
        );
        $this->em->refresh($round);
    }

    private function queueMessage(string $queue, string $availableAtModify): void
    {
        $at = (new \DateTimeImmutable($availableAtModify, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            <<<'SQL'
            INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
            VALUES ('{}', '{}', :queue, :at, :at, NULL)
            SQL,
            ['queue' => $queue, 'at' => $at],
        );
    }

    public function testAQuietPipelineIsReportedHealthy(): void
    {
        $snapshot = $this->health->snapshot();

        self::assertTrue($snapshot['healthy']);
        self::assertSame([], $snapshot['warnings']);
        self::assertSame(0, $snapshot['queue']['pending']);
        self::assertNull($snapshot['queue']['oldestPendingSeconds']);
    }

    public function testAClaimPastItsLeaseIsReportedStaleAndAFreshOneIsNot(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);

        // Just inside the lease: held, not stale. This half is the one that matters — a
        // predicate that flagged every held claim would look identical on the other case.
        $this->setRoundColumn($round, 'draw_locked_at', sprintf('-%d seconds', CompetitionRound::CLAIM_LEASE_SECONDS - 30));
        $snapshot = $this->health->snapshot();
        self::assertSame(1, $snapshot['rounds']['claimed']);
        self::assertSame(0, $snapshot['rounds']['staleClaims'], 'a claim inside its lease is working, not stale');

        // Past the lease: reclaimable, so a consumer died holding it.
        $this->setRoundColumn($round, 'draw_locked_at', sprintf('-%d seconds', CompetitionRound::CLAIM_LEASE_SECONDS + 30));
        $snapshot = $this->health->snapshot();
        self::assertSame(1, $snapshot['rounds']['staleClaims']);
        self::assertFalse($snapshot['healthy']);
        self::assertStringContainsString('past the', $snapshot['warnings'][0]);
    }

    public function testADueUnclaimedRoundIsOverdueOnlyOnceItIsPastTheGrace(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);
        $this->setRoundColumn($round, 'draw_locked_at', null);

        // Due, but within the grace that absorbs a skipped cron tick.
        $this->setRoundColumn($round, 'scheduled_at', sprintf('-%d seconds', CompetitionHealthService::OVERDUE_GRACE_SECONDS - 60));
        self::assertSame(0, $this->health->snapshot()['rounds']['overdueDraw'], 'a round between ticks is not behind');

        $this->setRoundColumn($round, 'scheduled_at', sprintf('-%d seconds', CompetitionHealthService::OVERDUE_GRACE_SECONDS + 120));
        $snapshot = $this->health->snapshot();

        self::assertSame(1, $snapshot['rounds']['overdueDraw']);
        self::assertGreaterThan(CompetitionHealthService::OVERDUE_GRACE_SECONDS, $snapshot['rounds']['worstOverdueSeconds']);
        self::assertFalse($snapshot['healthy']);
    }

    /**
     * A claimed round is never overdue, however late it is — it is being worked on. Without
     * this the two counts would double-report the same round and an operator would chase a
     * backlog that is one round in progress.
     */
    public function testAClaimedRoundIsNotCountedOverdue(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);

        $this->setRoundColumn($round, 'scheduled_at', '-1 hour');
        $this->setRoundColumn($round, 'draw_locked_at', '-10 seconds');

        $snapshot = $this->health->snapshot();
        self::assertSame(0, $snapshot['rounds']['overdueDraw']);
        self::assertSame(1, $snapshot['rounds']['claimed']);
    }

    /**
     * A completed or cancelled competition's rounds are not due for anything. Counting them
     * would make the overdue figure climb forever instead of describing the present.
     */
    public function testRoundsOfACompletedCompetitionAreIgnored(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);
        $this->setRoundColumn($round, 'draw_locked_at', null);
        $this->setRoundColumn($round, 'scheduled_at', '-1 hour');

        self::assertSame(1, $this->health->snapshot()['rounds']['overdueDraw']);

        $this->em->getConnection()->executeStatement(
            'UPDATE active_competition SET status = :status WHERE id = :id',
            ['status' => ActiveCompetitionStatus::COMPLETED->value, 'id' => $instance->getId()->toRfc4122()],
        );

        $snapshot = $this->health->snapshot();
        self::assertSame(0, $snapshot['rounds']['overdueDraw'], 'a finished competition has nothing due');
        self::assertTrue($snapshot['healthy']);
    }

    public function testQueueLagIsMeasuredFromTheOldestUndrainedMessage(): void
    {
        $this->queueMessage(CompetitionHealthService::TRANSPORT_QUEUE, '-30 seconds');
        $snapshot = $this->health->snapshot();

        self::assertSame(1, $snapshot['queue']['pending']);
        self::assertLessThanOrEqual(CompetitionHealthService::QUEUE_LAG_WARN_SECONDS, $snapshot['queue']['oldestPendingSeconds']);
        self::assertTrue($snapshot['healthy'], 'a message queued this tick is not lag');

        $this->queueMessage(CompetitionHealthService::TRANSPORT_QUEUE, '-15 minutes');
        $snapshot = $this->health->snapshot();

        self::assertSame(2, $snapshot['queue']['pending']);
        self::assertGreaterThan(CompetitionHealthService::QUEUE_LAG_WARN_SECONDS, $snapshot['queue']['oldestPendingSeconds']);
        self::assertFalse($snapshot['healthy']);
    }

    /**
     * A message whose available_at is in the future is a scheduled retry, not a backlog.
     * Reporting it as lag would make every failed-and-retrying message look like a stalled
     * consumer.
     */
    public function testAMessageNotYetAvailableIsNotCountedAsBacklog(): void
    {
        $this->queueMessage(CompetitionHealthService::TRANSPORT_QUEUE, '+10 minutes');

        $snapshot = $this->health->snapshot();
        self::assertSame(0, $snapshot['queue']['pending']);
        self::assertTrue($snapshot['healthy']);
    }

    public function testFailedMessagesAreSurfaced(): void
    {
        $this->queueMessage('failed', '-1 minute');

        $snapshot = $this->health->snapshot();
        self::assertSame(1, $snapshot['queue']['failed']);
        self::assertFalse($snapshot['healthy']);
    }

    /**
     * Cache coverage is reported, never warned about: the cache is built on demand and
     * self-validating, so an open competition without a row is normal.
     */
    public function testAnOpenCompetitionWithoutACacheRowIsReportedButNotAWarning(): void
    {
        $this->lockedCompetition();

        $snapshot = $this->health->snapshot();

        self::assertSame(1, $snapshot['cache']['openCompetitions']);
        self::assertSame(1, $snapshot['cache']['openWithoutRow']);
        self::assertSame(0, $snapshot['cache']['rows']);
        self::assertTrue($snapshot['healthy'], 'an unbuilt cache is a miss, not a fault');
    }

    /**
     * A health check must not be the thing that fails.
     *
     * `messenger_messages` is created lazily by the Doctrine transport, so on a fresh
     * database — or a new deploy before the first cron tick dispatches anything — it does
     * not exist. Querying it there raises "relation does not exist", which is exactly how
     * this was found: the first run of this suite errored on all nine cases. The absence is
     * reported rather than swallowed into a zero, because "no table" and "nothing queued"
     * are different facts to whoever is reading the output.
     */
    public function testAnAbsentMessengerTableIsReportedRatherThanThrowing(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS messenger_messages');

        $snapshot = $this->health->snapshot();

        self::assertArrayHasKey('unavailable', $snapshot['queue']);
        self::assertStringContainsString('does not exist', $snapshot['queue']['unavailable']);
        self::assertSame(0, $snapshot['queue']['pending']);
        self::assertTrue($snapshot['healthy'], 'a database that has never dispatched a message is not unhealthy');
    }
}
