<?php

namespace App\Entity;

use App\Repository\SeasonRecordRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: SeasonRecordRepository::class)]
#[ORM\Table(name: 'season_record')]
// One row per concluded season per club, enforced in the database rather than only in
// LeagueService's pre-check: POST /api/league/conclude-season is retried by the
// client now, and a duplicate row permanently corrupts a player's history (and
// double-counts Hall of Fame titles, which are summed from these rows).
//
// Note this constrains the number of rows, not the number of *writes*. A resubmission
// carrying different figures corrects this row in place and files the superseded ones
// on $overwriteAudit — see overwriteWith() and LeagueService::concludeSeason().
#[ORM\UniqueConstraint(name: 'uq_season_record_club_season', columns: ['club_id', 'season'])]
class SeasonRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Club $club;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private League $league;

    #[ORM\Column(type: 'smallint')]
    private int $season;

    #[ORM\Column(type: 'smallint')]
    private int $finalPosition;

    #[ORM\Column(type: 'smallint')]
    private int $gamesPlayed;

    #[ORM\Column(type: 'smallint')]
    private int $wins;

    #[ORM\Column(type: 'smallint')]
    private int $draws;

    #[ORM\Column(type: 'smallint')]
    private int $losses;

    #[ORM\Column(type: 'smallint')]
    private int $goalsFor;

    #[ORM\Column(type: 'smallint')]
    private int $goalsAgainst;

    #[ORM\Column(type: 'smallint')]
    private int $points;

    #[ORM\Column]
    private bool $promoted;

    #[ORM\Column]
    private bool $relegated;

    /**
     * How many times this season's result has been corrected by a later resubmission.
     *
     * The authoritative total: $overwriteAudit is an overview and is never trimmed, but
     * this is the number to trust if the two ever disagree.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $overwriteCount = 0;

    /**
     * The superseded results, oldest first — position and simple W/D/L per overwrite.
     *
     * Null on a row that has never been corrected, which is every row written before
     * overwriting was allowed. Deliberately not capped: an entry is ~90 bytes against a
     * season_snapshot.snapshot_data blob measured at 52KB, and an invented bound has
     * twice now rejected real traffic in this codebase (see CLAUDE.md on SnapshotValidator).
     *
     * @var list<array{overwrittenAt: string, finalPosition: int, wins: int, draws: int, losses: int}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $overwriteAudit = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Club $club,
        League $league,
        int $season,
        int $finalPosition,
        int $gamesPlayed,
        int $wins,
        int $draws,
        int $losses,
        int $goalsFor,
        int $goalsAgainst,
        int $points,
        bool $promoted,
        bool $relegated,
    ) {
        $this->id            = new UuidV7();
        $this->club       = $club;
        $this->league        = $league;
        $this->season        = $season;
        $this->finalPosition = $finalPosition;
        $this->gamesPlayed   = $gamesPlayed;
        $this->wins          = $wins;
        $this->draws         = $draws;
        $this->losses        = $losses;
        $this->goalsFor      = $goalsFor;
        $this->goalsAgainst  = $goalsAgainst;
        $this->points        = $points;
        $this->promoted      = $promoted;
        $this->relegated     = $relegated;
        $this->createdAt     = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getLeague(): League { return $this->league; }
    public function getSeason(): int { return $this->season; }
    public function getFinalPosition(): int { return $this->finalPosition; }
    public function getGamesPlayed(): int { return $this->gamesPlayed; }
    public function getWins(): int { return $this->wins; }
    public function getDraws(): int { return $this->draws; }
    public function getLosses(): int { return $this->losses; }
    public function getGoalsFor(): int { return $this->goalsFor; }
    public function getGoalsAgainst(): int { return $this->goalsAgainst; }
    public function getPoints(): int { return $this->points; }
    public function isPromoted(): bool { return $this->promoted; }
    public function isRelegated(): bool { return $this->relegated; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getOverwriteCount(): int { return $this->overwriteCount; }

    /** @return list<array{overwrittenAt: string, finalPosition: int, wins: int, draws: int, losses: int}>|null */
    public function getOverwriteAudit(): ?array { return $this->overwriteAudit; }

    /**
     * The audit as a pretty-printed string, for the admin detail page: EasyAdmin's code
     * editor needs a string rather than an array column. Same accessor pattern as
     * CompetitionResultAudit::getComputationAuditJsonPretty().
     */
    public function getOverwriteAuditPretty(): ?string
    {
        if ($this->overwriteAudit === null) {
            return null;
        }

        return json_encode($this->overwriteAudit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * This row's result, keyed exactly as matchesResult() and overwriteWith() expect it.
     *
     * These ten fields are the whole definition of "the result" for overwrite purposes.
     * SeasonSnapshot's bulk archival blobs (pyramid, seasonLeaders, seasonFixtures) are
     * deliberately outside it — see matchesResult().
     *
     * @return array{finalPosition: int, gamesPlayed: int, wins: int, draws: int, losses: int, goalsFor: int, goalsAgainst: int, points: int, promoted: bool, relegated: bool}
     */
    public function resultFields(): array
    {
        return [
            'finalPosition' => $this->finalPosition,
            'gamesPlayed'   => $this->gamesPlayed,
            'wins'          => $this->wins,
            'draws'         => $this->draws,
            'losses'        => $this->losses,
            'goalsFor'      => $this->goalsFor,
            'goalsAgainst'  => $this->goalsAgainst,
            'points'        => $this->points,
            'promoted'      => $this->promoted,
            'relegated'     => $this->relegated,
        ];
    }

    /**
     * Whether a resubmission says the same thing this row already does — the one place
     * "retry or correction?" is decided.
     *
     * Strict per field and order-independent; a missing key counts as a mismatch rather
     * than a match, so a caller that forgets a field gets an overwrite it can see in the
     * audit instead of a silent no-op.
     *
     * Only the ten result fields are compared. The archival blobs are not: the client
     * retries this POST, and a reordered 52KB seasonFixtures array is a retry, not a
     * correction — hashing those would make every retry look like one. Consequence: a
     * resubmission with identical figures but a different blob rewrites nothing.
     *
     * @param array<string, int|bool> $result
     */
    public function matchesResult(array $result): bool
    {
        foreach ($this->resultFields() as $field => $value) {
            if (!array_key_exists($field, $result) || $result[$field] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Replaces this row's result with a corrected resubmission, filing the superseded
     * position and W/D/L on $overwriteAudit.
     *
     * The count and the audit entry are written together because either on its own is a
     * lie about the other; that is the only reason this is a method rather than ten setters.
     *
     * $league is deliberately not a parameter: it records where the season was *played*,
     * which a correction cannot change — and re-pointing it would silently re-weight
     * SeasonRecordRepository::countTitlesByClubAndTier(), which scores a title per tier.
     * Nor is anything derived recomputed here; see LeagueService::overwriteConcludedSeason().
     *
     * @param array{finalPosition: int, gamesPlayed: int, wins: int, draws: int, losses: int, goalsFor: int, goalsAgainst: int, points: int, promoted: bool, relegated: bool} $result
     */
    public function overwriteWith(array $result, \DateTimeImmutable $at): void
    {
        $audit   = $this->overwriteAudit ?? [];
        $audit[] = [
            'overwrittenAt' => $at->format(\DateTimeInterface::ATOM),
            'finalPosition' => $this->finalPosition,
            'wins'          => $this->wins,
            'draws'         => $this->draws,
            'losses'        => $this->losses,
        ];

        $this->overwriteAudit = array_values($audit);
        $this->overwriteCount++;

        $this->finalPosition = $result['finalPosition'];
        $this->gamesPlayed   = $result['gamesPlayed'];
        $this->wins          = $result['wins'];
        $this->draws         = $result['draws'];
        $this->losses        = $result['losses'];
        $this->goalsFor      = $result['goalsFor'];
        $this->goalsAgainst  = $result['goalsAgainst'];
        $this->points        = $result['points'];
        $this->promoted      = $result['promoted'];
        $this->relegated     = $result['relegated'];
    }
}
