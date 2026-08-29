<?php

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\PlayerCareerStat;
use App\Entity\Transfer;
use App\Entity\User;
use App\Enum\LeaderboardCategory;
use App\Enum\TransferType;
use App\Repository\LeaderboardEntryRepository;
use App\Repository\PlayerCareerStatRepository;
use App\Repository\TransferRepository;
use App\Service\LeaderboardCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end cover for the five sync-derived leaderboards: PlayerCareerStat rows →
 * club_goals / club_assists / iron_man, and Transfer rows → transfer_record / transfer_spend.
 *
 * Every board is computed into a per-run unique period so pre-existing rows in
 * wunderkind_test can't contaminate the assertions, and so tearDown can sweep the
 * entries created for *other* clubs that happen to hold stats in the shared DB.
 */
class LeaderboardAggregateCategoriesTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var object[] Everything persisted here, removed in reverse order in tearDown. */
    private array $cleanup = [];

    private string $period;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->period = 'test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        // recalculate() writes an entry for every club with data, not just ours.
        foreach ($this->em->getRepository(LeaderboardEntry::class)->findBy(['period' => $this->period]) as $entry) {
            $this->em->remove($entry);
        }
        $this->em->flush();

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

    public function testSquadTotalsAndIronManAreDerivedFromPlayerCareerStats(): void
    {
        $deepSquad = $this->persistClub('Agg Deep Squad FC');
        $oneManTeam = $this->persistClub('Agg One Man FC');

        $this->persistStat($deepSquad, 'a1', 'Ade Fixture',   appearances: 30, goals: 10, assists: 2);
        $this->persistStat($deepSquad, 'a2', 'Bo Fixture',    appearances: 20, goals: 5,  assists: 8);
        $this->persistStat($deepSquad, 'a3', 'Cy Fixture',    appearances: 12, goals: 0,  assists: 1);
        $this->persistStat($oneManTeam, 'b1', 'Solo Fixture', appearances: 5,  goals: 20, assists: 0);

        $this->em->flush();

        $goals   = $this->recalculateAndIndex(LeaderboardCategory::CLUB_GOALS);
        $assists = $this->recalculateAndIndex(LeaderboardCategory::CLUB_ASSISTS);
        $ironMan = $this->recalculateAndIndex(LeaderboardCategory::IRON_MAN);

        $deepId = (string) $deepSquad->getId();
        $soloId = (string) $oneManTeam->getId();

        // Squad totals sum the whole roster...
        $this->assertSame(15, $goals[$deepId]->getScore());
        $this->assertSame(11, $assists[$deepId]->getScore());
        $this->assertSame(20, $goals[$soloId]->getScore());
        $this->assertSame(0,  $assists[$soloId]->getScore());

        // ...while iron_man takes only the single most-capped player, and names them.
        $this->assertSame(30, $ironMan[$deepId]->getScore());
        $this->assertSame('Ade Fixture', $ironMan[$deepId]->getDisplayLabel());
        $this->assertSame(5, $ironMan[$soloId]->getScore());

        // A one-man team out-scoring a whole squad still ranks above it — the board is a total,
        // not an average, and rank_position is written by the same assignRanks() pass.
        $this->assertLessThan($goals[$deepId]->getRank(), $goals[$soloId]->getRank());

        // Squad totals carry no player name — they are not individual-best boards.
        $this->assertNull($goals[$deepId]->getDisplayLabel());
        $this->assertNull($assists[$deepId]->getDisplayLabel());
    }

    public function testTransferBoardsSplitOnDirectionAndRankGrossFee(): void
    {
        $trader = $this->persistClub('Agg Trader FC');
        $buyer  = $this->persistClub('Agg Buyer Only FC');

        $this->persistTransfer($trader, TransferType::SALE,           'Sold Star',   5_000_000, netProceeds: 4_500_000);
        $this->persistTransfer($trader, TransferType::AGENT_ASSISTED, 'Minor Sale',  1_000_000, netProceeds: 900_000);
        $this->persistTransfer($trader, TransferType::SIGNING,        'Bought Star', 9_000_000);
        $this->persistTransfer($buyer,  TransferType::SIGNING,        'Bargain Buy', 100);

        $this->em->flush();

        $record = $this->recalculateAndIndex(LeaderboardCategory::TRANSFER_RECORD);
        $spend  = $this->recalculateAndIndex(LeaderboardCategory::TRANSFER_SPEND);

        $traderId = (string) $trader->getId();
        $buyerId  = (string) $buyer->getId();

        // Records, not totals: the single biggest deal on each side.
        $this->assertSame(5_000_000, $record[$traderId]->getScore(), 'gross fee, not net proceeds');
        $this->assertSame('Sold Star', $record[$traderId]->getDisplayLabel());
        $this->assertSame(9_000_000, $spend[$traderId]->getScore());
        $this->assertSame('Bought Star', $spend[$traderId]->getDisplayLabel());

        // A club that has only ever bought never appears on the fee-received board.
        $this->assertArrayNotHasKey($buyerId, $record);
        $this->assertSame(100, $spend[$buyerId]->getScore());
    }

    public function testSumByClubRejectsAnUnknownColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(PlayerCareerStatRepository::class)->sumByClub('rank');
    }

    public function testFindHighestFeeByClubReturnsOneRowPerClub(): void
    {
        $club = $this->persistClub('Agg Collapse FC');
        $this->persistTransfer($club, TransferType::SALE, 'First',  100);
        $this->persistTransfer($club, TransferType::SALE, 'Second', 200);
        $this->persistTransfer($club, TransferType::SALE, 'Third',  300);
        $this->em->flush();

        $rows = self::getContainer()->get(TransferRepository::class)->findHighestFeeByClub(false);
        $mine = array_values(array_filter(
            $rows,
            static fn (array $r): bool => (string) $r['clubId'] === (string) $club->getId(),
        ));

        $this->assertCount(1, $mine, 'three sales must collapse to one row');
        $this->assertSame(300, $mine[0]['score']);
        $this->assertSame('Third', $mine[0]['displayLabel']);
    }

    /**
     * Recalculates one board into this run's private period and returns its entries
     * indexed by club id.
     *
     * @return array<string, LeaderboardEntry>
     */
    private function recalculateAndIndex(LeaderboardCategory $category): array
    {
        self::getContainer()->get(LeaderboardCalculationService::class)
            ->recalculate($category, $this->period);

        $indexed = [];
        foreach (self::getContainer()->get(LeaderboardEntryRepository::class)
                     ->findAllOrderedByScore($category, $this->period) as $entry) {
            $indexed[(string) $entry->getClub()->getId()] = $entry;
        }

        return $indexed;
    }

    private function persistClub(string $name): Club
    {
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@agg.test'));
        $user->setPassword('x');

        $club = $this->persist(new Club($name, $user));
        // findAllOrderedByScore only ranks clubs past their first season.
        $club->setCurrentSeason(2);

        return $club;
    }

    private function persistStat(Club $club, string $playerId, string $playerName, int $appearances, int $goals, int $assists): PlayerCareerStat
    {
        $stat = $this->persist(new PlayerCareerStat($club, $playerId . bin2hex(random_bytes(4)), $playerName));
        $stat->applySnapshot($appearances, $goals, $assists, $playerName);

        return $stat;
    }

    private function persistTransfer(Club $club, TransferType $type, string $playerName, int $fee, int $netProceeds = 0): Transfer
    {
        $transfer = $this->persist(new Transfer(
            player: null,
            club: $club,
            destinationClubName: $type === TransferType::SIGNING ? $club->getName() : 'Elsewhere United',
            type: $type,
            occurredAt: new \DateTimeImmutable(),
        ));
        $transfer->setPlayerName($playerName);
        $transfer->setFee($fee);
        $transfer->setNetProceeds($netProceeds);

        return $transfer;
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }
}
