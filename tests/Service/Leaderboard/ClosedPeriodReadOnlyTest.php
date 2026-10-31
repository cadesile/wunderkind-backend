<?php

declare(strict_types=1);

namespace App\Tests\Service\Leaderboard;

use App\Entity\LeaderboardEntry;
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

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);
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

    private function entryCount(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(LeaderboardEntry::class, 'e')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
