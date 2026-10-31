<?php

declare(strict_types=1);

namespace App\Tests\Service\Leaderboard;

use App\Entity\LeaderboardEntry;
use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\LeaderboardCategory;
use App\Service\Leaderboard\LeaderboardPeriod;
use App\Service\LeaderboardCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A public GET must never be able to WRITE leaderboard rows.
 *
 * LeaderboardCalculationService::getLeaderboard()'s cold-cache callback calls
 * recalculate(), and computeAggregateScores() will happily upsert rows for any period
 * string at all — so without a guard, one anonymous request with an arbitrary ?period=
 * mints a cache key and populates a whole junk board. The same mechanism is what would
 * resurrect a period the close job has just pruned (see the closed-period cases below).
 */
class ClosedPeriodReadOnlyTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    public function testAnUnrecognisedPeriodReturnsTheSameEmptyTwoHundredAndWritesNothing(): void
    {
        $before = $this->entryCount();

        foreach (['nonsense', '2026-W99', '', 'all time', '../etc/passwd', '2026-M13'] as $period) {
            $this->client->request('GET', '/api/leaderboard/club_goals?period=' . urlencode($period));

            self::assertResponseIsSuccessful("?period={$period} must still be a 200");

            $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

            self::assertSame('club_goals', $payload['category']);
            self::assertSame($period, $payload['period'], 'the requested period is echoed back verbatim');
            self::assertSame([], $payload['entries']);
            self::assertSame(0, $payload['total']);
            self::assertFalse($payload['hasNextPage']);
            self::assertArrayHasKey('page', $payload);
            self::assertArrayHasKey('pageSize', $payload);
        }

        self::assertSame($before, $this->entryCount(), 'an unrecognised ?period= must not write a single row');
    }

    public function testARecognisedPeriodStillReachesTheService(): void
    {
        $this->client->request('GET', '/api/leaderboard/club_goals?period=all-time');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('all-time', $payload['period']);
        self::assertIsArray($payload['entries']);
    }

    public function testAnInvalidCategoryIsStillAFourHundred(): void
    {
        $this->client->request('GET', '/api/leaderboard/not_a_category');

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * THE case the guard exists for. The close job prunes a closed period's live rows, and
     * the cold-cache callback WRITES. Without the guard, one anonymous GET would refill the
     * pruned table with a near-empty board plus a junk row per club.
     */
    public function testAColdReadOfAClosedPeriodServesTheArchiveAndWritesNothing(): void
    {
        $period  = '2026-M09';
        $archive = $this->persistClosedArchive($period);
        $this->persistArchiveEntry($archive, 1, 'Frozen Rovers', 440, 'Frozen Striker');
        $this->persistArchiveEntry($archive, 2, 'Second Place FC', 120, null);
        $this->em->flush();

        $before = $this->entryCount();

        $this->client->request('GET', '/api/leaderboard/club_goals?period=' . $period);
        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(2, $payload['total'], 'the closed board is served from the archive');
        self::assertSame('Frozen Rovers', $payload['entries'][0]['clubName']);
        self::assertSame(440, $payload['entries'][0]['score']);
        self::assertSame(1, $payload['entries'][0]['rank']);
        self::assertSame('Frozen Striker', $payload['entries'][0]['displayLabel'], 'the frozen performer name is served, not a live one');
        self::assertSame('Second Place FC', $payload['entries'][1]['clubName']);

        self::assertSame($before, $this->entryCount(), 'a closed period must not be recomputed into fresh leaderboard_entry rows');
    }

    public function testRecalculateIsANoOpForAClosedPeriod(): void
    {
        $period = '2026-M08';
        $this->persistClosedArchive($period);
        $this->em->flush();

        $calculation = static::getContainer()->get(LeaderboardCalculationService::class);

        $before = $this->entryCount();

        self::assertTrue($calculation->isClosed(LeaderboardCategory::CLUB_GOALS, $period));
        self::assertSame(0, $calculation->recalculate(LeaderboardCategory::CLUB_GOALS, $period));
        self::assertSame($before, $this->entryCount(), 'neither the admin button nor the cron may resurrect a closed period');
    }

    /**
     * A PENDING archive means a close is in flight or failed part-way, and the live rows
     * have NOT been pruned — so the live board is still the truthful answer and must still
     * be computed.
     */
    public function testAPendingArchiveDoesNotMakeAPeriodReadOnly(): void
    {
        $period = '2026-M07';
        $this->persistPendingArchive($period);
        $this->em->flush();

        $calculation = static::getContainer()->get(LeaderboardCalculationService::class);

        self::assertFalse($calculation->isClosed(LeaderboardCategory::CLUB_GOALS, $period));
    }

    public function testAllTimeIsNeverClosed(): void
    {
        $calculation = static::getContainer()->get(LeaderboardCalculationService::class);

        self::assertFalse($calculation->isClosed(LeaderboardCategory::CLUB_GOALS, LeaderboardPeriod::ALL_TIME));
    }

    private function persistClosedArchive(string $period): LeaderboardPeriodArchive
    {
        $archive = $this->persistPendingArchive($period);
        $archive->markArchived(2, 2, 1, new \DateTimeImmutable());

        return $archive;
    }

    private function persistPendingArchive(string $period): LeaderboardPeriodArchive
    {
        $bounds = LeaderboardPeriod::boundsOf($period);
        self::assertNotNull($bounds);

        return $this->persist(new LeaderboardPeriodArchive(
            null,
            LeaderboardCategory::CLUB_GOALS,
            AwardCadence::MONTHLY,
            'Read-only Test Award',
            $period,
            $bounds[0],
            $bounds[1],
            null,
            new \DateTimeImmutable(),
        ));
    }

    private function persistArchiveEntry(
        LeaderboardPeriodArchive $archive,
        int $rank,
        string $clubName,
        int $score,
        ?string $displayLabel,
    ): LeaderboardPeriodArchiveEntry {
        return $this->persist(new LeaderboardPeriodArchiveEntry(
            $archive,
            $rank,
            'club-' . bin2hex(random_bytes(6)),
            $clubName,
            null,
            $score,
            $score,
            0,
            false,
            true,
            $displayLabel,
            null,
            null,
            null,
            'EN',
        ));
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }

    private function entryCount(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(LeaderboardEntry::class, 'e')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
