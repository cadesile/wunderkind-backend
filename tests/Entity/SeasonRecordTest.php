<?php

namespace App\Tests\Entity;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\SeasonRecord;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class SeasonRecordTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $user    = $this->createMock(User::class);
        $club = new Club('Test FC', $user);
        $league  = new League('EN', 8, 'League 8');

        $record = new SeasonRecord(
            club:       $club,
            league:        $league,
            season:        1,
            finalPosition: 2,
            gamesPlayed:   30,
            wins:          18,
            draws:         6,
            losses:        6,
            goalsFor:      55,
            goalsAgainst:  30,
            points:        60,
            promoted:      false,
            relegated:     false,
        );

        $this->assertSame($club, $record->getClub());
        $this->assertSame($league,  $record->getLeague());
        $this->assertSame(1,        $record->getSeason());
        $this->assertSame(2,        $record->getFinalPosition());
        $this->assertSame(30,       $record->getGamesPlayed());
        $this->assertSame(18,       $record->getWins());
        $this->assertSame(6,        $record->getDraws());
        $this->assertSame(6,        $record->getLosses());
        $this->assertSame(55,       $record->getGoalsFor());
        $this->assertSame(30,       $record->getGoalsAgainst());
        $this->assertSame(60,       $record->getPoints());
        $this->assertFalse($record->isPromoted());
        $this->assertFalse($record->isRelegated());
        $this->assertNotNull($record->getId());
        $this->assertNotNull($record->getCreatedAt());
    }
}
