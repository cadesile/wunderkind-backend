<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class SyncRequest
{
    #[Assert\Positive]
    public int $weekNumber;

    #[Assert\NotBlank]
    public string $clientTimestamp;

    /** Net change in earnings this week — may be negative (wages, upkeep). */
    public float $earningsDelta = 0;

    /** Client-authoritative balance after this week's tick. */
    public int $balance = 0;

    /** Client-authoritative lifetime career earnings. */
    public int $totalCareerEarnings = 0;

    /** Delta applied to reputation this week. */
    public float $reputationDelta = 0;

    /** Client-authoritative reputation score (float — server rounds to int on store). */
    public float $reputation = 0.0;

    #[Assert\PositiveOrZero]
    public float $hallOfFamePoints = 0;

    /** Number of players currently in the squad — informational / anti-cheat signal. */
    public int $squadSize = 0;

    /** Number of staff members currently employed — informational / anti-cheat signal. */
    public int $staffCount = 0;

    /**
     * Current facility upgrade levels, keyed by facility slug.
     * e.g. {"technicalZone": 1, "strengthSuite": 0, ...}
     *
     * @var array<string, int>
     */
    public array $facilityLevels = [];

    /**
     * Transfer summaries for players bought/sold during this tick.
     *
     * @var TransferSyncDto[]
     */
    #[Assert\Valid]
    #[Assert\All([new Assert\Type(TransferSyncDto::class)])]
    public array $transfers = [];

    /**
     * Archival snapshot of this week's financial ledger.
     *
     * @var LedgerEntrySyncDto[]
     */
    #[Assert\Valid]
    #[Assert\All([new Assert\Type(LedgerEntrySyncDto::class)])]
    public array $ledger = [];

    /**
     * @param array<array{playerId?: string, playerName?: string, destinationClub?: string, grossFee?: int, agentCommission?: int, netProceeds?: int, type?: string}|TransferSyncDto> $transfers
     */
    public function setTransfers(array $transfers): void
    {
        $this->transfers = array_map(static function (array|TransferSyncDto $item): TransferSyncDto {
            if ($item instanceof TransferSyncDto) {
                return $item;
            }
            $dto = new TransferSyncDto();
            $dto->playerId        = $item['playerId'] ?? '';
            $dto->playerName      = $item['playerName'] ?? '';
            $dto->playerPosition  = $item['playerPosition'] ?? '';
            $dto->destinationClub = $item['destinationClub'] ?? '';
            $dto->grossFee        = (int) ($item['grossFee'] ?? 0);
            $dto->agentCommission = (int) ($item['agentCommission'] ?? 0);
            $dto->netProceeds     = (int) ($item['netProceeds'] ?? 0);
            $dto->type            = $item['type'] ?? '';
            return $dto;
        }, $transfers);
    }

    /**
     * @param array<array{category?: string, amount?: int, description?: string}|LedgerEntrySyncDto> $ledger
     */
    public function setLedger(array $ledger): void
    {
        $this->ledger = array_map(static function (array|LedgerEntrySyncDto $item): LedgerEntrySyncDto {
            if ($item instanceof LedgerEntrySyncDto) {
                return $item;
            }
            $dto              = new LedgerEntrySyncDto();
            $dto->category    = $item['category'] ?? '';
            $dto->amount      = (int) ($item['amount'] ?? 0);
            $dto->description = $item['description'] ?? '';
            return $dto;
        }, $ledger);
    }

    /**
     * @var MatchResultDto[]
     */
    #[Assert\Valid]
    #[Assert\All([new Assert\Type(MatchResultDto::class)])]
    public array $matchResults = [];

    /**
     * @param array<array{opponentClubId?: string, goalsFor?: int, goalsAgainst?: int, week?: int}|MatchResultDto> $matchResults
     */
    public function setMatchResults(array $matchResults): void
    {
        $this->matchResults = array_map(static function (array|MatchResultDto $item): MatchResultDto {
            if ($item instanceof MatchResultDto) {
                return $item;
            }
            $dto                   = new MatchResultDto();
            // v2
            $dto->fixtureId        = $item['fixtureId'] ?? '';
            $dto->leagueId         = $item['leagueId'] ?? '';
            $dto->season           = (int) ($item['season'] ?? 0);
            $dto->round            = (int) ($item['round'] ?? 0);
            $dto->opponentClubId   = $item['opponentClubId'] ?? '';
            $dto->opponentClubName = $item['opponentClubName'] ?? '';
            $dto->homeGoals        = (int) ($item['homeGoals'] ?? 0);
            $dto->awayGoals        = (int) ($item['awayGoals'] ?? 0);
            $dto->isHome           = (bool) ($item['isHome'] ?? false);
            $dto->playedAt         = $item['playedAt'] ?? '';
            // v1 legacy
            $dto->goalsFor         = (int) ($item['goalsFor'] ?? 0);
            $dto->goalsAgainst     = (int) ($item['goalsAgainst'] ?? 0);
            $dto->week             = (int) ($item['week'] ?? 1);
            return $dto;
        }, $matchResults);
    }

    /**
     * Manager personality shift deltas sent by the client each week.
     * Keys: 'temperament', 'discipline', 'ambition'. Values: signed int deltas.
     *
     * @var array<string, int>
     */
    public array $managerShifts = [];

    // ── v2 fields ─────────────────────────────────────────────────────────

    /**
     * Mean overall rating of all active players at sync time. Integer, rounded.
     */
    public int $squadAvgOvr = 0;

    /**
     * Last ≤5 league results, newest first. Values: 'W' | 'D' | 'L'.
     *
     * @var string[]
     */
    public array $form = [];

    /** AMP's current league table position (1 = top). null = no league. */
    public ?int $leaguePosition = null;

    /**
     * Season running totals at the time of sync.
     *
     * @var array{wins: int, draws: int, losses: int, goalsFor: int, goalsAgainst: int, points: int}
     */
    public array $seasonRecord = [];

    /**
     * Season-to-date stats per AMP player with ≥1 appearance.
     *
     * @var array<array{playerId: string, appearances: int, goals: int, assists: int, averageRating: float}>
     */
    public array $playerStats = [];

    /**
     * Players signed into the squad this week (incoming transfers).
     *
     * @var array<array{playerId: string, playerName: string, position: string, age: int, overallRating: int, fee: int, fromClub: string|null}>
     */
    public array $signings = [];

    /**
     * Player attribute snapshots sent by the client each week.
     * Each entry: {playerId, pace, technical, vision, power, stamina, heart, height, weight, morale}
     *
     * @var array<array{playerId: string, pace?: int, technical?: int, vision?: int, power?: int, stamina?: int, heart?: int, height?: int, weight?: int, morale?: int}>
     */
    public array $players = [];
}
