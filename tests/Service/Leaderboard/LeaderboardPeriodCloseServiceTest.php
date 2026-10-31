<?php

declare(strict_types=1);

namespace App\Tests\Service\Leaderboard;

use App\Entity\Club;
use App\Entity\InboxMessage;
use App\Entity\LeaderboardEntry;
use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Entity\Leaderboard\LeaderboardAwardPlacement;
use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use App\Entity\User;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\Leaderboard\PeriodArchiveStatus;
use App\Enum\LeaderboardCategory;
use App\Repository\Leaderboard\LeaderboardPeriodArchiveEntryRepository;
use App\Repository\Leaderboard\LeaderboardPeriodArchiveRepository;
use App\Repository\LeaderboardEntryRepository;
use App\Service\Leaderboard\LeaderboardAwardDeliveryService;
use App\Service\Leaderboard\LeaderboardPeriod;
use App\Service\Leaderboard\LeaderboardPeriodCloseService;
use App\Service\LeaderboardCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The close job: claim, freeze, award, flush, prune — and every way that can be re-entered.
 *
 * Deliberately uses the non-aggregate FANATICS category throughout, so
 * recalculate() only re-ranks the rows this test created and does not upsert a row for
 * every club in the shared wunderkind_test database.
 */
class LeaderboardPeriodCloseServiceTest extends KernelTestCase
{
    private const CATEGORY = LeaderboardCategory::FANATICS;

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
        // Archives first: their entries cascade, and an entry holds no removable child.
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

    // ── Idempotency ─────────────────────────────────────────────────────────────────────

    /**
     * Counts, not "no exception thrown": the claim is meant to make a second close a
     * no-op, and only counts can tell the difference between that and a second close that
     * happened to overwrite the first.
     */
    public function testClosingTwiceProducesOneArchiveOneAwardAndOneInboxMessage(): void
    {
        $period = '2020-M01';
        $now    = $this->after($period);

        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 2);
        $winner     = $this->persistScoringClub($period, growth: 500, baseline: 100);
        $runnerUp   = $this->persistScoringClub($period, growth: 200, baseline: 50);
        $this->em->flush();

        $inboxBefore = $this->inboxCountFor($winner);

        $this->service()->closePeriod($definition, $period, $now);
        // A second pass with the anchor already advanced finds nothing due at all...
        $this->service()->closeDuePeriods($now, [$definition]);
        // ...and even forcing the same key through closePeriod() must lose the claim.
        $this->service()->closePeriod($definition, $period, $now);

        $this->em->clear();

        self::assertSame(1, $this->archiveCount($period), 'exactly one archive per (category, period)');

        $archive = $this->archives()->findOneForClaim(self::CATEGORY, $period);
        self::assertNotNull($archive);
        $this->track($archive);

        self::assertSame(PeriodArchiveStatus::ARCHIVED, $archive->getStatus());
        self::assertSame(2, $archive->getArchivedEntryCount());
        self::assertSame(2, $archive->getPlacementsAwarded());

        $entries = $this->entries()->findForArchive($archive);
        self::assertCount(2, $entries);
        self::assertSame([1, 2], array_map(static fn (LeaderboardPeriodArchiveEntry $e): int => $e->getRank(), $entries));
        self::assertSame([1, 2], array_map(static fn (LeaderboardPeriodArchiveEntry $e): ?int => $e->getPlace(), $entries));

