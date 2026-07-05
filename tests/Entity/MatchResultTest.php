<?php

namespace App\Tests\Entity;

use App\Entity\Club;
use App\Entity\MatchResult;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class MatchResultTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $user     = $this->createMock(User::class);
        $userClub = new Club('Test FC', $user);

        $result = new MatchResult(
            club:         $userClub,
            goalsFor:     2,
            goalsAgainst: 1,
            week:         14,
            season:       1,
        );
        $result->setOpponentClubName('Norwich Town');

        $this->assertSame($userClub,      $result->getClub());
        $this->assertSame('Norwich Town', $result->getOpponentClubName());
        $this->assertSame(2,              $result->getGoalsFor());
        $this->assertSame(1,              $result->getGoalsAgainst());
        $this->assertSame(14,             $result->getWeek());
        $this->assertSame(1,              $result->getSeason());
        $this->assertNotNull($result->getId());
        $this->assertNotNull($result->getCreatedAt());
    }
}
