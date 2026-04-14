<?php

namespace App\Tests\Entity;

use App\Entity\Academy;
use App\Entity\League;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class AcademyLeagueFieldsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $user    = $this->createMock(User::class);
        $academy = new Academy('Test FC', $user);

        $this->assertNull($academy->getCurrentLeague());
        $this->assertSame(1, $academy->getCurrentSeason());
    }

    public function testSetCurrentLeague(): void
    {
        $user    = $this->createMock(User::class);
        $academy = new Academy('Test FC', $user);
        $league  = new League('EN', 8, 'League 8');

        $academy->setCurrentLeague($league);
        $this->assertSame($league, $academy->getCurrentLeague());

        $academy->setCurrentLeague(null);
        $this->assertNull($academy->getCurrentLeague());
    }

    public function testSetCurrentSeason(): void
    {
        $user    = $this->createMock(User::class);
        $academy = new Academy('Test FC', $user);

        $academy->setCurrentSeason(3);
        $this->assertSame(3, $academy->getCurrentSeason());
    }
}
