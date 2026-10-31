<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\User;
use App\Enum\LeaderboardCategory;
use App\Repository\LeaderboardEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * THE record of who may appear on, and who may win, a leaderboard —
 * LeaderboardEntryRepository::applyEligibility().
 *
 * The spoof-club exclusion is a deliberate, visible behaviour change: spoof clubs
 * (CompetitionSpoofEntrantService) have been eligible for every public board since the
 * board existed, because only ClubRepository ever filtered them. Paying a bot a real cash
 * prize is the bug that forced the predicate, and once it is shared between the award path
 * and the public board, the two must agree. This test file is why that change is not an
 * accident, and the assertion that fails if someone "restores" the old behaviour.
 */
class LeaderboardEligibilityTest extends KernelTestCase
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

    public function testPublicBoardExcludesSpoofClubsHoweverHighTheyScore(): void
    {
        $period = 'test-' . bin2hex(random_bytes(4));

        $spoof = $this->persistClub('Spoof United', currentSeason: 9, isSpoof: true);
        $real  = $this->persistClub('Real Rovers', currentSeason: 9);

        // The spoof club outscores the real one by an order of magnitude — if it is
        // surfaced at all it lands at rank 1.
        $this->persistEntry($spoof, $period, 999_999);
        $this->persistEntry($real, $period, 10);
        $this->em->flush();

        $clubIds = $this->orderedClubIds($period);

        self::assertNotContains((string) $spoof->getId(), $clubIds, 'a spoof club must never appear on a public board');
        self::assertContains((string) $real->getId(), $clubIds);
    }

    public function testPublicBoardStillExcludesFirstSeasonClubs(): void
    {
        $period = 'test-' . bin2hex(random_bytes(4));

        $rookie  = $this->persistClub('Rookie FC', currentSeason: 1);
        $veteran = $this->persistClub('Veteran FC', currentSeason: 2);

        $this->persistEntry($rookie, $period, 500);
        $this->persistEntry($veteran, $period, 100);
        $this->em->flush();

        $clubIds = $this->orderedClubIds($period);

        self::assertNotContains((string) $rookie->getId(), $clubIds);
        self::assertContains((string) $veteran->getId(), $clubIds);
    }

    /**
     * Both predicates apply together — a spoof club that has also concluded seasons is
     * still out, and a first-season non-spoof club is still out.
     */
    public function testBothPredicatesApplyIndependently(): void
    {
        $period = 'test-' . bin2hex(random_bytes(4));

        $spoofRookie   = $this->persistClub('Spoof Rookie', currentSeason: 1, isSpoof: true);
        $spoofVeteran  = $this->persistClub('Spoof Veteran', currentSeason: 5, isSpoof: true);
        $realRookie    = $this->persistClub('Real Rookie', currentSeason: 1);
        $realVeteran   = $this->persistClub('Real Veteran', currentSeason: 5);

        foreach ([$spoofRookie, $spoofVeteran, $realRookie, $realVeteran] as $club) {
            $this->persistEntry($club, $period, 100);
        }
        $this->em->flush();

        $clubIds = $this->orderedClubIds($period);

        self::assertSame([(string) $realVeteran->getId()], array_values(array_intersect($clubIds, [
            (string) $spoofRookie->getId(),
            (string) $spoofVeteran->getId(),
            (string) $realRookie->getId(),
            (string) $realVeteran->getId(),
        ])));
    }

    /**
     * findAllForCategoryAndPeriod() is the deliberate exception — its docblock says
     * "regardless of club eligibility", because the stale-score zeroing pass has to see
     * every stored row including the ones no board surfaces.
     */
    public function testTheRawRowReadIsDeliberatelyUnfiltered(): void
    {
        $period = 'test-' . bin2hex(random_bytes(4));

        $spoof = $this->persistClub('Spoof Raw', currentSeason: 9, isSpoof: true);
        $this->persistEntry($spoof, $period, 42);
        $this->em->flush();

        $clubIds = array_map(
            static fn (LeaderboardEntry $e) => (string) $e->getClub()->getId(),
            $this->repo()->findAllForCategoryAndPeriod(LeaderboardCategory::CLUB_REPUTATION, $period),
        );

        self::assertContains((string) $spoof->getId(), $clubIds);
    }

    /** @return string[] */
    private function orderedClubIds(string $period): array
    {
        return array_map(
            static fn (LeaderboardEntry $e) => (string) $e->getClub()->getId(),
            $this->repo()->findAllOrderedByScore(LeaderboardCategory::CLUB_REPUTATION, $period),
        );
    }

    private function repo(): LeaderboardEntryRepository
    {
        return self::getContainer()->get(LeaderboardEntryRepository::class);
    }

    private function persistClub(string $name, int $currentSeason, bool $isSpoof = false): Club
    {
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@elig.test'));
        $user->setPassword('x');

        $club = $this->persist(new Club($name, $user));
        $club->setCurrentSeason($currentSeason);
        $club->setSpoof($isSpoof);

        return $club;
    }

    private function persistEntry(Club $club, string $period, int $score): LeaderboardEntry
    {
        $entry = $this->persist(new LeaderboardEntry($club, LeaderboardCategory::CLUB_REPUTATION, $period));
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
