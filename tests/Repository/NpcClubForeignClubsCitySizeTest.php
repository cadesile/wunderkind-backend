<?php

namespace App\Tests\Repository;

use App\Entity\NpcClub;
use App\Enum\CitySize;
use App\Repository\NpcClubRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class NpcClubForeignClubsCitySizeTest extends KernelTestCase
{
    private const COUNTRY = 'zy'; // synthetic 2-letter code, isolated from real generated data

    private EntityManagerInterface $em;
    /** @var NpcClub[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $club) {
            $managed = $this->em->find(NpcClub::class, $club->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    public function testFindForeignClubsReturnsCitySizeFields(): void
    {
        $club = new NpcClub(
            'Testopolis FC', self::COUNTRY, 1, 80, '#111111', '#eeeeee', 1_000_000, [],
            region: 'Test Region',
            citySize: CitySize::BIG,
            populationSize: 750_000,
            isCapital: true,
        );
        $this->em->persist($club);
        $this->cleanup[] = $club;
        $this->em->flush();

        /** @var NpcClubRepository $repo */
        $repo    = self::getContainer()->get(NpcClubRepository::class);
        $results = $repo->findForeignClubs('other-country-that-never-matches', null, 10);

        $match = null;
        foreach ($results as $row) {
            if ($row['id'] === (string) $club->getId()) {
                $match = $row;
                break;
            }
        }

        $this->assertNotNull($match, 'expected the persisted club to appear in findForeignClubs results');
        $this->assertSame('Test Region', $match['region']);
        $this->assertSame('BIG', $match['citySize']);
        $this->assertSame(750_000, $match['populationSize']);
        $this->assertTrue($match['isCapital']);
    }

    public function testFindForeignClubsHandlesDefaultCitySizeFields(): void
    {
        // No region/citySize/populationSize/isCapital passed — exercises the entity defaults
        // (region: null, citySize: MEDIUM, populationSize: 0, isCapital: false), matching
        // pre-existing NpcClub rows generated before the city-size feature shipped.
        $club = new NpcClub('Defaultville FC', self::COUNTRY, 1, 80, '#111111', '#eeeeee', 1_000_000, []);
        $this->em->persist($club);
        $this->cleanup[] = $club;
        $this->em->flush();

        /** @var NpcClubRepository $repo */
        $repo    = self::getContainer()->get(NpcClubRepository::class);
        $results = $repo->findForeignClubs('other-country-that-never-matches', null, 10);

        $match = null;
        foreach ($results as $row) {
            if ($row['id'] === (string) $club->getId()) {
                $match = $row;
                break;
            }
        }

        $this->assertNotNull($match);
        $this->assertNull($match['region']);
        $this->assertSame('MEDIUM', $match['citySize']);
        $this->assertSame(0, $match['populationSize']);
        $this->assertFalse($match['isCapital']);
    }

    public function testFindDistinctRegionsReturnsSortedUniqueNonNullRegions(): void
    {
        $withRegionA  = new NpcClub('Region A FC 1', self::COUNTRY, 1, 80, '#111111', '#eeeeee', 1_000_000, [], region: 'Zed Region');
        $withRegionA2 = new NpcClub('Region A FC 2', self::COUNTRY, 1, 80, '#111111', '#eeeeee', 1_000_000, [], region: 'Zed Region');
        $withRegionB  = new NpcClub('Region B FC', self::COUNTRY, 1, 80, '#111111', '#eeeeee', 1_000_000, [], region: 'Alpha Region');
        $withoutRegion = new NpcClub('No Region FC', self::COUNTRY, 1, 80, '#111111', '#eeeeee', 1_000_000, []);

        foreach ([$withRegionA, $withRegionA2, $withRegionB, $withoutRegion] as $club) {
            $this->em->persist($club);
            $this->cleanup[] = $club;
        }
        $this->em->flush();

        /** @var NpcClubRepository $repo */
        $repo    = self::getContainer()->get(NpcClubRepository::class);
        $regions = $repo->findDistinctRegions();

        $this->assertContains('Zed Region', $regions);
        $this->assertContains('Alpha Region', $regions);
        $this->assertSame(1, count(array_keys($regions, 'Zed Region')), 'duplicate region values must be deduplicated');
        $this->assertNotContains(null, $regions, 'null regions must be excluded');

        // Alphabetically sorted: 'Alpha Region' before 'Zed Region'.
        $this->assertLessThan(
            array_search('Zed Region', $regions, true),
            array_search('Alpha Region', $regions, true),
        );
    }
}
