<?php

namespace App\Tests\Entity;

use App\Entity\Academy;
use App\Entity\MatchResult;
use App\Entity\NpcClub;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class MatchResultTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $user    = $this->createMock(User::class);
        $academy = new Academy('Test FC', $user);
        $club    = new NpcClub('Norwich Town', 'EN', 8, 12, '#ffffff', '#000000', 100000, []);

        $result = new MatchResult(
            academy:      $academy,
            opponentClub: $club,
            goalsFor:     2,
            goalsAgainst: 1,
            week:         14,
            season:       1,
        );

        $this->assertSame($academy, $result->getAcademy());
        $this->assertSame($club,    $result->getOpponentClub());
        $this->assertSame(2,        $result->getGoalsFor());
        $this->assertSame(1,        $result->getGoalsAgainst());
        $this->assertSame(14,       $result->getWeek());
        $this->assertSame(1,        $result->getSeason());
        $this->assertNotNull($result->getId());
        $this->assertNotNull($result->getCreatedAt());
    }
}
