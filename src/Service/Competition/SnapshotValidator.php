<?php

namespace App\Service\Competition;

/**
 * Structural validation of the client-supplied entrant snapshot. This is NOT a
 * re-validation of game rules — just enough of a structural check to reject a
 * malformed or spoofed payload before it's persisted (CLAUDE.md's "never trust
 * client JSON shape" pattern, same instinct as GameEventTemplate's JSON).
 */
class SnapshotValidator
{
    private const MIN_PLAYERS = 11;
    private const MAX_PLAYERS = 30;

    /** @var list<string> Numeric player attributes checked for the 1-20 clamp, when present. */
    private const CLAMPED_PLAYER_ATTRIBUTES = ['currentAbility', 'pace', 'technical', 'vision', 'power', 'stamina', 'heart'];

    /**
     * @param array $club Envelope's "club" object
     * @param array $players Envelope's "players" list
     * @param array $staff Envelope's "staff" list
     * @return list<string> Violation messages; empty means valid.
     */
    public function validate(array $club, array $players, array $staff, string $expectedClubId): array
    {
        $violations = [];

        if (!isset($club['id']) || !is_string($club['id']) || $club['id'] === '') {
            $violations[] = 'club.id is required';
        } elseif ($club['id'] !== $expectedClubId) {
            $violations[] = 'club.id does not match the authenticated club';
        }

        if (!isset($club['name']) || !is_string($club['name']) || $club['name'] === '') {
            $violations[] = 'club.name is required';
        }

        $playerCount = count($players);
        if ($playerCount < self::MIN_PLAYERS || $playerCount > self::MAX_PLAYERS) {
            $violations[] = sprintf(
                'players must contain between %d and %d entries, got %d',
                self::MIN_PLAYERS,
                self::MAX_PLAYERS,
                $playerCount,
            );
        }

        foreach ($players as $i => $player) {
            if (!is_array($player)) {
                $violations[] = "players[$i] must be an object";
                continue;
            }

            foreach (['id', 'position'] as $key) {
                if (!isset($player[$key])) {
                    $violations[] = "players[$i].$key is required";
                }
            }

            foreach (self::CLAMPED_PLAYER_ATTRIBUTES as $attr) {
                if (!isset($player[$attr])) {
                    continue;
                }
                if (!is_int($player[$attr]) && !is_float($player[$attr])) {
                    $violations[] = "players[$i].$attr must be numeric";
                } elseif ($player[$attr] < 1 || $player[$attr] > 20) {
                    $violations[] = "players[$i].$attr must be between 1 and 20";
                }
            }
        }

        foreach ($staff as $i => $member) {
            if (!is_array($member)) {
                $violations[] = "staff[$i] must be an object";
                continue;
            }
            if (!isset($member['id']) || !isset($member['role'])) {
                $violations[] = "staff[$i].id and staff[$i].role are required";
            }
        }

        return $violations;
    }
}
