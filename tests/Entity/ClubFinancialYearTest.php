<?php

namespace App\Tests\Entity;

use App\Entity\Club;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Clients sync in ~4-week batches, so the financial-year end has to be detected from the
 * span a sync reports rather than from its end week landing exactly on week % 52 === 0.
 */
class ClubFinancialYearTest extends TestCase
{
    private function club(): Club
    {
        return new Club('Test FC', $this->createMock(User::class));
    }

    public function testBatchSpanningWeek52FiresOnce(): void
    {
        $club = $this->club();
        // A single sync reporting weeks 50-53 never lands on 52, but does cross the boundary.
        $this->assertTrue($club->crossedFinancialYearEnd(49, 53));
    }

    public function testBatchInsideTheSameYearDoesNotFire(): void
    {
        $club = $this->club();
        $this->assertFalse($club->crossedFinancialYearEnd(5, 9));
        $this->assertFalse($club->crossedFinancialYearEnd(49, 51));
    }

    public function testReplayedSyncDoesNotFireTwice(): void
    {
        $club = $this->club();
        // Week 53 already recorded — re-sending it must not pay the dividend again.
        $this->assertFalse($club->crossedFinancialYearEnd(53, 53));
    }

    public function testFirstEverSyncDoesNotFire(): void
    {
        $club = $this->club();
        // lastSyncedWeek defaults to 0; the week-1 baseline sync is not a year end.
        $this->assertFalse($club->crossedFinancialYearEnd(0, 1));
    }

    public function testSecondFinancialYearFires(): void
    {
        $club = $this->club();
        $this->assertTrue($club->crossedFinancialYearEnd(101, 105));
    }

    public function testRollbackSpanDoesNotFire(): void
    {
        $club = $this->club();
        // Client rolled back from 53 to 50 — going backwards is never a year end.
        $this->assertFalse($club->crossedFinancialYearEnd(53, 50));
    }
}
