<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\NpcClub;
use App\Enum\CitySize;
use App\Repository\NpcClubRepository;
use App\Service\Location\LocationCapacityService;
use App\Service\Location\NpcClubLandService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * `GET /api/initialize/land`'s rows: what the device stores on each NPC club as `landData`
 * and values the club's land from. Pure — entities in memory, capacity from a stubbed query.
 */
class NpcClubLandServiceTest extends TestCase
{
    public function testSeatedUnseatedAndTownlessClubs(): void
    {
        $town = new Location(new CountryProfile('IT', 'Italy'), 'Brindisi', 'brindisi', 'brindisi');
        $town->setRegion('Apulia');
        $town->setLandPence(300_000_00);
        $town->setPopulation(86_000);
        $town->setIsCapital(false);
        $town->setCitySize(CitySize::MEDIUM);

        $plot = new LandPlot($town, 1);
        $plot->setLandPenceOverride(450_000_00);

        $seated   = (new NpcClub('Brindisi US', 'IT', 3, 46, '#111111', '#eeeeee', 100_000, [], citySize: CitySize::MEDIUM))
            ->setLocation($town)->setLandPlot($plot);
        $unseated = (new NpcClub('Brindisi Nord', 'IT', 5, 30, '#111111', '#eeeeee', 100_000, [], citySize: CitySize::MEDIUM))
            ->setLocation($town);
        $townless = new NpcClub('Capital FC', 'IT', 6, 20, '#111111', '#eeeeee', 100_000, [], citySize: CitySize::MEDIUM);

        $repo = $this->createStub(NpcClubRepository::class);
        $repo->method('findByCountryWithLand')->willReturn([$seated, $unseated, $townless]);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => (string) $town->getId(), 'plots' => 2, 'npc_clubs' => 2, 'claimed' => 0],
        ]);

        $rows = (new NpcClubLandService($repo, new LocationCapacityService($connection)))->forCountry('IT');

        self::assertCount(2, $rows, 'a club with no town is left out');

        self::assertSame((string) $seated->getId(), $rows[0]['clubId']);
        self::assertSame((string) $plot->getId(), $rows[0]['plotId']);
        self::assertSame(1, $rows[0]['plotNumber']);
        self::assertSame(450_000_00, $rows[0]['plotLandPence'], 'the plot override wins');
        self::assertSame(300_000_00, $rows[0]['townLandPence']);
        self::assertSame('Apulia', $rows[0]['region']);
        self::assertSame(2, $rows[0]['plots']);
        self::assertSame(2, $rows[0]['npcOccupied']);
        self::assertSame(0, $rows[0]['available']);

        self::assertNull($rows[1]['plotId'], 'a town\'s unseated club has no plot');
        self::assertNull($rows[1]['plotLandPence']);
        self::assertSame(300_000_00, $rows[1]['townLandPence']);
    }
}
