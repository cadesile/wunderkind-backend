<?php

namespace App\Tests\Entity;

use App\Entity\Club;
use App\Entity\SeasonSnapshot;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class SeasonSnapshotTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $user    = $this->createMock(User::class);
        $club = new Club('Test FC', $user);
        $data    = ['amp' => ['leagueTier' => 8, 'finalPosition' => 1], 'pyramid' => []];

        $snapshot = new SeasonSnapshot(
            club:      $club,
            season:       1,
            country:      'EN',
            snapshotData: $data,
        );

        $this->assertSame($club, $snapshot->getClub());
        $this->assertSame(1,        $snapshot->getSeason());
        $this->assertSame('EN',     $snapshot->getCountry());
        $this->assertSame($data,    $snapshot->getSnapshotData());
        $this->assertNotNull($snapshot->getId());
        $this->assertNotNull($snapshot->getCreatedAt());
    }
}
