<?php

namespace App\Entity;

use App\Repository\LiveTelemetrySnapshotRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton row holding the cached 24h pyramid-activity aggregate shown on the
 * landing page's "Chairman's Terminal" widget. Refreshed periodically by
 * app:telemetry:generate (see LiveTelemetryService) rather than computed live
 * on each page load, since it's derived from scanning SyncRecord.payload JSON.
 */
#[ORM\Entity(repositoryClass: LiveTelemetrySnapshotRepository::class)]
class LiveTelemetrySnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $fixturesSimulated = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $capitalDeployedPence = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $resultsWins = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $resultsDraws = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $resultsLosses = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $activeClubs = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $weeksPlayed = 0;

    /**
     * Categorized real-event feed for the "Boardroom Incident & Consequence Feed" —
     * pyramid movement, boardroom outlay, attendance, dressing-room fallout, equity
     * dilution, commercial covenant risk, talisman/community highlights, and cup wins,
     * merged newest-first. Each entry: {time, text, category, categoryLabel, club,
     * detail, amountExact, timestampIso, meta, trophy}. `trophy` is a TrophyConfig and
     * is set only on CUP items. See LiveTelemetryService for how each category is built
     * and LiveTelemetryServiceTest for the exact shape.
     *
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $recentEvents = [];

    /** Signed average fan-morale delta across clubs with ≥2 readings in the window; null if none qualify. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $communityMoraleDelta = null;

    /**
     * 3rd stat box. Goals across every fixture in the window, deduplicated by fixtureId
     * (LiveTelemetryService::aggregate()), with the match count that produced them kept
     * alongside so goals-per-match is derived from the same pass rather than recomputed
     * from a stored average that could drift out of step with the total.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalGoals = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalMatches = 0;

    /**
     * 4th stat box. Clubs promoted or relegated across the whole pyramid in the feed's
     * own 7-day window — SeasonRecord rows, counted by
     * SeasonRecordRepository::countPromotionsAndRelegationsSince().
     *
     * Superseded on the landing page by $promotions/$relegations below, which the tile
     * now renders as two figures. Kept, and still written on every refresh, because
     * GET /api/telemetry serves it as `stats.tableTurnover` — removing it would break a
     * published field for an app release that is already out. It is exactly
     * promotions + relegations, counted as rows (see countTurnoverByDirectionSince()
     * for the one case where that is not the same as adding the two).
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $tableTurnover = 0;

    /**
     * $tableTurnover split by direction, over the SAME 7-day window — so the tile can
     * say "▲ 7 ▼ 4" instead of "11", which is the question the figure is actually
     * asked ("is the pyramid churning upward or downward").
     *
     * Still 7 days, not the 24h stats window, for the reason the window has always been
     * 7 days: a promotion lands only when a season concludes, and on real data that
     * happens on roughly 3 days in 5, so a 24h count reads 0 most of the time — twice
     * as visibly now that it is two numbers instead of one. The tile's sub-line says
     * "7 days" rather than inheriting the other three tiles' "24h".
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $promotions = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $relegations = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $generatedAt;

    public function __construct()
    {
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getFixturesSimulated(): int { return $this->fixturesSimulated; }
    public function getCapitalDeployedPence(): int { return $this->capitalDeployedPence; }
    public function getResultsWins(): int { return $this->resultsWins; }
    public function getResultsDraws(): int { return $this->resultsDraws; }
    public function getResultsLosses(): int { return $this->resultsLosses; }
    public function getActiveClubs(): int { return $this->activeClubs; }
    public function getWeeksPlayed(): int { return $this->weeksPlayed; }
    /** @return array<int, array<string, mixed>> */
    public function getRecentEvents(): array { return $this->recentEvents; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }

    public function getCommunityMoraleDelta(): ?int { return $this->communityMoraleDelta; }
    public function getTotalGoals(): int { return $this->totalGoals; }
    public function getTotalMatches(): int { return $this->totalMatches; }
    public function getTableTurnover(): int { return $this->tableTurnover; }
    public function getPromotions(): int { return $this->promotions; }
    public function getRelegations(): int { return $this->relegations; }

    /** @param array<int, array<string, mixed>> $recentEvents */
    public function update(
        int $fixturesSimulated,
        int $capitalDeployedPence,
        int $wins,
        int $draws,
        int $losses,
        int $activeClubs,
        int $weeksPlayed,
        array $recentEvents,
        ?int $communityMoraleDelta = null,
        int $totalGoals = 0,
        int $totalMatches = 0,
        int $tableTurnover = 0,
        int $promotions = 0,
        int $relegations = 0,
    ): void {
        $this->fixturesSimulated         = $fixturesSimulated;
        $this->capitalDeployedPence      = $capitalDeployedPence;
        $this->resultsWins               = $wins;
        $this->resultsDraws              = $draws;
        $this->resultsLosses             = $losses;
        $this->activeClubs               = $activeClubs;
        $this->weeksPlayed               = $weeksPlayed;
        $this->recentEvents              = $recentEvents;
        $this->communityMoraleDelta      = $communityMoraleDelta;
        $this->totalGoals                = $totalGoals;
        $this->totalMatches              = $totalMatches;
        $this->tableTurnover             = $tableTurnover;
        $this->promotions                = $promotions;
        $this->relegations               = $relegations;
        $this->generatedAt               = new \DateTimeImmutable();
    }

    /** e.g. "£14.6M" / "£850K" / "£0" — compact, matches the widget's Press Start 2P counter style. */
    public function getCapitalDeployedFormatted(): string
    {
        return self::formatPence($this->capitalDeployedPence);
    }

    /** Shared with LiveTelemetryService's ledger-event lines, so both use the same £K/£M style. */
    public static function formatPence(int $pence): string
    {
        $pounds = $pence / 100;

        if ($pounds >= 1_000_000) {
            return '£' . rtrim(rtrim(number_format($pounds / 1_000_000, 1), '0'), '.') . 'M';
        }
        if ($pounds >= 1_000) {
            return '£' . rtrim(rtrim(number_format($pounds / 1_000, 1), '0'), '.') . 'K';
        }

        return '£' . number_format($pounds, 0);
    }

    /** e.g. "14-6-7" (wins-draws-losses). */
    public function getResultsFormatted(): string
    {
        return "{$this->resultsWins}-{$this->resultsDraws}-{$this->resultsLosses}";
    }

    /**
     * e.g. "£31,000" / "-£4,500" — exact, non-compact, for modal detail lines and
     * the dilution/covenant stat boxes where a rounded £K/£M figure (formatPence())
     * would misrepresent a precise contract value.
     */
    public static function formatPenceExact(int $pence): string
    {
        $pounds = $pence / 100;
        $sign   = $pounds < 0 ? '-' : '';

        return $sign . '£' . number_format(abs($pounds), 0);
    }

    /**
     * The 3rd stat box's sub-line, e.g. "2.85 G/Match".
     *
     * Two decimal places always, including the no-matches case ("0.00"), so the line keeps
     * a constant width in the fixed-width terminal font — a value that changed length
     * between refreshes would shift the column.
     */
    public function getGoalsPerMatchFormatted(): string
    {
        $perMatch = $this->totalMatches > 0 ? $this->totalGoals / $this->totalMatches : 0.0;

        return number_format($perMatch, 2) . ' G/Match';
    }
}
