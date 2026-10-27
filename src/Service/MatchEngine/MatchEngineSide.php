<?php

declare(strict_types=1);

namespace App\Service\MatchEngine;

use App\Enum\PlayingStyle;

/**
 * Everything a match engine reads about one side of a match, and nothing else.
 *
 * The engines used to take `CompetitionEntrant` entities directly, which coupled match
 * resolution to how a registration happens to be stored. Two things fell out of that:
 *
 *  - Friendlies had to fake it. `FriendlyChallengeService::resolveSingleMatch()` built a
 *    throwaway CompetitionTemplate, ActiveCompetition, CompetitionRound, two
 *    CompetitionEntrants and a CompetitionFixture, then used reflection to overwrite the
 *    fixture's private `id` — all to supply one UUID as an RNG seed, because a friendly
 *    has no cup rows and never should.
 *  - Splitting the registration snapshot into display and engine halves would have made
 *    that worse, since the friendly path would then have had to fake squad rows too.
 *
 * The real input surface was always tiny: five getters on the entrant and exactly one on
 * the fixture. Naming it here means the engines no longer know or care where a side came
 * from, so a storage change cannot alter a match result.
 *
 * `clubId`/`clubName` are plain scalars rather than a Club entity on purpose — they are
 * only ever used for the audit trail and as a display-name fallback, and taking the
 * entity would quietly reintroduce the coupling this exists to remove.
 */
final class MatchEngineSide
{
    /**
     * @param list<array<string, mixed>> $players Starting XI, as submitted.
     * @param list<array<string, mixed>> $staff Staff behind the XI; read for manager ability/motivation.
     * @param array<string, mixed> $engineInputs Club-level tuning keys — see CompetitionRegistrationService::CLUB_ENGINE_KEYS.
     */
    public function __construct(
        public readonly string $sideId,
        public readonly string $clubId,
        public readonly string $clubName,
        public readonly int $seed,
        public readonly int $snapshotVersion,
        public readonly ?string $displayName,
        public readonly ?PlayingStyle $playingStyle,
        public readonly array $players,
        public readonly array $staff,
        public readonly array $engineInputs,
    ) {}

    /** The name to show for this side: the registered display name, falling back to the live club name. */
    public function resolvedName(): string
    {
        return $this->displayName ?? $this->clubName;
    }

    /**
     * Reads one of the club-level tuning keys. Absent means absent: the engine's own
     * defaults (`?? null`, `?? false`) decide what that means, exactly as they did when
     * these keys were loose in the snapshot's club object.
     */
    public function engineInput(string $key): mixed
    {
        return $this->engineInputs[$key] ?? null;
    }
}
