<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ConcludeSeasonRequest
{
    /** The club this season belongs to. See SyncRequest::$clubId — same semantics. */
    #[Assert\Uuid]
    public ?string $clubId = null;

    /**
     * The season this payload concludes — the idempotency key, together with the club.
     *
     * Optional, and absent on every client build before the retry queue shipped; it then
     * falls back to the club's own `currentSeason`. Sending it explicitly is what makes a
     * *late* retry safe as well as a duplicate one: once this request is queued, a retry
     * can arrive after the next season has already started on the server, at which point
     * "the current season" is the wrong thing to key on. See LeagueService::concludeSeason.
     */
    #[Assert\PositiveOrZero]
    public ?int $season = null;

    #[Assert\Positive]
    public int $finalPosition = 1;

    #[Assert\PositiveOrZero]
    public int $gamesPlayed = 0;

    #[Assert\PositiveOrZero]
    public int $wins = 0;

    #[Assert\PositiveOrZero]
    public int $draws = 0;

    #[Assert\PositiveOrZero]
    public int $losses = 0;

    #[Assert\PositiveOrZero]
    public int $goalsFor = 0;

    #[Assert\PositiveOrZero]
    public int $goalsAgainst = 0;

    #[Assert\PositiveOrZero]
    public int $points = 0;

    public bool $promoted = false;

    public bool $relegated = false;

    /** @var array<string, mixed> */
    public array $pyramidSnapshot = [];

    /**
     * Top-10 goal scorers/assisters/rated players per club tier, across the whole game world
     * for this season. Tiers with no qualifying data are omitted. Archived verbatim into
     * SeasonSnapshot.snapshotData only; no structured storage/processing yet.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $seasonLeaders = [];

    /**
     * The user's own club's full season fixture history, same shape as SyncRequest::$fixtures.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $seasonFixtures = [];
}
