<?php

namespace App\Tests\Entity;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class ClubLeagueFieldsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $user    = $this->createMock(User::class);
        $club = new Club('Test FC', $user);

        $this->assertNull($club->getCurrentLeague());
        $this->assertSame(1, $club->getCurrentSeason());
    }

    public function testSetCurrentLeague(): void
    {
        $user    = $this->createMock(User::class);
        $club = new Club('Test FC', $user);
        $league  = new League('EN', 8, 'League 8');

        $club->setCurrentLeague($league);
        $this->assertSame($league, $club->getCurrentLeague());

        $club->setCurrentLeague(null);
        $this->assertNull($club->getCurrentLeague());
    }

    public function testSetCurrentSeason(): void
    {
        $user    = $this->createMock(User::class);
        $club = new Club('Test FC', $user);

        $club->setCurrentSeason(3);
        $this->assertSame(3, $club->getCurrentSeason());
    }
}
