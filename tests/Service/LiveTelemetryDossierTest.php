<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LiveTelemetryService;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the boardroom overlay's per-club dossier — the "best rated
 * player" and "latest fixtures" blocks on the incident modal.
 *
 * Both builders are pure statics over already-fetched payload arrays, matching the
 * rest of LiveTelemetryService's build* methods, so no database is involved. The
 * MatchResult -> fixtures mapping lives in attachClubDossiers() (which does touch a
 * repository) and is supplied here as the already-shaped array it produces.
 *
 * The payload fixtures below mirror the real shape observed in production
 * sync_record rows: playerStats[] carries playerId/playerName/playerAge/
 * appearances/goals/assists/averageRating and NO position, while position lives on
 * fixtures[].{home,away}PlayerRatings[] keyed by the same playerId.
 */
class LiveTelemetryDossierTest extends TestCase
{
    /** @return array<string, mixed> */
    private function syncRow(
        string $clubId,
        array $playerStats,
        array $fixtures = [],
        string $at = '2026-10-12 10:00:00',
        ?array $homeKitConfig = null,
    ): array {
        return [
            'clubId'          => $clubId,
            'clubName'        => 'Test Club',
            'serverTimestamp' => new \DateTimeImmutable($at),
            'homeKitConfig'   => $homeKitConfig,
            'payload'         => ['playerStats' => $playerStats, 'fixtures' => $fixtures],
        ];
    }

    public function testPicksTheHighestAverageRatingRegardlessOfGoals(): void
    {
        $row = $this->syncRow('club-1', [
            ['playerId' => 'p1', 'playerName' => 'Prolific Striker', 'goals' => 18, 'assists' => 1, 'appearances' => 20, 'averageRating' => 6.9],
            ['playerId' => 'p2', 'playerName' => 'Max Everett',      'goals' => 10, 'assists' => 3, 'appearances' => 18, 'averageRating' => 8.044444444444443],
        ]);

        $best = LiveTelemetryService::findBestRatedPlayer($row);

        $this->assertSame('Max Everett', $best['playerName']);
        $this->assertSame(10, $best['goals']);
        $this->assertSame(3, $best['assists']);
    }

    public function testRoundsTheAverageRatingToOneDecimal(): void
    {
        // Arrives as a raw mean; every consumer wants the same rounding, so it is done
        // once here rather than in each renderer.
        $row = $this->syncRow('club-1', [
            ['playerId' => 'p1', 'playerName' => 'Max Everett', 'averageRating' => 8.044444444444443],
        ]);

        $this->assertSame(8.0, LiveTelemetryService::findBestRatedPlayer($row)['averageRating']);
    }

    public function testRecoversPositionFromTheFixtureRatingRows(): void
    {
        // playerStats[] has no position field at all — it is only in the per-fixture
        // player-rating rows, keyed by the same playerId.
        $row = $this->syncRow(
            'club-1',
            [['playerId' => 'p1', 'playerName' => 'Juan Ferrari', 'averageRating' => 7.1]],
            [[
                'homePlayerRatings' => [['playerId' => 'other', 'playerName' => 'Someone', 'position' => 'ST', 'rating' => 6.0]],
                'awayPlayerRatings' => [['playerId' => 'p1', 'playerName' => 'Juan Ferrari', 'position' => 'GK', 'rating' => 7.4]],
            ]],
        );

        $this->assertSame('GK', LiveTelemetryService::findBestRatedPlayer($row)['position']);
    }

    public function testPositionIsNullWhenThePlayerFeaturedInNoFixture(): void
    {
        $row = $this->syncRow('club-1', [
            ['playerId' => 'p1', 'playerName' => 'Unused Sub', 'averageRating' => 7.1],
        ]);

        $this->assertNull(LiveTelemetryService::findBestRatedPlayer($row)['position']);
    }

    public function testMergesTheClubKitIntoTheReportedPersonalTraits(): void
    {
        // playerStats[].appearanceConfig is personal-traits-only; a player's kit is
        // club-derived and never sent per-player. Same merge as
        // PlayerCareerStat::toFullAppearanceConfig().
        $row = $this->syncRow(
            'club-1',
            [[
                'playerId' => 'p1',
                'playerName' => 'Max Everett',
                'averageRating' => 8.0,
                'appearanceConfig' => [
                    'hair' => 'long', 'hairColor' => '#1c1410', 'headband' => false,
                    'skin' => 's1', 'face' => 'angry', 'facial' => 'none', 'lip' => '#b8302f',
                ],
            ]],
            homeKitConfig: ['kit' => 'stripes', 'primary' => '#ff0000', 'secondary' => '#ffffff', 'shorts' => '#000000', 'socks' => '#ff0000'],
        );

        $config = LiveTelemetryService::findBestRatedPlayer($row)['appearanceConfig'];

        $this->assertSame('long', $config['hair'], 'personal traits survive the merge');
        $this->assertSame('stripes', $config['kit'], 'club kit is merged in');
        $this->assertSame('#ff0000', $config['primary']);
    }

