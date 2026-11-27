<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Service\Location\OwnedPlotsMapService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The homepage plot map's data: active claims grouped into one marker per town, owner names
 * and club names only. The query is stubbed; what is tested is the grouping and fallbacks.
 */
class OwnedPlotsMapServiceTest extends TestCase
{
    public function testGroupsClaimsByTownWithOwnersAndClubs(): void
    {
        $rows = [
            self::row('loc-1', 'Manchester', 'EN', 'England', 'Courtney', 'Factory FC', 'club-1'),
            self::row('loc-1', 'Manchester', 'EN', 'England', null, 'Salford Rovers', 'club-2'),
            // Owned but empty: the club was liquidated, the claim name is a leftover.
            self::row('loc-1', 'Manchester', 'EN', 'England', 'Priya', 'Old Name FC', null),
            self::row('loc-2', 'Bari', 'IT', null, '  ', 'Bari Vecchia', 'club-3'),
        ];

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturn($rows);

        $service = new OwnedPlotsMapService($connection, new ArrayAdapter());
        $map = $service->getMap();
        $service->getMap(); // second read comes from the cache (fetch expected once)

        self::assertSame(['plots' => 4, 'towns' => 2, 'countries' => 2], $map['totals']);

        [$manchester, $bari] = $map['towns'];
        self::assertSame('Manchester', $manchester['town']);
        self::assertSame(3, $manchester['plotCount']);
        self::assertSame(53.4808, $manchester['lat']);
        self::assertSame([
            ['ownerName' => 'Courtney', 'clubName' => 'Factory FC'],
            ['ownerName' => 'Anonymous', 'clubName' => 'Salford Rovers'],
            ['ownerName' => 'Priya', 'clubName' => null],
        ], $manchester['owners']);

        self::assertSame('IT', $bari['countryName'], 'falls back to the code without a country profile');
        self::assertSame('Anonymous', $bari['owners'][0]['ownerName'], 'a blank name is Anonymous');
    }

    public function testOwnerListIsCappedButCountIsNot(): void
    {
        $rows = [];
        for ($i = 0; $i < OwnedPlotsMapService::OWNERS_PER_TOWN + 3; $i++) {
            $rows[] = self::row('loc-1', 'Tokyo', 'JP', 'Japan', "Owner $i", "Club $i", "club-$i");
        }

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        $town = (new OwnedPlotsMapService($connection, new ArrayAdapter()))->getMap()['towns'][0];

        self::assertSame(OwnedPlotsMapService::OWNERS_PER_TOWN + 3, $town['plotCount']);
        self::assertCount(OwnedPlotsMapService::OWNERS_PER_TOWN, $town['owners']);
    }

    public function testNoClaimsIsAnEmptyMap(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $map = (new OwnedPlotsMapService($connection, new ArrayAdapter()))->getMap();

        self::assertSame([], $map['towns']);
        self::assertSame(['plots' => 0, 'towns' => 0, 'countries' => 0], $map['totals']);
    }

    /** @return array<string, mixed> */
    private static function row(string $loc, string $town, string $cc, ?string $country, ?string $owner, ?string $club, ?string $clubId): array
    {
        return [
            'location_id'  => $loc,
            'town'         => $town,
            'region'       => 'Region',
            'country_code' => $cc,
            'lat'          => '53.480759',
            'lng'          => '-2.242631',
            'country_name' => $country,
            'owner_name'   => $owner,
            'club_name'    => $club,
            'club_id'      => $clubId,
        ];
    }
}
