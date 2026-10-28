<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\User;
use App\Service\Competition\CompetitionRegistrationService;
use PHPUnit\Framework\TestCase;

/**
 * Covers CompetitionRegistrationService::splitSnapshot(), the one place that decides how a
 * submitted envelope is divided between the display row and the engine-inputs row.
 *
 * It is shared rather than per-domain because competitions and friendlies store the same
 * submitted envelope across the same two shapes, and the duplicate copies had already
 * drifted: the friendly one recomputed OVR from the XI unconditionally and so ignored a
 * client-supplied `club.ovr` that a competition registration of the identical envelope
 * honoured. These tests pin the rule itself, so the next copy cannot quietly disagree.
 */
class SubmittedSnapshotSplitTest extends TestCase
{
    private function club(): Club
    {
        $user = new User('split@example.com');
        $user->setPassword('x');

        return new Club('Split FC', $user);
    }

    /** @return array<string, mixed> */
    private function envelope(int $ability, ?int $suppliedOvr = null): array
    {
        $players   = [];
        $positions = ['GK', 'DEF', 'DEF', 'DEF', 'DEF', 'MID', 'MID', 'MID', 'ATT', 'ATT', 'ATT'];
        foreach ($positions as $i => $position) {
            $players[] = [
                'id'             => sprintf('p%d', $i),
                'name'           => sprintf('Player %d', $i),
                'position'       => $position,
                'currentAbility' => $ability,
            ];
        }

        $club = ['name' => 'Split FC', 'cohesion' => 70, 'managerMotivation' => 65, 'lowUrgency' => false];
        if ($suppliedOvr !== null) {
            $club['ovr'] = $suppliedOvr;
        }

        return [
            'club'             => $club,
            'players'          => $players,
            'staff'            => [['id' => 's1', 'role' => 'MANAGER', 'name' => 'Boss']],
            'clientSnapshotAt' => '2026-10-28T12:00:00+01:00',
        ];
    }

    public function testADeviceSuppliedOvrWins(): void
    {
        $parts = CompetitionRegistrationService::splitSnapshot($this->envelope(60, 77), $this->club());

        self::assertSame(77, $parts['ovr'], 'a numeric club.ovr is the device\'s own rating and is taken as given');
        self::assertSame(77, $parts['display']['ovr'], 'and it is the value frozen into the display block');
    }

    public function testOvrIsDerivedFromTheStartingXiWhenTheDeviceSendsNone(): void
    {
        $parts = CompetitionRegistrationService::splitSnapshot($this->envelope(60), $this->club());

        self::assertSame(
            CompetitionRegistrationService::computeStartingXiOvr($this->envelope(60)),
            $parts['ovr'],
        );
        self::assertSame($parts['ovr'], $parts['display']['ovr']);
    }

    public function testEngineOnlyKeysStayOutOfTheDisplayBlock(): void
    {
        $parts = CompetitionRegistrationService::splitSnapshot($this->envelope(60), $this->club());

        foreach (CompetitionRegistrationService::CLUB_ENGINE_KEYS as $key) {
            self::assertArrayHasKey($key, $parts['engine'], sprintf('%s is an engine input', $key));
            self::assertArrayNotHasKey($key, $parts['display'], sprintf('%s must not ride on the public display block', $key));
        }
    }

    public function testPlayerCountIsDenormalisedSoNoReadPathNeedsTheSquad(): void
    {
        $parts = CompetitionRegistrationService::splitSnapshot($this->envelope(60), $this->club());

        self::assertSame(11, $parts['playerCount']);
        self::assertCount(11, $parts['players']);
        self::assertCount(1, $parts['staff']);
    }

    public function testNonArrayEntriesAndAMissingClubBlockAreToleratedRatherThanFatal(): void
    {
        $parts = CompetitionRegistrationService::splitSnapshot(
            ['players' => [['id' => 'p1'], 'rubbish', 7], 'staff' => 'not-a-list'],
            $this->club(),
        );

        self::assertSame([['id' => 'p1']], $parts['players'], 'a registration must never fail on a malformed entry');
        self::assertSame([], $parts['staff']);
        self::assertSame(1, $parts['playerCount']);
    }

    /**
     * The sanitiser's job is the column, not the calendar: clientSnapshotAt is VARCHAR(64)
     * stored verbatim so the API echoes back the offset the device wrote, so only blank and
     * over-length are rejected — and rejected as null, never as a violation, because this
     * field must never fail a registration. An unparseable but short string is kept.
     */
    public function testOnlyBlankAndOverLongClientSnapshotAtBecomeNull(): void
    {
        $club = $this->club();

        foreach (['', '   ', str_repeat('x', 65)] as $unusable) {
            $envelope                     = $this->envelope(60);
            $envelope['clientSnapshotAt'] = $unusable;

            self::assertNull(
                CompetitionRegistrationService::splitSnapshot($envelope, $club)['clientSnapshotAt'],
                sprintf('%d-char value must land as null', strlen($unusable)),
            );
        }

        $envelope                     = $this->envelope(60);
        $envelope['clientSnapshotAt'] = '  whenever  ';

        self::assertSame(
            'whenever',
            CompetitionRegistrationService::splitSnapshot($envelope, $club)['clientSnapshotAt'],
            'a short string is trimmed and kept — the split does not parse dates',
        );
    }

    public function testAUsableClientSnapshotAtIsStoredVerbatim(): void
    {
        $parts = CompetitionRegistrationService::splitSnapshot($this->envelope(60), $this->club());

        self::assertSame(
            '2026-10-28T12:00:00+01:00',
            $parts['clientSnapshotAt'],
            'stored as the client wrote it, so the API echo keeps its original offset',
        );
    }
}
