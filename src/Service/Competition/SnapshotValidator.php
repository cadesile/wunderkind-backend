<?php

namespace App\Service\Competition;

use App\Enum\Formation;
use App\Enum\PlayingStyle;

/**
 * Structural validation of the client-supplied entrant snapshot. This is NOT a
 * re-validation of game rules — just enough of a structural check to reject a
 * malformed or spoofed payload before it's persisted (CLAUDE.md's "never trust
 * client JSON shape" pattern, same instinct as GameEventTemplate's JSON).
 *
 * `club`/`players`/`staff` are untyped arrays end-to-end (no nested DTOs), so any key
 * not checked here still persists into the snapshot verbatim — this class is the only
 * gate. As of the 2026 client expansion (club reputation/tier/stadiumName/kit colours/
 * badgeShape; players[] name/dateOfBirth/age/nationality/potential/personality/morale/
 * motivation/condition/squadRole; staff[] name/nationality/ability/specialisms/
 * judgements), those new fields are DELIBERATELY left unchecked: the client's contract
 * for them isn't confirmed yet, and unlike formation/playingStyle they must NOT 422 the
 * whole registration on a malformed or unrecognized value. Don't "fix" this by adding
 * tryFrom()-style rejection for them until the shapes are locked — see
 * SnapshotValidatorTest's malformed-new-fields cases, which assert this on purpose.
 */
class SnapshotValidator
{
    /**
     * Exactly the starting XI, not the full squad — DeterministicEngine averages
     * currentAbility across every player in the snapshot, so a full 25-30 man squad
     * (bench/reserves included) would dilute that average with players who aren't
     * actually on the pitch.
     */
    private const REQUIRED_PLAYERS = 11;

    /**
     * @var list<string> Numeric player attributes checked for range, when present.
     * 0-100 — matches wunderkind-app's real local scale (Player.overallRating /
     * PlayerAttributes), NOT the backend's own 1-20 Personality Matrix convention. An
     * earlier version of this check used 1-20 by incorrect analogy to Personality; fixed
     * before any client build against it.
     */
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

        // Both optional — a snapshot without them just gets neutral tactical treatment
        // (DeterministicEngine) and no formation shown in narrative/display contexts.
        if (isset($club['playingStyle']) && PlayingStyle::tryFrom((string) $club['playingStyle']) === null) {
            $violations[] = 'club.playingStyle must be one of: ' . implode(', ', array_column(PlayingStyle::cases(), 'value'));
        }
        if (isset($club['formation']) && Formation::tryFrom((string) $club['formation']) === null) {
            $violations[] = 'club.formation must be one of: ' . implode(', ', array_column(Formation::cases(), 'value'));
        }

        $playerCount = count($players);
        if ($playerCount !== self::REQUIRED_PLAYERS) {
            $violations[] = sprintf(
                'players must contain exactly %d entries (the starting XI), got %d',
                self::REQUIRED_PLAYERS,
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
                } elseif ($player[$attr] < 0 || $player[$attr] > 100) {
                    $violations[] = "players[$i].$attr must be between 0 and 100";
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
