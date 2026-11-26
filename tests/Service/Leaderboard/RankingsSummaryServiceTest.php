<?php

declare(strict_types=1);

namespace App\Tests\Service\Leaderboard;

use App\Entity\Club;
use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Entity\Leaderboard\LeaderboardAwardPlacement;
use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use App\Entity\LeaderboardEntry;
use App\Entity\User;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\LeaderboardCategory;
use App\Service\Leaderboard\LeaderboardPeriod;
use App\Service\Leaderboard\RankingsSummaryService;
use App\Service\LeaderboardCalculationService;
use App\Service\TrophyConfigFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * GET /api/rankings/summary's payload: active awards only, soonest to close first, the
 * requesting club's line, and the player's own award history.
 *
 * Every test pins `now` to a date far from any other fixture (March 2031), so the period
 * keys it reads are its own even on the shared test database.
 */
class RankingsSummaryServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var object[] */
    private array $cleanup = [];

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em  = self::getContainer()->get(EntityManagerInterface::class);
        $this->now = new \DateTimeImmutable('2031-03-12 10:00:00', new \DateTimeZone('UTC'));

        // The board read is cached per (category, period) like the public endpoint's, and
        // every test here uses the same period, so start each one from a cold cache.
        self::getContainer()->get(LeaderboardCalculationService::class)->invalidate(LeaderboardCategory::CLUB_REPUTATION);
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

    public function testOnlyEnabledAwardsAreListedSoonestToCloseFirst(): void
    {
        $monthly  = $this->definition('Monthly Rep', AwardCadence::MONTHLY, enabled: true);
        $weekly   = $this->definition('Weekly Rep', AwardCadence::WEEKLY, enabled: true);
        $disabled = $this->definition('Quarterly Rep', AwardCadence::QUARTERLY, enabled: false);
        $me       = $this->club('Summary Me FC', 3);
        $this->em->flush();

        $ids = array_column($this->summary($me)['awards'], 'definitionId');
        $ours = array_values(array_intersect($ids, [(string) $monthly->getId(), (string) $weekly->getId(), (string) $disabled->getId()]));

        self::assertSame([(string) $weekly->getId(), (string) $monthly->getId()], $ours, 'weekly closes first; a disabled award is not listed');
    }

    public function testAnAwardCarriesItsPeriodPrizesTrophyTopRowsAndMyLine(): void
    {
        $definition = $this->definition('The Rep Builder', AwardCadence::WEEKLY, enabled: true, places: [
            2 => [20_000, 1],
            1 => [50_000, 3],
        ]);
        $definition->setTrophy(['style' => 'goblet', 'finish' => 'silver', 'ribbons' => true, 'ribbonPrimary' => '#c8202f', 'ribbonSecondary' => '#1f4fb8']);

        $period = LeaderboardPeriod::keyFor(AwardCadence::WEEKLY, $this->now);
        $a  = $this->club('Alpha Rovers', 4);
        $b  = $this->club('Bravo Town', 4);
        $c  = $this->club('Charlie City', 4);
        $me = $this->club('Delta Me FC', 4);
        $this->entry($a, $period, 90);
        $this->entry($b, $period, 60);
        $this->entry($c, $period, 40);
        $this->entry($me, $period, 25);
        $this->em->flush();

        $award = $this->awardFor($this->summary($me), $definition);

        self::assertSame($period, $award['periodKey']);
        self::assertSame('2031-03-17T00:00:00+00:00', $award['endsAt'], 'the ISO week ends Monday 00:00 UTC');
        self::assertSame('weekly', $award['cadence']);
        self::assertSame([1, 2], array_column($award['prizes'], 'place'), 'prizes come sorted by place');
        self::assertSame(50_000, $award['prizes'][0]['prizePence']);
        self::assertSame(3, $award['prizes'][0]['reputationDelta']);
        self::assertSame('goblet', $award['trophy']['style']);
        self::assertSame(4, $award['entrants']);
        self::assertSame(['Alpha Rovers', 'Bravo Town', 'Charlie City'], array_column($award['top'], 'clubName'));

        self::assertSame(4, $award['me']['rank']);
        self::assertSame(25, $award['me']['score']);
        self::assertFalse($award['me']['inPrize']);
        self::assertSame(35, $award['me']['gapToPrize'], '60 (the last prize place) minus 25');
    }

    public function testAClubInAPrizePlaceHasNoGap(): void
    {
        $definition = $this->definition('Leader Rep', AwardCadence::WEEKLY, enabled: true, places: [1 => [1_000, 0]]);
        $period = LeaderboardPeriod::keyFor(AwardCadence::WEEKLY, $this->now);
        $me = $this->club('Top Me FC', 4);
        $other = $this->club('Chaser FC', 4);
        $this->entry($me, $period, 50);
        $this->entry($other, $period, 10);
        $this->em->flush();

        $mine = $this->awardFor($this->summary($me), $definition)['me'];

        self::assertSame(1, $mine['rank']);
        self::assertTrue($mine['inPrize']);
        self::assertNull($mine['gapToPrize']);
    }

    public function testAFirstSeasonClubIsNotRankedAndSaysWhy(): void
    {
        $definition = $this->definition('Rookie Rep', AwardCadence::WEEKLY, enabled: true);
        $period = LeaderboardPeriod::keyFor(AwardCadence::WEEKLY, $this->now);
        $rookie = $this->club('Rookie Me FC', 1);
        $this->entry($rookie, $period, 500);
        $this->em->flush();

        $award = $this->awardFor($this->summary($rookie), $definition);

        self::assertNull($award['me']['rank']);
        self::assertSame('first_season', $award['me']['reason']);
        self::assertSame(TrophyConfigFactory::defaults(), $award['trophy'], 'no trophy configured falls back to the default cup');
    }

    public function testMyAwardsListsWhatThisUserWonWithThePrizeActuallyPaid(): void
    {
        $definition = $this->definition('History Rep', AwardCadence::MONTHLY, enabled: true);
        $me = $this->club('History Me FC', 4);
        $this->em->flush();

        $bounds  = LeaderboardPeriod::boundsOf('2031-M01');
        $archive = new LeaderboardPeriodArchive($definition, LeaderboardCategory::CLUB_REPUTATION, AwardCadence::MONTHLY, 'History Rep', '2031-M01', $bounds[0], $bounds[1], null, $this->now);
        $this->persist($archive);
        $entry = new LeaderboardPeriodArchiveEntry($archive, 1, (string) $me->getId(), 'History Me FC', (string) $me->getUser()->getId(), 77, 77, 0, false, true);
        $entry->markAwarded(1, [['type' => 'ledger_delta', 'amountPence' => 12_300], ['type' => 'reputation_delta', 'amount' => 2]], null);
        $this->persist($entry);
        $archive->markArchived(10, 1, 1, $this->now);
        $this->em->flush();

        $summary = $this->summary($me);
        $won = array_values(array_filter($summary['myAwards'], static fn (array $a): bool => $a['id'] === (string) $entry->getId()));

        self::assertCount(1, $won);
        self::assertSame('History Rep', $won[0]['name']);
        self::assertSame('January 2031', $won[0]['periodLabel']);
        self::assertSame(1, $won[0]['place']);
        self::assertSame(12_300, $won[0]['prizePence']);
        self::assertSame(2, $won[0]['reputationDelta']);

        $award = $this->awardFor($summary, $definition);
        self::assertSame('History Me FC', $award['lastWinner']['clubName']);
        self::assertSame('January 2031', $award['lastWinner']['periodLabel']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function summary(Club $club): array
    {
        return self::getContainer()->get(RankingsSummaryService::class)->build($club, $this->now);
    }

    /** @return array<string, mixed> */
    private function awardFor(array $summary, LeaderboardAwardDefinition $definition): array
    {
        foreach ($summary['awards'] as $award) {
            if ($award['definitionId'] === (string) $definition->getId()) {
                return $award;
            }
        }
        self::fail('award not listed');
    }

    /** @param array<int, array{0:int,1:int}> $places place => [prizePence, reputationDelta] */
    private function definition(string $name, AwardCadence $cadence, bool $enabled, array $places = [1 => [1_000, 0]]): LeaderboardAwardDefinition
    {
        $definition = (new LeaderboardAwardDefinition())
            ->setName($name)
            ->setCategory(LeaderboardCategory::CLUB_REPUTATION)
            ->setCadence($cadence)
            ->setIsEnabled($enabled);

        foreach ($places as $place => [$prize, $rep]) {
            $definition->addPlacement((new LeaderboardAwardPlacement($place))->setPrizePence($prize)->setReputationDelta($rep));
        }

        return $this->persist($definition);
    }

    private function club(string $name, int $currentSeason): Club
    {
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@rankings.test'));
        $user->setPassword('x');

        $club = $this->persist(new Club($name, $user));
        $club->setCurrentSeason($currentSeason);

        return $club;
    }

    private function entry(Club $club, string $period, int $score): void
    {
        $entry = $this->persist(new LeaderboardEntry($club, LeaderboardCategory::CLUB_REPUTATION, $period));
        $entry->setScore($score);
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }
}
