<?php

declare(strict_types=1);

namespace App\Tests\Service\Leaderboard;

use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\User;
use App\Enum\LeaderboardCategory;
use App\Service\Leaderboard\LeaderboardPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// migrations/ is outside composer's PSR-4 map (Doctrine loads those files itself), so the
// class has to be pulled in explicitly to read the SQL constant off it.
require_once dirname(__DIR__, 3) . '/migrations/Version20261031010000.php';

/**
 * The growth migration's one-off reset of in-flight period boards, exercised against the
 * SQL the migration actually ships (\DoctrineMigrations\Version20261031010000::RESET_IN_FLIGHT_PERIODS_SQL).
 *
 * This discontinuity is deliberate — every period row already underway is declared to
 * start measuring growth at its current cumulative, because the information a real
 * baseline would need was never recorded. It is tested here so it is a decision rather
 * than something discovered in production: without it those rows would sit at a stale
 * cumulative score with a NULL baseline, and assignRanks() would rank the whole board on
 * that stale standing in the meantime.
 *
 * wunderkind_test is built by doctrine:schema:create, so the statement never runs there of
 * its own accord — the test applies it explicitly to rows it created.
 */
class LeaderboardGrowthResetTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var object[] */
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
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    public function testTheResetZeroesPeriodRowsAndLeavesAllTimeAlone(): void
    {
        $club    = $this->persistClub();
        $weekly  = $this->persistPreMigrationEntry($club, '2026-W44', 1_200);
        $monthly = $this->persistPreMigrationEntry($club, '2026-M10', 1_200);
        $allTime = $this->persistPreMigrationEntry($club, LeaderboardPeriod::ALL_TIME, 1_200);
        $this->em->flush();

        $this->em->getConnection()->executeStatement(\DoctrineMigrations\Version20261031010000::RESET_IN_FLIGHT_PERIODS_SQL);
        $this->em->clear();

        foreach ([$weekly, $monthly] as $before) {
            $after = $this->em->find(LeaderboardEntry::class, $before->getId());

            self::assertNotNull($after);
            self::assertSame(0, $after->getScore(), 'an in-flight period row resets to zero growth');
            self::assertSame(1_200, $after->getBaselineScore());
            self::assertSame(1_200, $after->getCumulativeScore());
            self::assertSame(
                $after->getBaselineScore(),
                $after->getCumulativeScore(),
                'baseline_score = cumulative_score immediately after the reset',
            );
        }

        $allTimeAfter = $this->em->find(LeaderboardEntry::class, $allTime->getId());

        self::assertNotNull($allTimeAfter);
        self::assertSame(1_200, $allTimeAfter->getScore(), "'all-time' is a standing and is untouched");
        self::assertNull($allTimeAfter->getBaselineScore());
        self::assertNull($allTimeAfter->getCumulativeScore());
    }

    /**
     * The reset leaves each row in exactly the state observeCumulative() would have left
     * it in on a first sighting — so the very next observation scores a true delta.
     */
    public function testAfterTheResetTheNextObservationScoresATrueDelta(): void
    {
        $club  = $this->persistClub();
        $entry = $this->persistPreMigrationEntry($club, '2026-M10', 1_200);
        $this->em->flush();

        $this->em->getConnection()->executeStatement(\DoctrineMigrations\Version20261031010000::RESET_IN_FLIGHT_PERIODS_SQL);
        $this->em->clear();

        $reloaded = $this->em->find(LeaderboardEntry::class, $entry->getId());
        self::assertNotNull($reloaded);

        $reloaded->observeCumulative(1_250);

        self::assertSame(50, $reloaded->getScore());
    }

    private function persistClub(): Club
    {
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@reset.test'));
        $user->setPassword('x');

        $club = $this->persist(new Club('Reset FC', $user));
        $club->setCurrentSeason(3);

        return $club;
    }

    /** A row as it looked before the migration: an absolute cumulative in `score`, both new columns NULL. */
    private function persistPreMigrationEntry(Club $club, string $period, int $cumulative): LeaderboardEntry
    {
        $entry = $this->persist(new LeaderboardEntry($club, LeaderboardCategory::CLUB_GOALS, $period));
        $entry->setScore($cumulative);

        return $entry;
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }
}
