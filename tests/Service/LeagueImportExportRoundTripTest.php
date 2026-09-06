<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\League;
use App\Entity\NpcClub;
use App\Enum\CitySize;
use App\Enum\Formation;
use App\Enum\ReputationTier;
use App\Enum\TrophyColour;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Service\LeagueImportExportService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The world export is a hand-maintained field list, so it drifts as League and NpcClub grow
 * — citySize, populationSize, isCapital, region and abbreviation were all added to NpcClub
 * and never reached it, which meant a clear-then-import world round-trip quietly reset every
 * NPC club's city data to defaults.
 */
class LeagueImportExportRoundTripTest extends KernelTestCase
{
    /** Distinctive so it cannot collide with another test's synthetic country. */
    private const COUNTRY = 'ZQ';

    private LeagueImportExportService $service;
    private LeagueRepository $leagues;
    private NpcClubRepository $clubs;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(LeagueImportExportService::class);
        $this->leagues = self::getContainer()->get(LeagueRepository::class);
        $this->clubs   = self::getContainer()->get(NpcClubRepository::class);
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();
        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string, string, string[]}>
     */
    public static function worldEntityProvider(): array
    {
        return [
            // id and createdAt are server-side identity/bookkeeping; league association is
            // rebuilt from country+tier by relinkClubsToLeagues() rather than serialized.
            'League'  => [League::class, 'leagues', ['createdAt']],
            'NpcClub' => [NpcClub::class, 'clubs', ['createdAt', 'league']],
        ];
    }

    /**
     * The drift guard: a new column on League or NpcClub must appear in the world export.
     *
     * @param string[] $excluded
     */
    #[DataProvider('worldEntityProvider')]
    public function testEveryMappedColumnIsExported(string $class, string $section, array $excluded): void
    {
        $this->seed();
        $exported = $this->service->export()[$section][0] ?? null;
        self::assertIsArray($exported, 'Fixture did not reach the export.');

        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            if ($property->getAttributes(ORM\Column::class) === []
                || $property->getAttributes(ORM\Id::class) !== []
                || in_array($property->getName(), $excluded, true)) {
                continue;
            }

            self::assertArrayHasKey(
                $property->getName(),
                $exported,
                sprintf(
                    '%s::$%s is persisted but missing from the world export, so a '
                    . 'clear-then-import round-trip silently resets it.',
                    (new \ReflectionClass($class))->getShortName(),
                    $property->getName(),
                ),
            );
        }
    }

    /** Whatever the export emits must come back unchanged after a full clear-and-restore. */
    public function testWorldSurvivesClearAndReimport(): void
    {
        $this->seed();
        $exported = $this->service->export();

        $this->removeFixtures();
        self::assertCount(0, $this->clubs->findBy(['country' => self::COUNTRY]));

        $result = $this->service->import($exported);
        $this->em->clear();

        self::assertSame([], $result['errors']);
        self::assertTrue($result['applied']);

        $restored = $this->service->export();
        self::assertEquals(
            $this->onlyFixtures($exported),
            $this->onlyFixtures($restored),
            'A field the world export emits is not applied on import.',
        );
    }

    /** Regression: the city-size fields were lost entirely on every world re-import. */
    public function testCityDataSurvivesReimport(): void
    {
        $this->seed();
        $exported = $this->service->export();
        $this->removeFixtures();

        $this->service->import($exported);
        $this->em->clear();

        $club = $this->clubs->findOneBy(['country' => self::COUNTRY, 'name' => 'Probe Rovers']);

        self::assertSame(CitySize::BIG, $club->getCitySize());
        self::assertSame(1_234_567, $club->getPopulationSize());
        self::assertTrue($club->isCapital());
        self::assertSame('Probe Region', $club->getRegion());
        self::assertSame('PRV', $club->getAbbreviation());

        $league = $this->leagues->findOneBy(['country' => self::COUNTRY]);
        self::assertSame(6, $league->getSponsorCount());
        self::assertSame('trophy-3', $league->getTrophyImage());
        self::assertSame(TrophyColour::GOLD, $league->getTrophyColour());
    }

    /** A malformed row is reported, and the rest of the file still applies. */
    public function testBadRowIsReportedWithoutBlockingTheRest(): void
    {
        $this->seed();
        $exported = $this->service->export();
        $this->removeFixtures();

        $exported['clubs'][] = [
            'name'     => 'Broken United',
            'country'  => self::COUNTRY,
            'tier'     => 4,
            'citySize' => 'NOT_A_CITY_SIZE',
        ];

        $result = $this->service->import($exported);
        $this->em->clear();

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('Broken United', $result['errors'][0]);
        self::assertNotNull($this->clubs->findOneBy(['country' => self::COUNTRY, 'name' => 'Probe Rovers']));

        // The rejected row must not survive as a half-built entity: NpcClub is constructed
        // and persisted before the enum is parsed, so without detaching it the flush would
        // write a club whose import the operator was just told had failed.
        self::assertNull(
            $this->clubs->findOneBy(['country' => self::COUNTRY, 'name' => 'Broken United']),
            'A rejected row was persisted anyway.',
        );
    }

    private function seed(): void
    {
        $league = new League(self::COUNTRY, 4, 'Probe Division');
        $league->setPromotionSpots(2);
        $league->setTvDeal(500_000);
        $league->setPrizeMoney(250_000);
        $league->setLeaguePositionPot(75_000);
        $league->setLeagueReputationTier(ReputationTier::REGIONAL);
        $league->setSponsorCount(6);
        $league->setTrophyImage('trophy-3');
        $league->setTrophyColour(TrophyColour::GOLD);
        $this->em->persist($league);

        $club = new NpcClub(
            'Probe Rovers',
            self::COUNTRY,
            4,
            42,
            '#112233',
            '#445566',
            9_000_000,
            ['training' => 3],
            'Probe Region',
            CitySize::BIG,
            1_234_567,
            true,
        );
        $club->setAbbreviation('PRV');
        $club->setStadiumName('Probe Park');
        $club->setPlayingStyle('POSSESSION');
        $club->setFinancialApproach('CAUTIOUS');
        $club->setManagerTemperament(61);
        $club->setFormation(Formation::F_433);
        $this->em->persist($club);

        $this->em->flush();
        $this->em->clear();
    }

    /** Ignores whatever else lives in the dev/test world. */
    private function onlyFixtures(array $export): array
    {
        $filter = static fn (array $rows) => array_values(array_filter(
            $rows,
            static fn (array $row) => ($row['country'] ?? null) === self::COUNTRY,
        ));

        return [
            'leagues' => $filter($export['leagues']),
            'clubs'   => $filter($export['clubs']),
        ];
    }

    private function removeFixtures(): void
    {
        foreach ($this->clubs->findBy(['country' => self::COUNTRY]) as $club) {
            $this->em->remove($club);
        }
        $this->em->flush();

        foreach ($this->leagues->findBy(['country' => self::COUNTRY]) as $league) {
            $this->em->remove($league);
        }
        $this->em->flush();
        $this->em->clear();
    }
}
