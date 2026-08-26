<?php

namespace App\Tests\Repository;

use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\User;
use App\Enum\LeaderboardCategory;
use App\Repository\LeaderboardEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Leaderboard reads must only surface clubs that have concluded at least one full
 * season (Club::$currentSeason > 1). A club still on its first season has no
 * completed body of work to compare, so it shouldn't appear on public boards.
 */
class LeaderboardEntryRepositoryTest extends KernelTestCase
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

    public function testFindAllOrderedByScoreExcludesClubsThatHaveNotConcludedASeason(): void
    {
        $rookie   = $this->persistClub('Rookie FC', currentSeason: 1);
        $veteran  = $this->persistClub('Veteran FC', currentSeason: 2);

        $this->persistEntry($rookie, 500);
        $this->persistEntry($veteran, 100);
        $this->em->flush();

        /** @var LeaderboardEntryRepository $repo */
        $repo = self::getContainer()->get(LeaderboardEntryRepository::class);
        $results = $repo->findAllOrderedByScore(LeaderboardCategory::CLUB_REPUTATION, 'all-time');

        $clubIds = array_map(static fn (LeaderboardEntry $e) => (string) $e->getClub()->getId(), $results);

        $this->assertNotContains((string) $rookie->getId(), $clubIds, 'a club on its first season must not appear');
        $this->assertContains((string) $veteran->getId(), $clubIds, 'a club that has concluded a season must appear');
    }

    private function persistClub(string $name, int $currentSeason): Club
    {
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@lb.test'));
        $user->setPassword('x');

        $club = $this->persist(new Club($name, $user));
        $club->setCurrentSeason($currentSeason);

        return $club;
    }

    private function persistEntry(Club $club, int $score): LeaderboardEntry
    {
        $entry = $this->persist(new LeaderboardEntry($club, LeaderboardCategory::CLUB_REPUTATION, 'all-time'));
        $entry->setScore($score);

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