    public function testNoAppearanceConfigMeansNoSpriteRatherThanAKitWithNoBody(): void
    {
        // The common case today: no shipped client reports appearanceConfig yet. The
        // overlay must render the player without a sprite, not invent a likeness.
        $row = $this->syncRow(
            'club-1',
            [['playerId' => 'p1', 'playerName' => 'Max Everett', 'averageRating' => 8.0]],
            homeKitConfig: ['kit' => 'stripes', 'primary' => '#ff0000'],
        );

        $this->assertNull(LiveTelemetryService::findBestRatedPlayer($row)['appearanceConfig']);
    }

    public function testNoPlayerStatsMeansNoBestPlayer(): void
    {
        $this->assertNull(LiveTelemetryService::findBestRatedPlayer($this->syncRow('club-1', [])));
    }

    /**
     * Unlike buildTalismanEvents(), no rating threshold applies: the overlay always
     * wants the club's best player, even a mediocre one. The talisman ticker line is a
     * newsworthiness judgement; this is a roster fact.
     */
    public function testAppliesNoRatingThreshold(): void
    {
        $row = $this->syncRow('club-1', [
            ['playerId' => 'p1', 'playerName' => 'Honest Journeyman', 'averageRating' => 5.2],
        ]);

        $this->assertSame('Honest Journeyman', LiveTelemetryService::findBestRatedPlayer($row)['playerName']);
    }

    public function testReadsOnlyTheNewestSyncRowPerClub(): void
    {
        // playerStats[] is a cumulative season-to-date tally, so the latest row already
        // contains everything the earlier ones did — merging would just pick a winner
        // between a stale and a fresh copy of the same player.
        $dossiers = LiveTelemetryService::buildClubDossiers([
            $this->syncRow('club-1', [['playerId' => 'p1', 'playerName' => 'Stale', 'averageRating' => 9.5]], at: '2026-10-11 10:00:00'),
            $this->syncRow('club-1', [['playerId' => 'p1', 'playerName' => 'Fresh', 'averageRating' => 7.0]], at: '2026-10-12 10:00:00'),
        ], []);

        $this->assertSame('Fresh', $dossiers['club-1']['topPlayer']['playerName']);
    }

    public function testKeyedByClubIdNotClubName(): void
    {
        // Club names are not unique — production has six "London FC".
        $rows = [
            $this->syncRow('club-1', [['playerId' => 'p1', 'playerName' => 'First XI', 'averageRating' => 7.0]]),
            $this->syncRow('club-2', [['playerId' => 'p2', 'playerName' => 'Second XI', 'averageRating' => 7.0]]),
        ];

        $dossiers = LiveTelemetryService::buildClubDossiers($rows, []);

        $this->assertSame('First XI', $dossiers['club-1']['topPlayer']['playerName']);
        $this->assertSame('Second XI', $dossiers['club-2']['topPlayer']['playerName']);
    }

    public function testAttachesFixturesEvenToAClubThatHasNotSyncedInTheWindow(): void
    {
        // Such a club can still reach the feed via a SeasonRecord pyramid event. Its
        // persisted results are real and worth showing, even with no playerStats.
        $fixtures = [['opponent' => 'Bolton Rangers', 'scoreFor' => 2, 'scoreAgainst' => 1, 'week' => 24, 'result' => 'W']];

        $dossiers = LiveTelemetryService::buildClubDossiers([], ['club-9' => $fixtures]);

        $this->assertNull($dossiers['club-9']['topPlayer']);
        $this->assertSame($fixtures, $dossiers['club-9']['fixtures']);
    }

    public function testSyncRowsWithoutAClubIdAreSkipped(): void
    {
        $row = $this->syncRow('club-1', [['playerId' => 'p1', 'playerName' => 'X', 'averageRating' => 7.0]]);
        unset($row['clubId']);

        $this->assertSame([], LiveTelemetryService::buildClubDossiers([$row], []));
    }

    /**
     * Regression: mergeEventsByRecency() rebuilds every event from an explicit key
     * whitelist (to drop the internal 'at' sort key), and clubId was initially left
     * out of it. The symptom was not an error — every event simply arrived at
     * attachClubDossiers() with a null clubId, so no dossier was ever attached and the
     * overlay silently showed neither block. Anything the dossier step keys off must
     * survive this method.
     */
    public function testMergeKeepsTheClubIdTheDossierStepKeysOff(): void
    {
        $at = new \DateTimeImmutable('2026-10-12 10:00:00');

        $events = LiveTelemetryService::mergeEventsByRecency([[
            'time' => '1m ago', 'text' => 'x', 'category' => 'OUTLAY', 'categoryLabel' => '[OUTLAY]',
            'club' => 'Test Club', 'detail' => 'x', 'amountExact' => null, 'timestampIso' => $at->format(\DateTimeInterface::ATOM),
            'meta' => [], 'clubBadge' => null, 'clubId' => 'club-1', 'at' => $at,
        ]]);

        $this->assertSame('club-1', $events[0]['clubId']);
        $this->assertArrayNotHasKey('at', $events[0], 'the internal sort key is still dropped');
    }

    public function testIgnoresMalformedPlayerStatEntries(): void
    {
        // playerStats[] is archived verbatim with no validation, same posture every
        // other build* method takes toward payload arrays.
        $row = $this->syncRow('club-1', [
            'not-an-array',
            ['playerId' => 'p1', 'playerName' => 'Real Player', 'averageRating' => 7.0],
        ]);

        $this->assertSame('Real Player', LiveTelemetryService::findBestRatedPlayer($row)['playerName']);
    }
}
