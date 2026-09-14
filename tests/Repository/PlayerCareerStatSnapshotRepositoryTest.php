<?php

namespace App\Tests\Repository;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\PlayerCareerStatSnapshot;
use App\Entity\SeasonRecord;
use App\Entity\User;
use App\Enum\StatsPeriod;
use App\Repository\PlayerCareerStatSnapshotRepository;
use App\Service\PeriodResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PlayerCareerStatSnapshotRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PeriodResolver $resolver;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = self::getContainer()->get(PeriodResolver::class);
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

    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }

    private function club(string $name): Club
    {
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@snapshot-repo.test'));
        $user->setPassword('x');

        return $this->persist(new Club($name, $user));
    }

    private function snapshot(Club $club, string $playerId, string $playerName, int $goals, \DateTimeImmutable $recordedAt): PlayerCareerStatSnapshot
    {
        return $this->persist(new PlayerCareerStatSnapshot($club, $playerId, $playerName, 1, $goals, 0, $recordedAt));
    }

    private function repo(): PlayerCareerStatSnapshotRepository
    {
        return self::getContainer()->get(PlayerCareerStatSnapshotRepository::class);
    }

    public function testTopGoalScorerInWindowProjectsClubNameAndPlayerName(): void
    {
        $club = $this->club('Scorer Club');
        $now = new \DateTimeImmutable();
        $this->snapshot($club, 'p1', 'Striker One', 3, $now->modify('-2 days'));
        $this->snapshot($club, 'p1', 'Striker One', 7, $now->modify('-1 day'));

        $this->em->flush();

        $results = $this->repo()->topGoalScorerInWindowByClub(StatsPeriod::WEEK, 10, $this->resolver);
        $match = array_values(array_filter($results, static fn (array $r) => $r['clubId'] === (string) $club->getId()));

        $this->assertCount(1, $match);
        $this->assertSame('Scorer Club', $match[0]['clubName']);
        $this->assertSame('Striker One', $match[0]['playerName']);
        // First snapshot has no predecessor at all (not even outside the window) -> its
        // full value (3) counts as the baseline delta. Second snapshot's real delta is
        // 7 - 3 = 4. Both fall inside the window, so both are summed: 3 + 4 = 7.
        $this->assertSame(7, $match[0]['value']);
    }

    public function testResetBeforeWindowDoesNotLeakAStaleDelta(): void
    {
        $club = $this->club('Pre-Window Reset Club');
        $now = new \DateTimeImmutable();

        // Both outside the 7-day WEEK window.
        $this->snapshot($club, 'p1', 'Vet Striker', 5, $now->modify('-10 days'));
        $this->snapshot($club, 'p1', 'Vet Striker', 1, $now->modify('-8 days')); // season reset, still pre-window

        // Inside the window.
        $this->snapshot($club, 'p1', 'Vet Striker', 6, $now->modify('-2 days'));

        $this->em->flush();

        $results = $this->repo()->topGoalScorerInWindowByClub(StatsPeriod::WEEK, 10, $this->resolver);
        $match = array_values(array_filter($results, static fn (array $r) => $r['clubId'] === (string) $club->getId()));

        $this->assertCount(1, $match);
        // Correct: 6 - 1 (the pre-window reset baseline) = 5. A buggy implementation
        // that filtered history to the window BEFORE running LAG() would lose the
        // pre-window baseline entirely and wrongly treat the in-window row as having
        // no predecessor, reporting 6 (the raw value) instead of the true delta.
        $this->assertSame(5, $match[0]['value']);
    }

    public function testResetInsideWindowIsCountedAsFreshActivity(): void
    {
        $club = $this->club('Mid-Window Reset Club');
        $now = new \DateTimeImmutable();

        $this->snapshot($club, 'p1', 'Streaky Striker', 8, $now->modify('-5 days'));
        $this->snapshot($club, 'p1', 'Streaky Striker', 3, $now->modify('-2 days')); // reset inside the window

        $this->em->flush();

        $results = $this->repo()->topGoalScorerInWindowByClub(StatsPeriod::WEEK, 10, $this->resolver);
        $match = array_values(array_filter($results, static fn (array $r) => $r['clubId'] === (string) $club->getId()));

        $this->assertCount(1, $match);
        // First snapshot has no predecessor at all -> delta = 8. Second snapshot resets
        // (3 < 8) -> counted as fresh activity, delta = 3. Total = 11.
        $this->assertSame(11, $match[0]['value']);
    }

    public function testBestPlayerPerClubCollapsesAndRespectsLimit(): void
    {
        $club = $this->club('Two Strikers Club');
        $now = new \DateTimeImmutable();

        $this->snapshot($club, 'p1', 'Top Scorer', 5, $now->modify('-1 day'));
        $this->snapshot($club, 'p2', 'Backup Striker', 2, $now->modify('-1 day'));

        $this->em->flush();

        $results = $this->repo()->topGoalScorerInWindowByClub(StatsPeriod::WEEK, 10, $this->resolver);
        $match = array_values(array_filter($results, static fn (array $r) => $r['clubId'] === (string) $club->getId()));

        $this->assertCount(1, $match, 'only the best player per club should appear');
        $this->assertSame('Top Scorer', $match[0]['playerName']);
        $this->assertSame(5, $match[0]['value']);
    }

    public function testSeasonPeriodRespectsPerClubBound(): void
    {
        $club = $this->club('Season Bound Club');
        $league = $this->persist(new League(strtolower(bin2hex(random_bytes(1))), 8, 'Test League'));

        // SeasonRecord::createdAt is always "now" (not settable via the constructor) —
        // so the season boundary for this test IS this row's creation instant.
        $seasonRecord = $this->persist(new SeasonRecord($club, $league, 1, 4, 10, 5, 3, 2, 12, 8, 18, false, false));
        $this->em->flush();
        $boundary = $seasonRecord->getCreatedAt();

        // Before the season boundary — must not count toward the SEASON-period total.
        $this->snapshot($club, 'p1', 'Season Striker', 9, $boundary->modify('-5 days'));
        // After the season boundary — must count.
        $this->snapshot($club, 'p1', 'Season Striker', 2, $boundary->modify('+1 second'));

        $this->em->flush();

        $results = $this->repo()->topGoalScorerInWindowByClub(StatsPeriod::SEASON, 10, $this->resolver);
        $match = array_values(array_filter($results, static fn (array $r) => $r['clubId'] === (string) $club->getId()));

        $this->assertCount(1, $match);
        // The pre-boundary snapshot (goals=9) is excluded entirely from the sum, so the
        // post-boundary snapshot's reset-aware delta (2 < 9 -> counted as fresh) is the
        // only contribution: 2.
        $this->assertSame(2, $match[0]['value']);
    }
}
