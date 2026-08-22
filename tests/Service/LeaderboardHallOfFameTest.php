<?php

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\LeaderboardEntry;
use App\Entity\SeasonRecord;
use App\Entity\User;
use App\Enum\LeaderboardCategory;
use App\Repository\GameConfigRepository;
use App\Repository\LeaderboardEntryRepository;
use App\Service\HallOfFameScoreService;
use App\Service\LeaderboardCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end cover for the derived `hall_of_fame` leaderboard: SeasonRecord titles →
 * tier-weighted score → LeaderboardEntry + Club::$hallOfFamePoints.
 */
class LeaderboardHallOfFameTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /**
     * Everything persisted here, in creation order, removed again in tearDown.
     * The League especially: uq_league_country_tier is UNIQUE (country, tier) over
     * only 676 two-letter codes, so leaked rows accumulate until unrelated tests
     * start colliding.
     *
     * @var object[]
     */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        // Reverse creation order so dependents go before what they reference.
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

    public function testTitlesProduceATierWeightedScoreOnTheLeaderboard(): void
    {
        $country = $this->randomCountryCode();
        $topTier = $this->persist(new League($country, 1, 'Test Top Flight'));
        $lowTier = $this->persist(new League($country, 4, 'Test Fourth Tier'));

        $champion  = $this->persistClub('HoF Champion FC');
        $lowerWins = $this->persistClub('HoF Lower Division FC');
        $winless   = $this->persistClub('HoF Winless FC');

        $this->persistSeason($champion, $topTier, 1, finalPosition: 1);
        $this->persistSeason($lowerWins, $lowTier, 1, finalPosition: 1);
        $this->persistSeason($lowerWins, $lowTier, 2, finalPosition: 1);
        // A runner-up finish earns nothing.
        $this->persistSeason($winless, $topTier, 1, finalPosition: 2);

        $this->em->flush();

        $weights = self::getContainer()->get(GameConfigRepository::class)
            ->getConfig()->getLeagueWinPoints();
        $topWeight = (int) ($weights['1'] ?? $weights[1]);
        $lowWeight = (int) ($weights['4'] ?? $weights[4]);

        $scores = self::getContainer()->get(HallOfFameScoreService::class)->scoresByClub();

        $this->assertSame($topWeight, $scores[(string) $champion->getId()]);
        $this->assertSame($lowWeight * 2, $scores[(string) $lowerWins->getId()]);
        $this->assertArrayNotHasKey((string) $winless->getId(), $scores);

        // Recalculate the board and read the persisted entries back.
        $calc = self::getContainer()->get(LeaderboardCalculationService::class);
        $calc->recalculate(LeaderboardCategory::HALL_OF_FAME, 'all-time');

        $entryRepo = self::getContainer()->get(LeaderboardEntryRepository::class);
        $championEntry = $entryRepo->findOneBy([
            'club'     => $champion,
            'category' => LeaderboardCategory::HALL_OF_FAME,
            'period'   => 'all-time',
        ]);
        $lowerEntry = $entryRepo->findOneBy([
            'club'     => $lowerWins,
            'category' => LeaderboardCategory::HALL_OF_FAME,
            'period'   => 'all-time',
        ]);

        // Register for cleanup before asserting, so a failing assertion still tears down.
        foreach ([$championEntry, $lowerEntry] as $entry) {
            if ($entry !== null) {
                $this->cleanup[] = $entry;
            }
        }

        $this->assertNotNull($championEntry, 'a title should create a hall_of_fame entry');
        $this->assertSame($topWeight, $championEntry->getScore());
        $this->assertNotNull($lowerEntry);
        $this->assertSame($lowWeight * 2, $lowerEntry->getScore());

        // The whole point of the 10x-per-tier drop: one top-flight title outranks two fourth-tier ones.
        $this->assertLessThan($lowerEntry->getRank(), $championEntry->getRank());
    }

    public function testSyncAllClubsMirrorsTheScoreOntoTheClub(): void
    {
        $country = $this->randomCountryCode();
        $league  = $this->persist(new League($country, 2, 'Test Second Tier'));
        $club    = $this->persistClub('HoF Mirror FC');

        $this->persistSeason($club, $league, 1, finalPosition: 1);
        $this->em->flush();

        $this->assertSame(0, $club->getHallOfFamePoints(), 'precondition: starts at 0');

        $hof = self::getContainer()->get(HallOfFameScoreService::class);
        $hof->syncAllClubs();

        $weights = self::getContainer()->get(GameConfigRepository::class)
            ->getConfig()->getLeagueWinPoints();
        $expected = (int) ($weights['2'] ?? $weights[2]);

        $this->em->refresh($club);
        $this->assertSame($expected, $club->getHallOfFamePoints());
    }

    /**
     * Backfill safety: before this change the score came from the client, so production can
     * hold hall_of_fame entries with a nonzero score for a club that has never won anything.
     * The aggregate pass only writes rows for clubs that scored, so such a row must be
     * explicitly reset — otherwise it outranks every legitimate title winner forever.
     */
    public function testStaleClientSuppliedScoreIsResetForATitlelessClub(): void
    {
        $club = $this->persistClub('HoF Stale Score FC');

        $entry = $this->persist(
            new LeaderboardEntry($club, LeaderboardCategory::HALL_OF_FAME, 'all-time')
        );
        $entry->setScore(999999999);
        $club->setHallOfFamePoints(999999999);
        $this->em->flush();

        self::getContainer()->get(LeaderboardCalculationService::class)
            ->recalculate(LeaderboardCategory::HALL_OF_FAME, 'all-time');

        $this->em->refresh($entry);
        $this->assertSame(0, $entry->getScore(), 'a club with no titles must score 0');

        // Club::$hallOfFamePoints is mirrored by the backfill step, not by recalculate().
        self::getContainer()->get(HallOfFameScoreService::class)->syncAllClubs();
        $this->em->refresh($club);
        $this->assertSame(0, $club->getHallOfFamePoints());
    }

    /**
     * The old behaviour: a client could send any hallOfFamePoints and the server stored it.
     * Sync must no longer touch the field.
     */
    public function testSyncRequestHallOfFamePointsIsIgnored(): void
    {
        $reflection = new \ReflectionClass(\App\Service\SyncService::class);
        $source     = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString(
            'setHallOfFamePoints',
            $source,
            'SyncService must not write hallOfFamePoints — it is derived from SeasonRecord',
        );
    }

    private function randomCountryCode(): string
    {
        return chr(random_int(65, 90)) . chr(random_int(65, 90));
    }

    private function persistClub(string $name): Club
    {
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@hof.test'));
        $user->setPassword('x');

        return $this->persist(new Club($name, $user));
    }

    private function persistSeason(Club $club, League $league, int $season, int $finalPosition): SeasonRecord
    {
        return $this->persist(new SeasonRecord(
            club: $club,
            league: $league,
            season: $season,
            finalPosition: $finalPosition,
            gamesPlayed: 38,
            wins: 25,
            draws: 8,
            losses: 5,
            goalsFor: 80,
            goalsAgainst: 30,
            points: 83,
            promoted: false,
            relegated: false,
        ));
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }
}
