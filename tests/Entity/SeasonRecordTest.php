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
        $user    = $this->createStub(User::class);
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

        $this->assertSame(0, $record->getOverwriteCount(), 'a fresh record has never been corrected');
        $this->assertNull($record->getOverwriteAudit());
        $this->assertNull($record->getOverwriteAuditPretty());
    }

    public function testMatchesResultIsTrueForTheSameFigures(): void
    {
        $record = $this->record();

        $this->assertTrue($record->matchesResult($record->resultFields()));
    }

    /**
     * Each of the ten fields on its own must break the match. This is the test that stops a
     * field being dropped from resultFields() — which would not fail anything else, it would
     * just silently make a correction to that field look like a retry and be swallowed.
     */
    public function testMatchesResultIsFalseWhenAnySingleFieldDiffers(): void
    {
        $record = $this->record();
        $base   = $record->resultFields();

        $this->assertCount(10, $base, 'ten fields define a result');

        foreach ($base as $field => $value) {
            $changed         = $base;
            $changed[$field] = is_bool($value) ? !$value : $value + 1;

            $this->assertFalse(
                $record->matchesResult($changed),
                sprintf('a different %s must count as a correction', $field),
            );
        }
    }

    /** A caller that omits a field gets an overwrite it can see, not a silent no-op. */
    public function testMatchesResultIsFalseWhenAFieldIsMissing(): void
    {
        $record = $this->record();
        $result = $record->resultFields();
        unset($result['points']);

        $this->assertFalse($record->matchesResult($result));
    }

    /** The loose-comparison trap: "60" is not 60, and a correction must not be hidden by it. */
    public function testMatchesResultComparesStrictly(): void
    {
        $record = $this->record();
        $result = $record->resultFields();

        $result['points']   = '60';
        $result['promoted'] = 0;

        $this->assertFalse($record->matchesResult($result));
    }

    public function testOverwriteWithAppliesTheNewResultAndFilesThePreviousOne(): void
    {
        $record = $this->record();
        $at     = new \DateTimeImmutable('2026-11-01T14:02:11+00:00');

        $record->overwriteWith([
            'finalPosition' => 1,
            'gamesPlayed'   => 30,
            'wins'          => 24,
            'draws'         => 3,
            'losses'        => 3,
            'goalsFor'      => 70,
            'goalsAgainst'  => 22,
            'points'        => 75,
            'promoted'      => true,
            'relegated'     => false,
        ], $at);

        $this->assertSame(1,  $record->getFinalPosition());
        $this->assertSame(24, $record->getWins());
        $this->assertSame(3,  $record->getDraws());
        $this->assertSame(3,  $record->getLosses());
        $this->assertSame(70, $record->getGoalsFor());
        $this->assertSame(22, $record->getGoalsAgainst());
        $this->assertSame(75, $record->getPoints());
        $this->assertTrue($record->isPromoted());
        $this->assertFalse($record->isRelegated());

        $this->assertSame(1, $record->getOverwriteCount());
        $this->assertSame(
            [[
                'overwrittenAt' => '2026-11-01T14:02:11+00:00',
                'finalPosition' => 2,
                'wins'          => 18,
                'draws'         => 6,
                'losses'        => 6,
            ]],
            $record->getOverwriteAudit(),
            'the audit carries the superseded position and W/D/L, not the new ones',
        );
    }

    public function testSuccessiveOverwritesAppendOldestFirstAndKeepTheCountInStep(): void
    {
        $record = $this->record();

        foreach ([2, 1] as $i => $position) {
            $result                  = $record->resultFields();
            $result['finalPosition'] = $position;
            $record->overwriteWith($result, new \DateTimeImmutable(sprintf('2026-11-0%d 12:00:00', $i + 1)));
        }

        $this->assertSame(2, $record->getOverwriteCount());
        $this->assertSame(
            [2, 2],
            array_column($record->getOverwriteAudit(), 'finalPosition'),
            'the record started at 2, and the first correction set it to 2 again',
        );
        $this->assertSame(1, $record->getFinalPosition());
        $this->assertCount($record->getOverwriteCount(), $record->getOverwriteAudit());
    }

    /**
     * The audit must encode as a JSON *list*. A sparse array would come back out as a JSON
     * object and break every consumer that iterates it — see CLAUDE.md on AppearanceType's
     * tattoos list for the same trap.
     */
    public function testTheAuditEncodesAsAJsonList(): void
    {
        $record = $this->record();
        $record->overwriteWith($record->resultFields(), new \DateTimeImmutable());
        $record->overwriteWith($record->resultFields(), new \DateTimeImmutable());

        $json = $record->getOverwriteAuditPretty();

        $this->assertIsString($json);
        $this->assertStringStartsWith('[', $json);
        $this->assertSame($record->getOverwriteAudit(), json_decode($json, true));
    }

    private function record(): SeasonRecord
    {
        return new SeasonRecord(
            club:          new Club('Test FC', $this->createStub(User::class)),
            league:        new League('EN', 8, 'League 8'),
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
    }
}
