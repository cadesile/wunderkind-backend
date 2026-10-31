<?php

declare(strict_types=1);

namespace App\Tests\Service\Leaderboard;

use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Entity\Leaderboard\LeaderboardAwardPlacement;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use App\Entity\User;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\LeaderboardCategory;
use App\Repository\Leaderboard\LeaderboardPeriodArchiveEntryRepository;
use App\Repository\Leaderboard\LeaderboardPeriodArchiveRepository;
use App\Service\Leaderboard\LeaderboardPeriod;
use App\Service\Leaderboard\LeaderboardPeriodCloseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * THE baseline-missing rule, end to end through a real close — and the reason the archive
 * records baseline_missing and award_eligible per row rather than leaving a reader to
 * re-derive it:
 *
 *  | case                                         | baseline | on the board | award-eligible |
 *  |----------------------------------------------|----------|--------------|----------------|
 *  | has a baseline                               | its own  | yes          | yes            |
 *  | none, club created at/after the period start | 0        | yes          | YES            |
 *  | none, club created before the period start   | 0        | yes          | no             |
 *
 * The second row did all its accumulating inside the period, so its cumulative IS its
 * growth. The third covers a club crossing the currentSeason > 1 gate mid-period: its
 * cumulative is partly pre-period, so it is listed honestly but cannot win.
 */
class LeaderboardGrowthScoringTest extends KernelTestCase
{
    // CLUB_REPUTATION deliberately: it is NOT an aggregate category, so recalculate()
    // only re-ranks the rows this test created rather than upserting one for every club
    // in the shared wunderkind_test database.
    private const CATEGORY = LeaderboardCategory::CLUB_REPUTATION;

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

    public function testAStampedBaselineIsSubtractedAndTheClubCanWin(): void
    {
        $period = '2018-M01';
        $entry  = $this->entryWith($period, baseline: 100, cumulative: 140, createdAt: new \DateTimeImmutable('2008-01-01'));

        self::assertSame(40, $entry->getScore(), 'baseline 100 + cumulative 140 is a period score of 40');

        $archived = $this->closeAndFetch($period);

        self::assertFalse($archived->isBaselineMissing());
        self::assertTrue($archived->isAwardEligible());
        self::assertTrue($archived->isAwarded());
        self::assertSame(40, $archived->getScore());
        self::assertSame(140, $archived->getCumulativeScore());
        self::assertSame(100, $archived->getBaselineScore());
    }

    /**
     * No baseline, but the club did not exist before the period started — so everything it
     * has accumulated, it accumulated inside the period. It wins.
     */
    public function testAClubBornInsideThePeriodIsAwardEligibleWithoutABaseline(): void
    {
        $period = '2018-M02';
        $bounds = LeaderboardPeriod::boundsOf($period);
        self::assertNotNull($bounds);

        $this->entryWithNoBaseline($period, score: 60, createdAt: $bounds[0]->modify('+2 days'));

        $archived = $this->closeAndFetch($period);

        self::assertTrue($archived->isBaselineMissing());
        self::assertTrue($archived->isAwardEligible(), 'it did all of its accumulating inside the period');
        self::assertTrue($archived->isAwarded());
        self::assertSame(0, $archived->getBaselineScore());
    }

    /**
     * No baseline and the club predates the period — the currentSeason > 1 gate crossed
     * mid-period. Its cumulative is partly pre-period, so it is listed honestly and cannot
     * win.
     */
    public function testAClubThatArrivedMidPeriodIsListedButNotAwardEligible(): void
    {
        $period = '2018-M03';
        $bounds = LeaderboardPeriod::boundsOf($period);
        self::assertNotNull($bounds);

        $this->entryWithNoBaseline($period, score: 5_000, createdAt: $bounds[0]->modify('-3 years'));

        $archived = $this->closeAndFetch($period);

        self::assertTrue($archived->isBaselineMissing());
        self::assertFalse($archived->isAwardEligible());
        self::assertFalse($archived->isAwarded(), 'a partly pre-period cumulative must not win a growth board');
        self::assertSame(5_000, $archived->getScore(), 'it is still on the board, honestly — it did arrive this period');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────

    private function entryWith(string $period, int $baseline, int $cumulative, \DateTimeImmutable $createdAt): LeaderboardEntry
    {
        $club = $this->persistClub($createdAt);

        $entry = $this->persist(new LeaderboardEntry($club, self::CATEGORY, $period));
        $entry->observeCumulative($baseline);
        $entry->observeCumulative($cumulative);
        $this->em->flush();

        return $entry;
    }

    /** A row whose score was written absolutely, never observed — so baseline_score is NULL. */
    private function entryWithNoBaseline(string $period, int $score, \DateTimeImmutable $createdAt): LeaderboardEntry
    {
        $club = $this->persistClub($createdAt);

        $entry = $this->persist(new LeaderboardEntry($club, self::CATEGORY, $period));
        $entry->setScore($score);
        $this->em->flush();

        return $entry;
    }

    private function closeAndFetch(string $period): LeaderboardPeriodArchiveEntry
    {
        $definition = new LeaderboardAwardDefinition();
        $definition
            ->setName('Growth Rule Test')
            ->setCategory(self::CATEGORY)
            ->setCadence(AwardCadence::MONTHLY)
            ->setIsEnabled(true);

        $placement = new LeaderboardAwardPlacement(1);
        $placement->setPrizePence(50_000);
        $definition->addPlacement($placement);

        $this->persist($definition);
        $this->em->flush();

        $bounds = LeaderboardPeriod::boundsOf($period);
        self::assertNotNull($bounds);

        self::getContainer()->get(LeaderboardPeriodCloseService::class)
            ->closePeriod($definition, $period, $bounds[1]->modify('+1 day'));

        $archive = self::getContainer()->get(LeaderboardPeriodArchiveRepository::class)
            ->findOneForClaim(self::CATEGORY, $period);

        self::assertNotNull($archive);
        $this->cleanup[] = $archive;

        $entries = self::getContainer()->get(LeaderboardPeriodArchiveEntryRepository::class)->findForArchive($archive);

        self::assertCount(1, $entries, 'the test club should be the only eligible row for this private period');

        return $entries[0];
    }

    private function persistClub(\DateTimeImmutable $createdAt): Club
    {
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@growth.test'));
        $user->setPassword('x');

        $club = $this->persist(new Club('Growth Rule FC ' . bin2hex(random_bytes(3)), $user));
        $club->setCurrentSeason(3);

        // createdAt has no setter — it is stamped in the constructor, which is correct for
        // production and useless for a rule keyed on it. Written directly, which is the
        // only way to express "this club existed before the period".
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE club SET created_at = :createdAt WHERE id = :id',
            ['createdAt' => $createdAt->format('Y-m-d H:i:s'), 'id' => $club->getId()->toRfc4122()],
        );
        $this->em->refresh($club);

        return $club;
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }
}
