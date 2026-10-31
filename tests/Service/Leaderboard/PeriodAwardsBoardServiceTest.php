<?php

declare(strict_types=1);

namespace App\Tests\Service\Leaderboard;

use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\LeaderboardCategory;
use App\Service\Leaderboard\LeaderboardPeriod;
use App\Service\Leaderboard\PeriodAwardsBoardService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The landing page's "who won last period" read.
 *
 * Two properties matter beyond the obvious: it returns [] when nothing is archived (which
 * hides both the section and its nav link, and is the real state for every environment
 * until a definition is enabled and a period ends), and the entry fetch is BATCHED — a
 * snapshot must not be traded for an N+1 of the same size.
 */
class PeriodAwardsBoardServiceTest extends KernelTestCase
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

    /**
     * The shared test DB may hold archives written by other tests, so this asserts the
     * contract rather than absolute emptiness: a board is only ever built from an ARCHIVED
     * row that actually has frozen entries, and the limit is honoured. An empty list is
     * what hides both the section and its nav link, and is the real state for every
     * environment until a definition is enabled and a period ends.
     */
    public function testEveryListedBoardHasFrozenRowsAndTheLimitIsHonoured(): void
    {
        $boards = $this->service()->getBoards(2);

        self::assertLessThanOrEqual(2, count($boards));

        foreach ($boards as $board) {
            self::assertNotSame([], $board['entries'], 'a board with no frozen rows must not be listed');
            self::assertLessThanOrEqual(PeriodAwardsBoardService::ENTRIES_PER_BOARD, count($board['entries']));
        }
    }

    public function testASkippedArchiveIsNeverListed(): void
    {
        $archive = $this->persistArchive('2015-M01', LeaderboardCategory::IRON_MAN);
        $archive->markSkipped(new \DateTimeImmutable());
        $this->em->flush();

        $periods = array_column($this->service()->getBoards(50), 'period');

        self::assertNotContains('2015-M01', $periods, 'a period with nothing to show is not a board worth a card');
    }

    public function testAPendingArchiveIsNeverListed(): void
    {
        $archive = $this->persistArchive('2015-M02', LeaderboardCategory::IRON_MAN);
        $this->persistEntry($archive, 1, 'Pending FC', 10);
        $this->em->flush();

        $periods = array_column($this->service()->getBoards(50), 'period');

        self::assertNotContains('2015-M02', $periods, 'a close still in flight is not history yet');
    }

    public function testAnArchivedBoardIsReturnedReadyToRender(): void
    {
        $archive = $this->persistArchive('2015-M03', LeaderboardCategory::IRON_MAN);
        $top     = $this->persistEntry($archive, 1, 'Frozen Rovers', 440, 'Frozen Striker');
        $top->markAwarded(1, [['type' => 'ledger_delta', 'amountPence' => 1_000]], null);
        $this->persistEntry($archive, 2, 'Second FC', 120);
        $archive->markArchived(37, 2, 1, new \DateTimeImmutable());
        $this->em->flush();

        $board = $this->boardFor('2015-M03');
        self::assertNotNull($board);

        self::assertSame('Iron Man Test Award', $board['name']);
        self::assertSame('iron_man', $board['category']);
        self::assertSame('monthly', $board['cadence']);
        self::assertSame('Monthly', $board['cadenceLabel']);
        self::assertSame('March 2015', $board['periodLabel'], 'the label comes from the frozen instants');
        self::assertSame(37, $board['entryCount'], 'every eligible club, not just the frozen rows');
        self::assertSame(1, $board['awardCount']);

        self::assertCount(2, $board['entries']);
        self::assertSame(1, $board['entries'][0]['rank']);
        self::assertSame('Frozen Rovers', $board['entries'][0]['clubName']);
        self::assertSame(440, $board['entries'][0]['score']);
        self::assertSame('Frozen Striker', $board['entries'][0]['displayLabel']);
        self::assertTrue($board['entries'][0]['awarded']);
        self::assertFalse($board['entries'][1]['awarded']);
    }

    /**
     * The archive froze club identity so a club that has since been deleted still renders
     * its badge — the page's "any club listed anywhere shows at least its badge" rule.
     * Nothing here touches the club table at all, which is the point.
     */
    public function testFrozenIdentityStillRendersWithNoClubRow(): void
    {
        $archive = $this->persistArchive('2015-M04', LeaderboardCategory::IRON_MAN);
        $entry   = new LeaderboardPeriodArchiveEntry(
            $archive,
            1,
            'a-club-id-that-no-longer-exists',
            'Ghost Rovers',
            null,
            90,
            90,
            0,
            false,
            true,
            null,
            ['kit' => 'stripes', 'primary' => '#ff0000'],
            ['kit' => 'plain'],
            ['badgeShape' => 'shield'],
            'EN',
        );
        $this->em->persist($entry);
        $this->cleanup[] = $entry;

        $archive->markArchived(1, 1, 0, new \DateTimeImmutable());
        $this->em->flush();

        $board = $this->boardFor('2015-M04');
        self::assertNotNull($board);

        $row = $board['entries'][0];

        self::assertSame('Ghost Rovers', $row['clubName']);
        self::assertSame(['badgeShape' => 'shield'], $row['badgeConfig']);
        self::assertSame(['kit' => 'stripes', 'primary' => '#ff0000'], $row['homeKitConfig']);
        self::assertSame('EN', $row['countryCode']);
    }

    /** Several boards are listed at once, each with its own frozen rows. */
    public function testSeveralBoardsAreListedAtOnce(): void
    {
        $categories = [
            LeaderboardCategory::IRON_MAN,
            LeaderboardCategory::CLUB_GOALS,
            LeaderboardCategory::CLUB_ASSISTS,
            LeaderboardCategory::TRANSFER_RECORD,
        ];

        foreach ($categories as $i => $category) {
            $archive = $this->persistArchive('2014-M0' . ($i + 1), $category);
            $this->persistEntry($archive, 1, 'Batched FC ' . $i, 100 - $i);
            $this->persistEntry($archive, 2, 'Batched Runner ' . $i, 50 - $i);
            $archive->markArchived(2, 2, 0, new \DateTimeImmutable());
        }
        $this->em->flush();
        $this->em->clear();

        $ours = array_values(array_filter(
            $this->service()->getBoards(10),
            static fn (array $b): bool => str_starts_with($b['period'], '2014-M'),
        ));

        self::assertGreaterThanOrEqual(1, count($ours));

        foreach ($ours as $board) {
            self::assertCount(2, $board['entries']);
        }
    }

    /** The batched repository read, asserted directly. */
    public function testFindTopEntriesForArchivesReturnsEveryArchiveInOneCall(): void
    {
        $ids = [];
        foreach ([LeaderboardCategory::IRON_MAN, LeaderboardCategory::CLUB_GOALS] as $i => $category) {
            $archive = $this->persistArchive('2013-M0' . ($i + 1), $category);
            $this->persistEntry($archive, 1, 'Direct FC ' . $i, 10);
            $this->persistEntry($archive, 2, 'Direct Runner ' . $i, 5);
            $this->persistEntry($archive, 3, 'Direct Third ' . $i, 1);
            $archive->markArchived(3, 3, 0, new \DateTimeImmutable());
            $ids[] = (string) $archive->getId();
        }
        $this->em->flush();

        $grouped = self::getContainer()
            ->get(\App\Repository\Leaderboard\LeaderboardPeriodArchiveEntryRepository::class)
            ->findTopEntriesForArchives($ids, 2);

        self::assertCount(2, $grouped, 'both archives in one call');

        foreach ($ids as $id) {
            self::assertArrayHasKey($id, $grouped);
            self::assertCount(2, $grouped[$id], 'and each capped at the per-archive slice');
            self::assertSame([1, 2], array_map(
                static fn (LeaderboardPeriodArchiveEntry $e): int => $e->getRank(),
                $grouped[$id],
            ));
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────

    private function service(): PeriodAwardsBoardService
    {
        return self::getContainer()->get(PeriodAwardsBoardService::class);
    }

    /** @return array<string, mixed>|null */
    private function boardFor(string $period): ?array
    {
        foreach ($this->service()->getBoards(50) as $board) {
            if ($board['period'] === $period) {
                return $board;
            }
        }

        return null;
    }

    private function persistArchive(string $period, LeaderboardCategory $category): LeaderboardPeriodArchive
    {
        $bounds = LeaderboardPeriod::boundsOf($period);
        self::assertNotNull($bounds);

        $archive = new LeaderboardPeriodArchive(
            null,
            $category,
            AwardCadence::MONTHLY,
            'Iron Man Test Award',
            $period,
            $bounds[0],
            $bounds[1],
            null,
            new \DateTimeImmutable(),
        );

        $this->em->persist($archive);
        $this->cleanup[] = $archive;

        return $archive;
    }

    private function persistEntry(
        LeaderboardPeriodArchive $archive,
        int $rank,
        string $clubName,
        int $score,
        ?string $displayLabel = null,
    ): LeaderboardPeriodArchiveEntry {
        $entry = new LeaderboardPeriodArchiveEntry(
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
        );

        $this->em->persist($entry);
        $this->cleanup[] = $entry;

        return $entry;
    }
}
