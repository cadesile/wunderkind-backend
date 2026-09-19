<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ConcludeSeasonRequest;
use App\Entity\Club;
use App\Entity\League;
use App\Entity\SeasonRecord;
use App\Entity\SeasonSnapshot;
use App\Entity\User;
use App\Service\LeagueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Covers only the new archival fields added to ConcludeSeasonRequest (seasonLeaders,
 * seasonFixtures) — see the Sync & ConcludeSeason Archive handoff. concludeSeason()'s
 * promotion/relegation/pyramid-rebuild behavior is exercised elsewhere; not duplicated here.
 */
class LeagueServiceConcludeSeasonTest extends KernelTestCase
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

    public function testSeasonLeadersAndSeasonFixturesAreArchivedVerbatim(): void
    {
        $country = $this->randomCountryCode();
        $league  = $this->persist(new League($country, 3, 'Test Tier'));

        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@conclude.test'));
        $user->setPassword('x');
        $club = $this->persist(new Club('Conclude Season FC', $user));
        $club->setCurrentLeague($league);
        $this->em->flush();

        $seasonLeaders = [
            ['tier' => 'elite', 'leaders' => [
                ['playerId' => 'p1', 'playerName' => 'Top Scorer', 'goals' => 30, 'isUserClubPlayer' => true],
            ]],
        ];
        $seasonFixtures = [
            ['fixtureId' => 'f1', 'season' => 1, 'weekNumber' => 4, 'homeGoals' => 2, 'awayGoals' => 1, 'result' => 'HOME_WIN'],
        ];

        $dto                  = new ConcludeSeasonRequest();
        $dto->finalPosition   = 1;
        $dto->gamesPlayed     = 20;
        $dto->points          = 45;
        $dto->seasonLeaders   = $seasonLeaders;
        $dto->seasonFixtures  = $seasonFixtures;

        self::getContainer()->get(LeagueService::class)->concludeSeason($club, $dto);

        $snapshot = $this->em->getRepository(SeasonSnapshot::class)->findOneBy([
            'club'   => $club,
            'season' => 1,
        ]);
        $record = $this->em->getRepository(SeasonRecord::class)->findOneBy([
            'club'   => $club,
            'season' => 1,
        ]);
        $this->cleanup[] = $snapshot;
        $this->cleanup[] = $record;

        $this->assertNotNull($snapshot);
        $data = $snapshot->getSnapshotData();
        $this->assertSame($seasonLeaders, $data['seasonLeaders']);
        $this->assertSame($seasonFixtures, $data['seasonFixtures']);
    }

    private function randomCountryCode(): string
    {
        return chr(random_int(65, 90)) . chr(random_int(65, 90));
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }
}
