<?php

namespace App\Tests\Repository;

use App\Entity\Club;
use App\Entity\MatchResult;
use App\Entity\User;
use App\Enum\StatsPeriod;
use App\Repository\MatchResultRepository;
use App\Service\PeriodResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class MatchResultRepositoryTest extends KernelTestCase
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
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@match-result.test'));
        $user->setPassword('x');

        return $this->persist(new Club($name, $user));
    }

    private function match(Club $club, int $goalsFor, int $goalsAgainst): MatchResult
    {
        return $this->persist(new MatchResult($club, $goalsFor, $goalsAgainst, 1, 1));
    }

    private function repo(): MatchResultRepository
    {
        return self::getContainer()->get(MatchResultRepository::class);
    }

    public function testGetMostWinsByClubTiebreaksOnGoalDifference(): void
    {
        $narrow = $this->club('Narrow Wins FC');
        $this->match($narrow, 1, 0);
        $this->match($narrow, 2, 1);

        $wide = $this->club('Wide Wins FC');
        $this->match($wide, 5, 0);
        $this->match($wide, 4, 0);

        $this->em->flush();

        $results = $this->repo()->getMostWinsByClub(StatsPeriod::ALL, 10, $this->resolver);
        $ids = array_map(static fn (array $r) => (string) $r['clubId'], $results);

        $narrowIndex = array_search((string) $narrow->getId(), $ids, true);
        $wideIndex = array_search((string) $wide->getId(), $ids, true);

        $this->assertNotFalse($narrowIndex);
        $this->assertNotFalse($wideIndex);
        // Both clubs have 2 wins — wide's +9 goal difference must rank it above narrow's +2.
        $this->assertLessThan($narrowIndex, $wideIndex);

        foreach ($results as $row) {
            if ((string) $row['clubId'] === (string) $wide->getId()) {
                $this->assertSame(2, $row['value']);
                $this->assertSame(9, $row['secondaryValue']);
            }
        }
    }

    public function testGetBiggestRoutByClubExcludesNonWins(): void
    {
        $loser = $this->club('Loser FC');
        $this->match($loser, 0, 5);
        $this->match($loser, 1, 1);

        $this->em->flush();

        $results = $this->repo()->getBiggestRoutByClub(StatsPeriod::ALL, 10, $this->resolver);
        $ids = array_map(static fn (array $r) => (string) $r['clubId'], $results);

        $this->assertNotContains((string) $loser->getId(), $ids, 'a club with no wins in the window must not appear');
    }

    public function testGetBiggestRoutByClubRanksByMargin(): void
    {
        $rout = $this->club('Rout FC');
        $this->match($rout, 6, 1);

        $this->em->flush();

        $results = $this->repo()->getBiggestRoutByClub(StatsPeriod::ALL, 10, $this->resolver);
        $match = array_values(array_filter($results, static fn (array $r) => (string) $r['clubId'] === (string) $rout->getId()));

        $this->assertCount(1, $match);
        $this->assertSame(5, $match[0]['value']);
    }

    public function testGetBestDefenceByClubExcludesClubsBelowMinimumMatches(): void
    {
        $luckyOnce = $this->club('Lucky Once FC');
        $this->match($luckyOnce, 3, 0); // single clean sheet, below the minimum

        $solid = $this->club('Solid Defence FC');
        $this->match($solid, 2, 1);
        $this->match($solid, 1, 1);
        $this->match($solid, 3, 1);

        $this->em->flush();

        $results = $this->repo()->getBestDefenceByClub(StatsPeriod::ALL, 10, $this->resolver);
        $ids = array_map(static fn (array $r) => (string) $r['clubId'], $results);

        $this->assertNotContains((string) $luckyOnce->getId(), $ids, 'a single clean sheet must not qualify for the fortress-defence board');
        $this->assertContains((string) $solid->getId(), $ids);
    }

    public function testGetBestDefenceByClubOrdersAscending(): void
    {
        $tight = $this->club('Tight FC');
        $this->match($tight, 1, 0);
        $this->match($tight, 1, 0);
        $this->match($tight, 1, 0);

        $leaky = $this->club('Leaky FC');
        $this->match($leaky, 1, 3);
        $this->match($leaky, 1, 3);
        $this->match($leaky, 1, 3);

        $this->em->flush();

        $results = $this->repo()->getBestDefenceByClub(StatsPeriod::ALL, 10, $this->resolver);
        $ids = array_map(static fn (array $r) => (string) $r['clubId'], $results);

        $tightIndex = array_search((string) $tight->getId(), $ids, true);
        $leakyIndex = array_search((string) $leaky->getId(), $ids, true);

        $this->assertNotFalse($tightIndex);
        $this->assertNotFalse($leakyIndex);
        $this->assertLessThan($leakyIndex, $tightIndex, 'lower average goals conceded must rank first (ascending)');
    }
}
