<?php

namespace App\Tests\Service;

use App\Service\HallOfFameScoreService;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the pure weighting step of the derived Hall of Fame score.
 * The DB-backed paths (scoresByClub / syncClub) are exercised by
 * LeaderboardHallOfFameTest.
 */
class HallOfFameScoreServiceTest extends TestCase
{
    /** GameConfig::$leagueWinPoints defaults, as they come back from the json column. */
    private const WEIGHTS = [
        '1' => 10000000,
        '2' => 1000000,
        '3' => 100000,
        '4' => 10000,
        '5' => 1000,
        '6' => 100,
        '7' => 10,
        '8' => 1,
    ];

    public function testSingleTitleScoresItsTierWeight(): void
    {
        $scores = HallOfFameScoreService::applyWeights(
            [['clubId' => 'club-a', 'tier' => 3, 'titles' => 1]],
            self::WEIGHTS,
        );

        $this->assertSame(['club-a' => 100000], $scores);
    }

    public function testTitlesInTheSameTierAccumulate(): void
    {
        $scores = HallOfFameScoreService::applyWeights(
            [['clubId' => 'club-a', 'tier' => 5, 'titles' => 4]],
            self::WEIGHTS,
        );

        $this->assertSame(['club-a' => 4000], $scores);
    }

    public function testTitlesAcrossTiersSumPerClub(): void
    {
        $scores = HallOfFameScoreService::applyWeights(
            [
                ['clubId' => 'club-a', 'tier' => 2, 'titles' => 2],
                ['clubId' => 'club-a', 'tier' => 4, 'titles' => 1],
                ['clubId' => 'club-b', 'tier' => 8, 'titles' => 3],
            ],
            self::WEIGHTS,
        );

        $this->assertSame(2010000, $scores['club-a']);
        $this->assertSame(3, $scores['club-b']);
    }

    /**
     * The whole point of the 10x-per-tier drop documented on GameConfig::$leagueWinPoints:
     * one top-flight title must outrank any number of second-tier titles.
     */
    public function testOneTopFlightTitleOutweighsManyLowerDivisionTitles(): void
    {
        $scores = HallOfFameScoreService::applyWeights(
            [
                ['clubId' => 'champion',  'tier' => 1, 'titles' => 1],
                ['clubId' => 'yo-yo-club', 'tier' => 2, 'titles' => 9],
            ],
            self::WEIGHTS,
        );

        $this->assertGreaterThan($scores['yo-yo-club'], $scores['champion']);
    }

    public function testIntegerKeyedWeightMapIsAlsoAccepted(): void
    {
        $scores = HallOfFameScoreService::applyWeights(
            [['clubId' => 'club-a', 'tier' => 1, 'titles' => 1]],
            [1 => 10000000],
        );

        $this->assertSame(['club-a' => 10000000], $scores);
    }

    /** An admin can save a partial map — an unknown tier must score 0, not throw. */
    public function testUnknownTierScoresZero(): void
    {
        $scores = HallOfFameScoreService::applyWeights(
            [
                ['clubId' => 'club-a', 'tier' => 12, 'titles' => 5],
                ['clubId' => 'club-b', 'tier' => 1,  'titles' => 1],
            ],
            self::WEIGHTS,
        );

        $this->assertSame(0, $scores['club-a']);
        $this->assertSame(10000000, $scores['club-b']);
    }

    public function testEmptyWeightMapScoresEverythingZero(): void
    {
        $scores = HallOfFameScoreService::applyWeights(
            [['clubId' => 'club-a', 'tier' => 1, 'titles' => 3]],
            [],
        );

        $this->assertSame(['club-a' => 0], $scores);
    }

    public function testNoTitlesYieldsNoRows(): void
    {
        $this->assertSame([], HallOfFameScoreService::applyWeights([], self::WEIGHTS));
    }
}
