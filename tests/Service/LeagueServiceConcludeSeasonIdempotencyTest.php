<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ConcludeSeasonRequest;
use App\Entity\Club;
use App\Entity\League;
use App\Entity\SeasonRecord;
use App\Entity\SeasonSnapshot;
use App\Entity\User;
use App\Repository\SeasonRecordRepository;
use App\Repository\SeasonSnapshotRepository;
use App\Service\LeagueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * POST /api/league/conclude-season is queued and retried by the client, and a cancelled
 * request can never be proven not to have reached the server — so a second arrival must
 * be a no-op that answers exactly what the first one answered.
 *
 * Every occurrence of the old behaviour permanently corrupted a real player's history:
 * a duplicate SeasonRecord (which the Hall of Fame score is summed from, so a duplicated
 * title counted twice), a duplicate SeasonSnapshot, and a second `currentSeason + 1`
 * skipping a season forward.
 */
class LeagueServiceConcludeSeasonIdempotencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LeagueService $service;

    /** @var object[] Removed in reverse creation order in tearDown. */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(LeagueService::class);
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

    public function testADuplicateConcludeWritesNothingASecondTimeAndReplaysTheFirstAnswer(): void
    {
        [$club, $league] = $this->clubInLeague('Idem Duplicate FC', tier: 4);

        $first = $this->service->concludeSeason($club, $this->request());

        $this->assertSame(2, $club->getCurrentSeason(), 'precondition: the first call advances the season');
        $this->collectArchive($club);

        $second = $this->service->concludeSeason($club, $this->request());

        $this->assertSame($first, $second, 'a duplicate must replay the first response verbatim');
        $this->assertSame(2, $club->getCurrentSeason(), 'the season must not advance twice');
        $this->assertSame(1, $this->countRecords($club), 'exactly one SeasonRecord');
        $this->assertSame(1, $this->countSnapshots($club), 'exactly one SeasonSnapshot');
    }

    /**
     * The case a retry queue actually produces: the retry lands after the player has moved
     * on, so "the current season" is no longer the season being reported. Keying on the
     * payload's own `season` is what keeps this from concluding season 2 by accident.
     */
    public function testALateRetryForAnAlreadyConcludedSeasonDoesNotConcludeTheNextOne(): void
    {
        [$club, $league] = $this->clubInLeague('Idem Late Retry FC', tier: 5);

        $first = $this->service->concludeSeason($club, $this->request(season: 1));
        $this->collectArchive($club);

        // The player plays on; the queued retry only reaches the server now.
        $club->setCurrentSeason(4);
        $this->em->flush();

        $replay = $this->service->concludeSeason($club, $this->request(season: 1));

        $this->assertSame($first, $replay);
        $this->assertSame(4, $club->getCurrentSeason(), 'a late retry must not move the club on');
        $this->assertSame(1, $this->countRecords($club));
        $this->assertSame(1, $this->countSnapshots($club));
    }

    /**
     * Without the payload's `season`, an older client's retry is indistinguishable from a
     * fresh conclude of whatever season the club is on now — so it concludes that one.
     * This asserts the fallback deliberately keys on `currentSeason` for those clients,
     * because there is nothing better available; it is the reason the field was added.
     */
    public function testWithoutASeasonInThePayloadTheClubsCurrentSeasonIsUsed(): void
    {
        [$club] = $this->clubInLeague('Idem Legacy Client FC', tier: 6);
        $club->setCurrentSeason(3);
        $this->em->flush();

        $this->service->concludeSeason($club, $this->request(season: null));
        $this->collectArchive($club);

        $record = self::getContainer()->get(SeasonRecordRepository::class)
            ->findOneByClubAndSeason($club, 3);

        $this->assertNotNull($record, 'the record must be filed under the club’s current season');
        $this->assertSame(4, $club->getCurrentSeason());
    }

    /**
     * A payload for a season *ahead* of the server's count is accepted, and pulls the count
     * up to it. The gap means the server never received the seasons in between — which is
     * exactly when keeping the archive it did receive matters most. Rejecting it would be
     * worse than useless: the client's outbox drops a 4xx rather than retrying, so a 422
     * would silently and permanently lose that season and cascade into every later one
     * queued behind it.
     */
    public function testASeasonAheadOfTheServersCountIsArchivedAndPullsTheCountUp(): void
    {
        [$club] = $this->clubInLeague('Idem Future Season FC', tier: 7);

        $this->assertSame(1, $club->getCurrentSeason(), 'precondition');

        $this->service->concludeSeason($club, $this->request(season: 4));
        $this->collectArchive($club);

        $this->assertSame(5, $club->getCurrentSeason(), 'the count catches up to the payload');
        $this->assertNotNull(
            self::getContainer()->get(SeasonRecordRepository::class)->findOneByClubAndSeason($club, 4),
            'the archive must be kept, not discarded',
        );
    }

    /** And it is still idempotent on that season afterwards. */
    public function testAFutureSeasonArchiveIsStillReplayedOnRetry(): void
    {
        [$club] = $this->clubInLeague('Idem Future Replay FC', tier: 8);

        $first = $this->service->concludeSeason($club, $this->request(season: 4));
        $this->collectArchive($club);

        $replay = $this->service->concludeSeason($club, $this->request(season: 4));

        $this->assertSame($first, $replay);
        $this->assertSame(5, $club->getCurrentSeason(), 'and it does not advance again');
        $this->assertSame(1, $this->countRecords($club));
    }

    /**
     * The bound exists so a junk season number fails validation rather than reaching the
     * driver: `SeasonRecord::$season` is a SMALLINT, and an out-of-range value would be a
     * 500 the outbox retries forever rather than a 422 it drops.
     */
    public function testTheSeasonFieldIsBoundedWellInsideSmallint(): void
    {
        $dto         = $this->request(season: 40000);
        $violations  = self::getContainer()->get(\Symfony\Component\Validator\Validator\ValidatorInterface::class)
            ->validate($dto);

        $this->assertGreaterThan(0, count($violations));
        $this->assertSame('season', $violations[0]->getPropertyPath());
    }

    /**
     * The window where the first attempt committed the archive and then died before the
     * pyramid was built: the record exists, `response_json` is null. The retry must rebuild
     * and store it rather than 500 or hand back nothing — and the rebuild must not file a
     * second record.
     */
    public function testARecordWithNoStoredResponseIsRebuiltAndStoredOnTheNextRetry(): void
    {
        [$club] = $this->clubInLeague('Idem Half Written FC', tier: 3);

        $this->service->concludeSeason($club, $this->request(season: 1));
        $this->collectArchive($club);

        // Simulate the first attempt dying before storeConcludeResponse() ran.
        $this->em->getConnection()->executeStatement(
            'UPDATE season_snapshot SET response_json = NULL WHERE club_id = :club',
            ['club' => (string) $club->getId()],
        );
        $this->em->clear();

        $club     = $this->em->find(Club::class, $club->getId());
        $rebuilt  = $this->service->concludeSeason($club, $this->request(season: 1));

        $this->assertArrayHasKey('seasonRecordId', $rebuilt);
        $this->assertArrayHasKey('leagues', $rebuilt);
        $this->assertSame(1, $this->countRecords($club), 'the rebuild must not file a second record');

        $stored = self::getContainer()->get(SeasonSnapshotRepository::class)
            ->findByClubAndSeason($club, 1)
            ?->getResponseJson();

        $this->assertSame($rebuilt, $stored, 'the rebuilt response must be stored for the next retry');
    }

    public function testTheStoredResponseIsTheBodyTheFirstCallReturned(): void
    {
        [$club] = $this->clubInLeague('Idem Stored Body FC', tier: 2);

        $first = $this->service->concludeSeason($club, $this->request(season: 1));
        $this->collectArchive($club);

        $this->em->clear();
        $club   = $this->em->find(Club::class, $club->getId());
        $stored = self::getContainer()->get(SeasonSnapshotRepository::class)
            ->findByClubAndSeason($club, 1)
            ?->getResponseJson();

        $this->assertSame($first, $stored);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @return array{0: Club, 1: League} */
    private function clubInLeague(string $name, int $tier): array
    {
        $country = $this->unusedCountryCode();
        $league  = $this->persist(new League($country, $tier, 'Idem Test Tier ' . $tier));

        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@conclude.test'));
        $user->setPassword('x');

        $club = $this->persist(new Club($name, $user));
        $club->setCountry($country);
        $club->setCurrentLeague($league);
        $this->em->flush();

        return [$club, $league];
    }

    private function request(?int $season = 1): ConcludeSeasonRequest
    {
        $dto                = new ConcludeSeasonRequest();
        $dto->season        = $season;
        $dto->finalPosition = 3;
        $dto->gamesPlayed   = 38;
        $dto->wins          = 20;
        $dto->draws         = 9;
        $dto->losses        = 9;
        $dto->goalsFor      = 61;
        $dto->goalsAgainst  = 44;
        $dto->points        = 69;

        return $dto;
    }

    /**
     * concludeSeason() persists its own archive rows, so they are not in $cleanup. Register
     * them before asserting, so a failing assertion still tears the data down.
     */
    private function collectArchive(Club $club): void
    {
        foreach ($this->em->getRepository(SeasonRecord::class)->findBy(['club' => $club]) as $row) {
            $this->cleanup[] = $row;
        }
        foreach ($this->em->getRepository(SeasonSnapshot::class)->findBy(['club' => $club]) as $row) {
            $this->cleanup[] = $row;
        }
    }

    private function countRecords(Club $club): int
    {
        return count($this->em->getRepository(SeasonRecord::class)->findBy(['club' => $club]));
    }

    private function countSnapshots(Club $club): int
    {
        return count($this->em->getRepository(SeasonSnapshot::class)->findBy(['club' => $club]));
    }

    /** uq_league_country_tier spans only 676 two-letter codes — don't collide with a leaked row. */
    private function unusedCountryCode(): string
    {
        $repo = self::getContainer()->get(\App\Repository\LeagueRepository::class);

        for ($i = 0; $i < 50; $i++) {
            $code = chr(random_int(65, 90)) . chr(random_int(65, 90));
            if ($repo->findByCountry($code) === []) {
                return $code;
            }
        }

        self::fail('could not find an unused country code');
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }
}
