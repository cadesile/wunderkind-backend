<?php

namespace App\Tests\Entity;

use App\Entity\Academy;
use App\Entity\SeasonSnapshot;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class SeasonSnapshotTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $user    = $this->createMock(User::class);
        $academy = new Academy('Test FC', $user);
        $data    = ['amp' => ['leagueTier' => 8, 'finalPosition' => 1], 'pyramid' => []];

        $snapshot = new SeasonSnapshot(
            academy:      $academy,
            season:       1,
            country:      'EN',
            snapshotData: $data,
        );

        $this->assertSame($academy, $snapshot->getAcademy());
        $this->assertSame(1,        $snapshot->getSeason());
        $this->assertSame('EN',     $snapshot->getCountry());
        $this->assertSame($data,    $snapshot->getSnapshotData());
        $this->assertNotNull($snapshot->getId());
        $this->assertNotNull($snapshot->getCreatedAt());
    }
}