        self::assertSame($inboxBefore + 1, $this->inboxCountFor($winner), 'exactly one inbox message for the winner');
        self::assertSame(1, $this->inboxCountFor($runnerUp));
    }

    /** Two processes racing the raw claim: one wins, the other must skip. */
    public function testASecondConcurrentClaimSkips(): void
    {
        $period = '2020-M02';
        $now    = $this->after($period);

        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 1);
        $this->persistScoringClub($period, growth: 300, baseline: 10);
        $this->em->flush();

        $first  = $this->service()->closePeriod($definition, $period, $now);
        $second = $this->service()->closePeriod($definition, $period, $now);

        self::assertSame('archived', $first['result']);
        self::assertSame('claim not won', $second['result']);

        $this->trackArchive($period);
    }

    // ── Reclaim ─────────────────────────────────────────────────────────────────────────

    /**
     * A close that died part-way leaves a pending archive with incomplete entries. Once its
     * lease expires it must be taken over, and the partial entries discarded wholesale —
     * which is exactly what lets the entry rewrite use the plain ORM without tripping
     * uq_lpae_archive_rank.
     */
    public function testAnExpiredLeaseIsReclaimedAndPartialEntriesAreDiscarded(): void
    {
        $period = '2020-M03';
        $now    = $this->after($period);

        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 1);
        $this->persistScoringClub($period, growth: 400, baseline: 10);
        $this->persistScoringClub($period, growth: 100, baseline: 10);

        $stale = $this->persistArchive($period, $definition, claimedAt: $now->modify('-400 seconds'));
        $this->persistOrphanEntry($stale, rank: 1, clubName: 'Partial One');
        $this->persistOrphanEntry($stale, rank: 2, clubName: 'Partial Two');
        $this->em->flush();

        $staleId = (string) $stale->getId();

        $outcome = $this->service()->closePeriod($definition, $period, $now);

        self::assertSame('archived', $outcome['result']);

        $this->em->clear();

        self::assertSame(1, $this->archiveCount($period), 'reclaim must reuse the pending row, not insert a second');

        $archive = $this->archives()->find($staleId);
        self::assertNotNull($archive);
        $this->track($archive);
        self::assertSame(PeriodArchiveStatus::ARCHIVED, $archive->getStatus());

        $names = array_map(
            static fn (LeaderboardPeriodArchiveEntry $e): string => $e->getClubName(),
            $this->entries()->findForArchive($archive),
        );

        self::assertNotContains('Partial One', $names, 'a pending archive\'s entries are incomplete and must be discarded');
        self::assertNotContains('Partial Two', $names);
        self::assertCount(2, $names, 'the full board is rewritten');
    }

    public function testALiveLeaseIsNotReclaimed(): void
    {
        $period = '2020-M04';
        $now    = $this->after($period);

        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 1);
        $this->persistScoringClub($period, growth: 400, baseline: 10);
        $fresh = $this->persistArchive($period, $definition, claimedAt: $now->modify('-10 seconds'));
        $this->em->flush();

        $outcome = $this->service()->closePeriod($definition, $period, $now);

        self::assertSame('claim not won', $outcome['result'], 'someone else is mid-close');
        self::assertSame(PeriodArchiveStatus::PENDING, $fresh->getStatus());
    }

    // ── Never commit a status ahead of the work ─────────────────────────────────────────

    /**
     * THE guarantee that makes a reclaim safe. If delivery blows up, the archive must stay
     * pending AND the live rows must stay unpruned — otherwise a failed close silently
     * destroys a board.
     */
    public function testAFailureDuringDeliveryLeavesThePeriodPendingAndUnpruned(): void
    {
        $period = '2020-M05';
        $now    = $this->after($period);

        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 1);
        $this->persistScoringClub($period, growth: 400, baseline: 10);
        $this->em->flush();

        $exploding = $this->createStub(LeaderboardAwardDeliveryService::class);
        $exploding->method('awardPlaces')->willThrowException(new \RuntimeException('delivery exploded'));

        $service = $this->service($exploding);

        try {
            $service->closePeriod($definition, $period, $now);
            self::fail('the exception should have propagated');
        } catch (\RuntimeException $e) {
            self::assertSame('delivery exploded', $e->getMessage());
        }

        $this->em->clear();

        $archive = $this->archives()->findOneForClaim(self::CATEGORY, $period);
        self::assertNotNull($archive);
        $this->track($archive);

        self::assertSame(PeriodArchiveStatus::PENDING, $archive->getStatus(), 'the claim stands, so the period is reclaimable');
        self::assertNull($archive->getClosedAt());
        self::assertGreaterThan(0, $this->liveRowCount($period), 'the live board must NOT be pruned by a failed close');

        $reloaded = $this->em->find(LeaderboardAwardDefinition::class, $definition->getId());
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getLastClosedPeriodKey(), 'the anchor must not advance past work that did not happen');
    }

    // ── Prune ───────────────────────────────────────────────────────────────────────────

    public function testTheClosedPeriodsLiveRowsAreGoneAndTheNewPeriodStartsAtZero(): void
    {
        $period = '2020-M06';
        $now    = $this->after($period);

        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 1);
        $club       = $this->persistScoringClub($period, growth: 400, baseline: 10);
        $this->em->flush();

        self::assertGreaterThan(0, $this->liveRowCount($period));

        $this->service()->closePeriod($definition, $period, $now);

        self::assertSame(0, $this->liveRowCount($period), 'the visible "the table clears"');

        // The next open period is populated by the next sync or generate pass, not by the
        // close itself — and it starts from a fresh baseline, so growth reads zero.
        $openKey = $definition->currentPeriodKey($now);
        $entry   = $this->persist(new LeaderboardEntry($club, self::CATEGORY, $openKey));
        $entry->observeCumulative(410);
        $this->em->flush();

        self::getContainer()->get(LeaderboardCalculationService::class)->recalculate(self::CATEGORY, $openKey);

        self::assertSame(0, $entry->getScore(), 'the new period starts at zero growth');
        self::assertSame(410, $entry->getCumulativeScore());
        self::assertNotNull($entry->getRank());

        $this->trackArchive($period);
    }

    // ── Catch-up ────────────────────────────────────────────────────────────────────────

    /**
     * A cron outage must settle each missed period in order, one per pass — never silently
     * skip two months.
     */
    public function testACatchUpClosesEachMissedPeriodOldestFirst(): void
    {
        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 1);
        $definition->markClosed('2020-M06', new \DateTimeImmutable('2020-07-01', new \DateTimeZone('UTC')));
        $this->em->flush();

        // "Now" is well past three further month ends.
        $now = new \DateTimeImmutable('2020-10-05 04:14:00', new \DateTimeZone('UTC'));

        $closed = [];
        for ($pass = 0; $pass < 3; $pass++) {
            $dueKey = $this->service()->dueKeyFor($definition, $now);
            self::assertNotNull($dueKey, "pass {$pass} should have found a due period");

            $this->persistScoringClub($dueKey, growth: 100 + $pass, baseline: 1);
            $this->em->flush();

            $this->service()->closePeriod($definition, $dueKey, $now);
            $closed[] = $dueKey;
        }

        self::assertSame(['2020-M07', '2020-M08', '2020-M09'], $closed, 'oldest first, one per pass');

        // And the fourth pass finds nothing: 2020-M10 is still open.
        self::assertNull($this->service()->dueKeyFor($definition, $now));

        foreach ($closed as $period) {
            $archive = $this->trackArchive($period);
            self::assertNotNull($archive);

            $bounds = LeaderboardPeriod::boundsOf($period);
            self::assertNotNull($bounds);
            self::assertEquals($bounds[0], $archive->getPeriodStartsAt(), "{$period} start is frozen correctly");
            self::assertEquals($bounds[1], $archive->getPeriodEndsAt());
        }
    }

    public function testAnOpenPeriodIsNeverDueWithoutForce(): void
    {
        $definition = $this->persistDefinition(AwardCadence::QUARTERLY, places: 1);
        $this->em->flush();

        // Mid-quarter.
        $now = new \DateTimeImmutable('2020-11-15 12:00:00', new \DateTimeZone('UTC'));

        self::assertSame('2020-Q3', $this->service()->dueKeyFor($definition, $now), 'the previous quarter has ended');
        self::assertSame('2020-Q4', $this->service()->dueKeyFor($definition, $now, force: true), '--force closes the open one');
    }

    public function testTheAnchorIsMonotonic(): void
    {
        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 1);
        $definition->markClosed('2020-M09', new \DateTimeImmutable('2020-10-01', new \DateTimeZone('UTC')));
        $this->em->flush();

        // Now is inside 2020-M09 itself — an absurd clock, but the anchor must still refuse
        // to re-close a period at or before itself.
        $now = new \DateTimeImmutable('2020-09-20 12:00:00', new \DateTimeZone('UTC'));

        self::assertNull($this->service()->dueKeyFor($definition, $now));
        self::assertNull($this->service()->dueKeyFor($definition, $now, force: true));
    }

    // ── Awarding rules ──────────────────────────────────────────────────────────────────

    public function testGrowthBelowTheMinimumIsArchivedButNotAwarded(): void
    {
        $period = '2020-M11';
        $now    = $this->after($period);

        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 1);
        $definition->setMinimumScore(1_000);
        $this->persistScoringClub($period, growth: 999, baseline: 10);
        $this->em->flush();

        $outcome = $this->service()->closePeriod($definition, $period, $now);

        self::assertSame('archived', $outcome['result']);
        self::assertSame(1, $outcome['archived'], 'the row is still frozen — it is honest history');
        self::assertSame(0, $outcome['awarded'], 'but a dead chart must not pay an idle club');

        $archive = $this->trackArchive($period);
        self::assertNotNull($archive);
        self::assertSame(0, $archive->getPlacementsAwarded());
    }

    public function testAnEmptyBoardIsSkippedNotArchived(): void
    {
        $period = '2020-M12';
        $now    = $this->after($period);

        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 1);
        $this->em->flush();

        $outcome = $this->service()->closePeriod($definition, $period, $now);

        self::assertStringContainsString('skipped', $outcome['result']);

        $archive = $this->trackArchive($period);
        self::assertNotNull($archive);
        self::assertSame(PeriodArchiveStatus::SKIPPED, $archive->getStatus());
        self::assertNotNull($archive->getClosedAt());
    }

    /**
     * A disabled definition writes no period rows at all (ActivePeriodService), so on
     * re-enable there is nothing from before the gap to award. This asserts the end of that
     * chain: the first close after a re-enable sees only post-re-enable growth.
     */
    public function testTheFirstCloseAfterAReEnableAwardsOnlyPostReEnableGrowth(): void
    {
        $period = '2021-M01';
        $now    = $this->after($period);

        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 1);

        // The all-time leader: a huge cumulative, but its row for THIS period was created
        // after the re-enable, so its baseline is its own cumulative and its growth is 0.
        $leader = $this->persistScoringClub($period, growth: 0, baseline: 9_000_000);
        // A modest club that actually gained during the period.
        $climber = $this->persistScoringClub($period, growth: 50, baseline: 10);
        $this->em->flush();

        $this->service()->closePeriod($definition, $period, $now);
        $this->em->clear();

        $archive = $this->trackArchive($period);
        self::assertNotNull($archive);

        $entries = $this->entries()->findForArchive($archive);

        self::assertSame($climber->getName(), $entries[0]->getClubName(), 'growth ranks above an untouched cumulative');
        self::assertTrue($entries[0]->isAwarded());
        self::assertSame(9_000_000, $entries[1]->getCumulativeScore(), 'the leader is still listed, truthfully');
        self::assertFalse($entries[1]->isAwarded(), 'the all-time leader cannot be retro-awarded');
    }

    public function testTheCountsOnTheArchiveAreCorrect(): void
    {
        $period = '2021-M02';
        $now    = $this->after($period);

        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 2);
        foreach ([300, 200, 100, 50] as $growth) {
            $this->persistScoringClub($period, growth: $growth, baseline: 5);
        }
        $this->em->flush();

        $this->service()->closePeriod($definition, $period, $now);
        $this->em->clear();

        $archive = $this->trackArchive($period);
        self::assertNotNull($archive);

        self::assertSame(4, $archive->getEntryCount(), 'every eligible club, uncapped');
        self::assertSame(4, $archive->getArchivedEntryCount());
        self::assertSame(2, $archive->getPlacementsAwarded(), 'two prize places configured');
    }

    /** The archive must freeze club identity, so the landing page still renders a deleted club. */
    public function testClubIdentityIsFrozenOntoTheArchive(): void
    {
        $period = '2021-M03';
        $now    = $this->after($period);

        $definition = $this->persistDefinition(AwardCadence::MONTHLY, places: 1);
        $club       = $this->persistScoringClub($period, growth: 120, baseline: 1);
        $club->setCountry('EN');
        $club->setBadgeConfig(['badgeShape' => 'shield']);
        $club->setHomeKitConfig(['kit' => 'stripes', 'primary' => '#ff0000']);
        $this->em->flush();

        $this->service()->closePeriod($definition, $period, $now);
        $this->em->clear();

        $archive = $this->trackArchive($period);
        self::assertNotNull($archive);

        $entry = $this->entries()->findForArchive($archive)[0];

        self::assertSame($club->getName(), $entry->getClubName());
        self::assertSame('EN', $entry->getCountryCode());
        self::assertSame(['badgeShape' => 'shield'], $entry->getBadgeConfig());
        self::assertSame(['kit' => 'stripes', 'primary' => '#ff0000'], $entry->getHomeKitConfig());
        self::assertNotNull($entry->getUserId(), 'the credited account is frozen too');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────

    private function service(?LeaderboardAwardDeliveryService $delivery = null): LeaderboardPeriodCloseService
    {
        if ($delivery === null) {
            return self::getContainer()->get(LeaderboardPeriodCloseService::class);
        }

        return new LeaderboardPeriodCloseService(
            $this->em,
            self::getContainer()->get(\App\Repository\Leaderboard\LeaderboardAwardDefinitionRepository::class),
            $this->archives(),
            $this->entries(),
            self::getContainer()->get(LeaderboardEntryRepository::class),
            self::getContainer()->get(LeaderboardCalculationService::class),
            $delivery,
            new NullLogger(),
        );
    }

    private function archives(): LeaderboardPeriodArchiveRepository
    {
        return self::getContainer()->get(LeaderboardPeriodArchiveRepository::class);
    }

    private function entries(): LeaderboardPeriodArchiveEntryRepository
    {
        return self::getContainer()->get(LeaderboardPeriodArchiveEntryRepository::class);
    }

    /** An instant comfortably after the given period has ended. */
    private function after(string $period): \DateTimeImmutable
    {
        $bounds = LeaderboardPeriod::boundsOf($period);
        self::assertNotNull($bounds, "'{$period}' is not a valid period key");

        return $bounds[1]->modify('+2 days');
    }

    private function persistDefinition(AwardCadence $cadence, int $places): LeaderboardAwardDefinition
    {
        $definition = new LeaderboardAwardDefinition();
        $definition
            ->setName('Close Test Award')
            ->setCategory(self::CATEGORY)
            ->setCadence($cadence)
            ->setIsEnabled(true);

        for ($place = 1; $place <= $places; $place++) {
            $placement = new LeaderboardAwardPlacement($place);
            $placement->setPrizePence(100_000 * (($places + 1) - $place));
            $definition->addPlacement($placement);
        }

        return $this->persist($definition);
    }

    /** A club with a growth-scored row in $period: baseline stamped, then observed up by $growth. */
    private function persistScoringClub(string $period, int $growth, int $baseline): Club
    {
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@close.test'));
        $user->setPassword('x');

        $club = $this->persist(new Club('Close FC ' . bin2hex(random_bytes(3)), $user));
        $club->setCurrentSeason(4);

        $entry = $this->persist(new LeaderboardEntry($club, self::CATEGORY, $period));
        $entry->observeCumulative($baseline);
        $entry->observeCumulative($baseline + $growth);

        return $club;
    }

    private function persistArchive(string $period, LeaderboardAwardDefinition $definition, \DateTimeImmutable $claimedAt): LeaderboardPeriodArchive
    {
        $bounds = LeaderboardPeriod::boundsOf($period);
        self::assertNotNull($bounds);

        return $this->persist(new LeaderboardPeriodArchive(
            $definition,
            self::CATEGORY,
            $definition->getCadence(),
            $definition->getName(),
            $period,
            $bounds[0],
            $bounds[1],
            null,
            $claimedAt,
        ));
    }

    private function persistOrphanEntry(LeaderboardPeriodArchive $archive, int $rank, string $clubName): LeaderboardPeriodArchiveEntry
    {
        $entry = new LeaderboardPeriodArchiveEntry(
            $archive,
            $rank,
            'club-' . bin2hex(random_bytes(6)),
            $clubName,
            null,
            1,
            1,
            0,
            false,
            true,
        );

        // Not tracked for cleanup: these are deleted by the reclaim under test, and the
        // archive cascades whatever survives.
        $this->em->persist($entry);

        return $entry;
    }

    private function archiveCount(string $period): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM leaderboard_period_archive WHERE category = :c AND period_key = :p',
            ['c' => self::CATEGORY->value, 'p' => $period],
        );
    }

    private function liveRowCount(string $period): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM leaderboard_entry WHERE category = :c AND period = :p',
            ['c' => self::CATEGORY->value, 'p' => $period],
        );
    }

    private function inboxCountFor(Club $club): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM inbox_message WHERE club_id = :c',
            ['c' => $club->getId()->toRfc4122()],
        );
    }

    private function trackArchive(string $period): ?LeaderboardPeriodArchive
    {
        $archive = $this->archives()->findOneForClaim(self::CATEGORY, $period);

        if ($archive !== null) {
            $this->track($archive);
        }

        return $archive;
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }

    private function track(object $entity): void
    {
        $this->cleanup[] = $entity;
    }
}
