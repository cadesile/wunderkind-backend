<?php

namespace App\Tests\Entity;

use App\Entity\League;
use App\Entity\NpcClub;
use PHPUnit\Framework\TestCase;

class NpcClubLeagueFieldTest extends TestCase
{
    public function testLeagueDefaultsToNull(): void
    {
        $club = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);
        $this->assertNull($club->getLeague());
    }

    public function testSetLeague(): void
    {
        $club   = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);
        $league = new League('EN', 8, 'League 8');

        $club->setLeague($league);
        $this->assertSame($league, $club->getLeague());

        $club->setLeague(null);
        $this->assertNull($club->getLeague());
    }
}
