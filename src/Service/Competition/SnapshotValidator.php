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
    public const REQUIRED_PLAYERS = 11;

    /**
     * Hard ceiling on the serialized size of one submitted snapshot.
     *
     * This is the one check in this class that rejects on *size* rather than shape, and it
     * is a deliberate exception to the rule above about not failing a registration over
     * unrecognised fields — because an unbounded section is not a field-shape question.
     *
     * `club`, `staff` and `facilities` had no bound of any kind: the envelope's only ceiling
     * was nginx's `client_max_body_size 5m`. On a 64-entrant bracket that is up to 320MB of
     * JSON for one competition, every byte of it decoded by a public, unauthenticated
     * endpoint — so a single registrant could make a competition unservable for everyone
     * else in it. That is an availability bug, not a validation preference.
     *
     * 256KB is roughly ten times a real snapshot (eleven players with full attributes,
     * personality and appearance, plus club identity and staff, measures around 25KB), so it
     * rejects abuse without being anywhere near legitimate traffic.
     */
    public const MAX_SNAPSHOT_BYTES = 262144;


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

        // No count cap on staff. One was added here and removed again: the backroom is
        // open-ended (an admin can raise the per-club staff limits, and the client may put
        // scouts in `staff[]`), and MAX_SNAPSHOT_BYTES already bounds the payload — so a
        // count cap only turned legitimate squads into 422s on register, resubmit, friendly
        // create/accept and spoof cloning without adding any protection.
        foreach ($staff as $i => $member) {
            if (!is_array($member)) {
                $violations[] = "staff[$i] must be an object";
                continue;
            }
            if (!isset($member['id']) || !isset($member['role'])) {
                $violations[] = "staff[$i].id and staff[$i].role are required";
            }
        }

        // Measured last, on the whole envelope, so one oversized section cannot hide behind
        // another passing its own checks. json_encode is given the same data that would be
        // persisted; a payload that cannot even be encoded is rejected rather than stored.
        $encoded = json_encode(['club' => $club, 'players' => $players, 'staff' => $staff]);
        if ($encoded === false) {
            $violations[] = 'snapshot could not be encoded';
        } elseif (strlen($encoded) > self::MAX_SNAPSHOT_BYTES) {
            $violations[] = sprintf(
                'snapshot must be at most %d bytes, got %d',
                self::MAX_SNAPSHOT_BYTES,
                strlen($encoded),
            );
        }

        return $violations;
    }
}
