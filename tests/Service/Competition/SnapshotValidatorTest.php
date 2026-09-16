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

    public function testRejectsTooFewPlayers(): void
    {
        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC'],
            $this->validPlayers(5),
            [],
            'club-1',
        );

        $this->assertNotEmpty(array_filter($violations, fn ($v) => str_contains($v, 'players must contain')));
    }

    public function testRejectsTooManyPlayers(): void
    {
        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC'],
            $this->validPlayers(31),
            [],
            'club-1',
        );

        $this->assertNotEmpty(array_filter($violations, fn ($v) => str_contains($v, 'players must contain')));
    }

    public function testRejectsOutOfRangeAttribute(): void
    {
        $players    = $this->validPlayers();
        $players[0]['currentAbility'] = 25; // > 20 clamp

        $violations = $this->validator->validate(
            ['id' => 'club-1', 'name' => 'Test FC'],
            $players,
            [],
            'club-1',
        );

        $this->assertContains('players[0].currentAbility must be between 1 and 20', $violations);
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
}
