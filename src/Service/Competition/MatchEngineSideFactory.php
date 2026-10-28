<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionEntrantSquad;
use App\Enum\PlayingStyle;
use App\Service\MatchEngine\MatchEngineSide;

/**
 * Builds the engines' MatchEngineSide inputs out of whatever a domain happens to store.
 *
 * It lives here, not beside MatchEngineSide, on purpose: the value object's whole point is
 * that the engines know nothing about entities, so it must not import any. The knowledge of
 * how a competition entrant or a friendly challenger maps onto a side belongs on this side
 * of that line.
 */
class MatchEngineSideFactory
{
    /**
     * A cup entrant. The squad row is the engine's only source for the XI, staff and
     * club-level tuning keys, so a missing one yields an empty side rather than a crash:
     * squads are pruned once a competition completes, and re-resolving a finished tie is a
     * bug to surface as a flat result, not a 500.
     */
    public static function fromEntrant(CompetitionEntrant $entrant, ?CompetitionEntrantSquad $squad): MatchEngineSide
    {
        $display = $entrant->getClubDisplayJson();

        return new MatchEngineSide(
            sideId: (string) $entrant->getId(),
            clubId: (string) $entrant->getClub()->getId(),
            clubName: $entrant->getClub()->getName(),
            seed: $entrant->getSeed(),
            snapshotVersion: $entrant->getSnapshotVersion(),
            displayName: is_string($display['name'] ?? null) ? $display['name'] : null,
            playingStyle: self::styleFrom($display),
            players: $squad?->getPlayersJson() ?? [],
            staff: $squad?->getStaffJson() ?? [],
            engineInputs: $squad?->getClubEngineJson() ?? [],
        );
    }

    /**
     * A friendly side. A friendly has no entrant row at all, which is why this takes the
     * pieces directly — the previous design faked a CompetitionEntrant (and four other
     * entities) to reach the engine.
     *
     * `seed` is the venue ordering (1 = home, 2 = away) rather than a bracket seed, and
     * `snapshotVersion` is 1: neither concept exists for a friendly, and both are recorded
     * in the audit trail only.
     *
     * @param array<string, mixed> $clubDisplay
     * @param list<array<string, mixed>> $players
     * @param list<array<string, mixed>> $staff
     * @param array<string, mixed> $engineInputs
     */
    public static function fromFriendlySide(
        string $sideId,
        string $clubId,
        string $clubName,
        array $clubDisplay,
        array $players,
        array $staff,
        array $engineInputs,
        int $seed,
    ): MatchEngineSide {
        return new MatchEngineSide(
            sideId: $sideId,
            clubId: $clubId,
            clubName: $clubName,
            seed: $seed,
            snapshotVersion: 1,
            displayName: is_string($clubDisplay['name'] ?? null) ? $clubDisplay['name'] : null,
            playingStyle: self::styleFrom($clubDisplay),
            players: $players,
            staff: $staff,
            engineInputs: $engineInputs,
        );
    }

    /**
     * The display block stores an already-validated PlayingStyle value, but it is still
     * read through tryFrom(): the block is stored JSON, so a row written before a case was
     * renamed must degrade to "no style" (neutral tactical treatment) rather than throw.
     *
     * @param array<string, mixed> $clubDisplay
     */
    private static function styleFrom(array $clubDisplay): ?PlayingStyle
    {
        $value = $clubDisplay['playingStyle'] ?? null;

        return is_string($value) ? PlayingStyle::tryFrom($value) : null;
    }
}
