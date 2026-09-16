<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Service\Competition\SnapshotValidator;
use PHPUnit\Framework\TestCase;

class SnapshotValidatorTest extends TestCase
{
    private SnapshotValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SnapshotValidator();
    }

    /** @return list<array<string, mixed>> */
    private function validPlayers(int $count = 11): array
    {
        $players = [];
        for ($i = 0; $i < $count; $i++) {
            $players[] = ['id' => "p$i", 'position' => 'MID', 'currentAbility' => 10];
        }

        return $players;
    }

    public function testValidSnapshotHasNoViolations(): void
    {
        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC'],
            $this->validPlayers(),
            [],
            'club-1',
        );

        $this->assertSame([], $violations);
    }

    public function testRejectsSpoofedClubId(): void
    {
        $violations = $this->validator->validate(
            ['id' => 'someone-elses-club', 'name' => 'Test FC'],
            $this->validPlayers(),
            [],
            'club-1',
        );

        $this->assertContains('club.id does not match the authenticated club', $violations);
    }

    public function testRejectsFewerThan11Players(): void
    {
        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC'],
            $this->validPlayers(5),
            [],
            'club-1',
        );

        $this->assertContains('players must contain exactly 11 entries (the starting XI), got 5', $violations);
    }

    public function testRejectsAFullSquadInsteadOfJustTheStartingXi(): void
    {
        // The realistic mistake this check guards against: sending the whole 18-25 man
        // squad (bench/reserves included) rather than just the starting XI, which would
        // dilute DeterministicEngine's currentAbility average with players not on the pitch.
        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC'],
            $this->validPlayers(18),
            [],
            'club-1',
        );

        $this->assertContains('players must contain exactly 11 entries (the starting XI), got 18', $violations);
    }

    public function testRejectsOutOfRangeAttribute(): void
    {
        $players    = $this->validPlayers();
        $players[0]['currentAbility'] = 150; // > 100

        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC'],
            $players,
            [],
            'club-1',
        );

        $this->assertContains('players[0].currentAbility must be between 0 and 100', $violations);
    }

    public function testAcceptsRealAppScaleValues(): void
    {
        // wunderkind-app's real local scale for these attributes is 0-100, not the
        // backend's own 1-20 Personality Matrix convention — a value like 78 must pass.
        $players    = $this->validPlayers();
        $players[0]['currentAbility'] = 78;
        $players[0]['pace']           = 92;

        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC'],
            $players,
            [],
            'club-1',
        );

        $this->assertSame([], $violations);
    }

    public function testRejectsPlayerMissingRequiredKeys(): void
    {
        $players    = $this->validPlayers();
        unset($players[0]['position']);

        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC'],
            $players,
            [],
            'club-1',
        );

        $this->assertContains('players[0].position is required', $violations);
    }

    public function testRejectsStaffMissingRole(): void
    {
        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC'],
            $this->validPlayers(),
            [['id' => 'staff-1']],
            'club-1',
        );

        $this->assertContains('staff[0].id and staff[0].role are required', $violations);
    }

    public function testAcceptsValidFormationAndPlayingStyle(): void
    {
        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC', 'formation' => '4-3-3', 'playingStyle' => 'HIGH_PRESS'],
            $this->validPlayers(),
            [],
            'club-1',
        );

        $this->assertSame([], $violations);
    }

    public function testFormationAndPlayingStyleAreOptional(): void
    {
        // No formation/playingStyle at all — a snapshot without them is still valid;
        // the match engine just applies no tactical multiplier.
        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC'],
            $this->validPlayers(),
            [],
            'club-1',
        );

        $this->assertSame([], $violations);
    }

    public function testRejectsUnknownPlayingStyle(): void
    {
        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC', 'playingStyle' => 'TIKI_TAKA'],
            $this->validPlayers(),
            [],
            'club-1',
        );

        $this->assertNotEmpty(array_filter($violations, fn ($v) => str_contains($v, 'club.playingStyle')));
    }

    public function testRejectsUnknownFormation(): void
    {
        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC', 'formation' => '2-3-5'],
            $this->validPlayers(),
            [],
            'club-1',
        );

        $this->assertNotEmpty(array_filter($violations, fn ($v) => str_contains($v, 'club.formation')));
    }
}
