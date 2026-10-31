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
     * The award read must agree with the board read — that is the whole reason the
     * predicate was hoisted. Paying a bot real money is the bug this closes.
     */
    public function testTheAwardReadExcludesSpoofAndFirstSeasonClubs(): void
    {
        $period = 'test-' . bin2hex(random_bytes(4));

        $spoof   = $this->persistClub('Spoof Award', currentSeason: 9, isSpoof: true);
        $rookie  = $this->persistClub('Rookie Award', currentSeason: 1);
        $winner  = $this->persistClub('Winner Award', currentSeason: 9);

        $this->persistEntry($spoof, $period, 999_999);
        $this->persistEntry($rookie, $period, 888_888);
        $this->persistEntry($winner, $period, 10);
        $this->em->flush();

        $ranked = $this->repo()->findRankedForAward(LeaderboardCategory::CLUB_REPUTATION, $period, 50);
        $clubIds = array_map(static fn (LeaderboardEntry $e) => (string) $e->getClub()->getId(), $ranked);

        self::assertNotContains((string) $spoof->getId(), $clubIds, 'a spoof club must never win a prize');
        self::assertNotContains((string) $rookie->getId(), $clubIds, 'a first-season club must never win a prize');
        self::assertSame([(string) $winner->getId()], $clubIds);
    }

    /**
     * score DESC, club_id ASC — deterministic, so a reclaimed retry re-reading the same
     * data produces the same places. A tie goes to the lower UUID: arbitrary, but stable,
     * and stability is the property an award needs.
     */
    public function testTheAwardReadIsDeterministicOnATie(): void
    {
        $period = 'test-' . bin2hex(random_bytes(4));

        $clubs = [];
        for ($i = 0; $i < 5; $i++) {
            $club    = $this->persistClub('Tie FC ' . $i, currentSeason: 9);
            $clubs[] = $club;
            $this->persistEntry($club, $period, 100);
        }
        $this->em->flush();

        $first = $this->awardOrder($period);
        $again = $this->awardOrder($period);

        self::assertSame($first, $again, 'the same data must produce the same places');

        $sorted = $first;
        sort($sorted);
        self::assertSame($sorted, $first, 'a tie is broken by the lower club id');
    }

    /** @return string[] */
    private function awardOrder(string $period): array
    {
        return array_map(
            static fn (LeaderboardEntry $e) => (string) $e->getClub()->getId(),
            $this->repo()->findRankedForAward(LeaderboardCategory::CLUB_REPUTATION, $period, 50),
        );
    }

    public function testCountEligibleMatchesTheBoard(): void
    {
        $period = 'test-' . bin2hex(random_bytes(4));

        $this->persistEntry($this->persistClub('Counted A', currentSeason: 9), $period, 5);
        $this->persistEntry($this->persistClub('Counted B', currentSeason: 9), $period, 4);
        $this->persistEntry($this->persistClub('Spoof C', currentSeason: 9, isSpoof: true), $period, 3);
        $this->persistEntry($this->persistClub('Rookie D', currentSeason: 1), $period, 2);
        $this->em->flush();

        self::assertSame(2, $this->repo()->countEligible(LeaderboardCategory::CLUB_REPUTATION, $period));
    }

    public function testDeleteForPeriodClearsOnlyThatPeriod(): void
    {
        $doomed   = 'test-' . bin2hex(random_bytes(4));
        $survivor = 'test-' . bin2hex(random_bytes(4));

        $club = $this->persistClub('Prune FC', currentSeason: 9);
        $this->persistEntry($club, $doomed, 10);
        $this->persistEntry($club, $survivor, 20);
        $this->em->flush();

        $deleted = $this->repo()->deleteForPeriod(LeaderboardCategory::CLUB_REPUTATION, $doomed);

        self::assertSame(1, $deleted);
        self::assertSame([], $this->orderedClubIds($doomed));
        self::assertSame([(string) $club->getId()], $this->orderedClubIds($survivor));
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
