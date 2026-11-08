<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\NpcClub;
use App\Enum\CitySize;
use App\Service\WorldPackSnapshotBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The `land` block on an NPC club in the world pack.
 *
 * ADDITIVE, AND THAT IS THE PART WORTH TESTING. The frontend ships behind the backend, so a
 * new key may appear but nothing a client already reads may move or change meaning — see the
 * API-compatibility rule. These pin that `region`, `citySize`, `populationSize` and
 * `isCapital` are all still exactly where they were, alongside the new block.
 */
class WorldPackLandSnapshotTest extends KernelTestCase
{
    private const CODE = 'WL';

    private EntityManagerInterface $em;
    private WorldPackSnapshotBuilder $builder;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(WorldPackSnapshotBuilder::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        $c = self::getContainer()->get(Connection::class);
        $c->executeStatement('UPDATE npc_club SET land_plot_id = NULL WHERE country = :c', ['c' => self::CODE]);
        $c->executeStatement('DELETE FROM npc_club WHERE country = :c', ['c' => self::CODE]);
        $c->executeStatement(
            'DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::CODE],
        );
        $c->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::CODE]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::CODE]);
        $this->em->clear();
    }

    private function seed(bool $withPlot, ?int $plotOverridePence = null): NpcClub
    {
        $profile = new CountryProfile(self::CODE, 'Worldland');
        $this->em->persist($profile);

        $location = new Location($profile, 'London', self::CODE . '-london', 'london');
        $location->setRegion('Greater London');
        $location->setPopulation(8_982_000);
        $location->setCoordinates(51.5, -0.12);
        $location->setLandPence(15_000_000_000);
        $location->setLandValuedAt(new \DateTimeImmutable());
        $location->setIsCapital(true);
        $this->em->persist($location);

        $plot = null;
        if ($withPlot) {
            $plot = new LandPlot($location, 1);
            if ($plotOverridePence !== null) {
                $plot->setLandPenceOverride($plotOverridePence);
            }
            $this->em->persist($plot);
        }

        $club = new NpcClub(
            name: 'London FC', country: self::CODE, tier: 1, reputation: 85,
            primaryColor: '#111111', secondaryColor: '#eeeeee', balance: 5_000_000,
            facilities: [], region: 'Greater London', citySize: CitySize::BIG,
            populationSize: 8_982_000, isCapital: true, location: $location,
        );
        $club->setLandPlot($plot);

        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    public function testASeatedClubCarriesItsTownAndItsPlot(): void
    {
        $snapshot = $this->builder->buildClubSnapshot($this->seed(withPlot: true), [], [], []);

        self::assertSame(
            [
                'name'       => 'London',
                'region'     => 'Greater London',
                'plotNumber' => 1,
                'capital'    => true,
                'valuePence' => 15_000_000_000,
            ],
            $snapshot['land'],
        );
    }

    /** An unseated club still reports its town — it just has no plot number to give. */
    public function testAnUnseatedClubReportsTheTownWithANullPlotNumber(): void
    {
        $snapshot = $this->builder->buildClubSnapshot($this->seed(withPlot: false), [], [], []);

        self::assertSame('London', $snapshot['land']['name']);
        self::assertNull($snapshot['land']['plotNumber']);
        self::assertSame(15_000_000_000, $snapshot['land']['valuePence'], 'the town value still applies');
    }

    /**
     * The plot's own price wins where it has one, because that is the figure the catalogue
     * quotes a player — the pack and the shop must agree on what this ground costs.
     */
    public function testAPlotOverrideBeatsTheTownValue(): void
    {
        $snapshot = $this->builder->buildClubSnapshot(
            $this->seed(withPlot: true, plotOverridePence: 20_000_000_000),
            [], [], [],
        );

        self::assertSame(20_000_000_000, $snapshot['land']['valuePence']);
    }

    /** A club generated before its country had locations has no town, and says so. */
    public function testAClubWithNoTownHasNullLand(): void
    {
        $club = new NpcClub(
            name: 'Capital FC', country: self::CODE, tier: 4, reputation: 40,
            primaryColor: '#111111', secondaryColor: '#eeeeee', balance: 1_000_000,
            facilities: [], citySize: CitySize::MEDIUM,
        );

        self::assertNull($this->builder->buildClubSnapshot($club, [], [], [])['land']);
    }

    /**
     * THE COMPATIBILITY ASSERTION. Everything a client reads today must still be there, with
     * the same name and the same meaning — `land` is an addition, not a replacement for the
     * four town fields that were already on the club.
     */
    public function testTheExistingClubKeysAreUntouched(): void
    {
        $snapshot = $this->builder->buildClubSnapshot($this->seed(withPlot: true), [], [], []);

        foreach ([
            'id', 'name', 'abbreviation', 'tier', 'reputation', 'startingBalance',
            'primaryColor', 'secondaryColor', 'identity', 'stadiumName', 'facilities',
            'region', 'citySize', 'populationSize', 'isCapital', 'personality',
            'players', 'staff', 'scouts',
        ] as $key) {
            self::assertArrayHasKey($key, $snapshot, "{$key} must survive the land addition");
        }

        self::assertSame('Greater London', $snapshot['region']);
        self::assertTrue($snapshot['isCapital']);
        self::assertSame(8_982_000, $snapshot['populationSize']);
        self::assertSame('BIG', $snapshot['citySize']);
    }
}
