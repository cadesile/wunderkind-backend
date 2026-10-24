<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Service\Competition\CompetitionRegistrationService;
use PHPUnit\Framework\TestCase;

/**
 * `GET /api/matches/{matchId}` returns the registered snapshot XI as the lineup for a fixture
 * that has not been played yet, so the pre-match screen can answer "who is each club
 * fielding" instead of showing a crest and a countdown with nothing else on it.
 *
 * The shape has to match a played lineup exactly — the client renders one table for both —
 * which is why this is a projection and not a pass-through of the snapshot's own wider
 * player shape. See docs/api/match-detail-club-fields.md §4a (app repo).
 */
class SnapshotXiFormattingTest extends TestCase
{
    /** Exactly the keys DeterministicEngine::calculatePerformance() emits, in its order. */
    private const LINEUP_KEYS = [
        'name', 'position', 'appearance', 'goals', 'assists', 'yellowCards', 'redCards', 'rating',
    ];

    public function testItProjectsASnapshotPlayerOntoThePlayedLineupShape(): void
    {
        $formatted = CompetitionRegistrationService::formatSnapshotXiForClient([
            [
                'id'             => 'p1',
                'name'           => 'Ada Keeper',
                'position'       => 'GK',
                'appearance'     => ['hair' => 'curly'],
                // Snapshot-only fields that must not leak into a lineup entry.
                'currentAbility' => 72,
                'nationality'    => 'English',
                'dateOfBirth'    => '2008-01-01',
            ],
        ]);

        $this->assertCount(1, $formatted);
        $this->assertSame(self::LINEUP_KEYS, array_keys($formatted[0]));
        $this->assertSame('Ada Keeper', $formatted[0]['name']);
        $this->assertSame('GK', $formatted[0]['position']);
        $this->assertSame(['hair' => 'curly'], $formatted[0]['appearance']);
    }

    /**
     * `rating` is null, not 0: a played lineup's rating runs 1.0-10.0, so 0 would render as
     * the worst performance anyone has ever had rather than "not played yet". Counters are 0
     * because nobody has scored, which is simply true.
     */
    public function testPerMatchValuesAreEmptyRatherThanZeroRated(): void
    {
        $formatted = CompetitionRegistrationService::formatSnapshotXiForClient([
            ['name' => 'Someone', 'position' => 'MID'],
        ]);

        $this->assertNull($formatted[0]['rating']);
        $this->assertSame(0, $formatted[0]['goals']);
        $this->assertSame(0, $formatted[0]['assists']);
        $this->assertSame(0, $formatted[0]['yellowCards']);
        $this->assertSame(0, $formatted[0]['redCards']);
    }

    /** `id` is stripped from a played lineup too (formatLineupForClient's default), so it must not appear here either. */
    public function testItDoesNotLeakThePlayerId(): void
    {
        $formatted = CompetitionRegistrationService::formatSnapshotXiForClient([
            ['id' => 'player-uuid', 'name' => 'Someone', 'position' => 'ATT'],
        ]);

        $this->assertArrayNotHasKey('id', $formatted[0]);
    }

    /**
     * Submitted order is the club's own statement of intent before kick-off, and the only
     * ordering available — a played lineup is sorted by ability by the engine instead.
     */
    public function testItPreservesSubmittedOrder(): void
    {
        $formatted = CompetitionRegistrationService::formatSnapshotXiForClient([
            ['name' => 'First',  'position' => 'GK',  'currentAbility' => 20],
            ['name' => 'Second', 'position' => 'DEF', 'currentAbility' => 90],
            ['name' => 'Third',  'position' => 'ATT', 'currentAbility' => 55],
        ]);

        $this->assertSame(['First', 'Second', 'Third'], array_column($formatted, 'name'));
    }

    /** The snapshot is client-supplied, so a malformed entry must be skipped, not fatal. */
    public function testItSkipsNonArrayEntriesAndNullsMissingFields(): void
    {
        $formatted = CompetitionRegistrationService::formatSnapshotXiForClient([
            'not-an-array',
            null,
            ['name' => 'Only A Name'],
            ['position' => 'DEF', 'appearance' => 'not-an-array'],
        ]);

        $this->assertCount(2, $formatted);
        $this->assertSame('Only A Name', $formatted[0]['name']);
        $this->assertNull($formatted[0]['position']);
        $this->assertNull($formatted[0]['appearance']);
        $this->assertNull($formatted[1]['name']);
        $this->assertSame('DEF', $formatted[1]['position']);
        $this->assertNull($formatted[1]['appearance'], 'a non-array appearance must not be passed through');
    }

    public function testAnEmptySnapshotStillYieldsAnEmptyLineup(): void
    {
        $this->assertSame([], CompetitionRegistrationService::formatSnapshotXiForClient([]));
    }
}
