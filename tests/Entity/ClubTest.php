<?php

namespace App\Tests\Entity;

use App\Entity\Club;
use App\Entity\User;
use App\Enum\Formation;
use PHPUnit\Framework\TestCase;

class ClubTest extends TestCase
{
    public function testClubInstantiation(): void
    {
        $user = $this->createMock(User::class);
        $club = new Club('Test FC', $user);
        $this->assertInstanceOf(Club::class, $club);
        $this->assertSame('Test FC', $club->getName());
        $this->assertSame($user, $club->getUser());
        $this->assertSame(Formation::F_442, $club->getFormation());
    }

    public function testFormationSetter(): void
    {
        $user = $this->createMock(User::class);
        $club = new Club('Test FC', $user);
        $club->setFormation(Formation::F_433);
        $this->assertSame(Formation::F_433, $club->getFormation());
    }
}
