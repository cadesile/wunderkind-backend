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
     * dilution, commercial covenant risk, and talisman/community highlights, merged
     * newest-first. Each entry: {time, text, category, categoryLabel, club, detail,
     * amountExact, timestampIso, meta}. See LiveTelemetryService for how each
     * category is built and LiveTelemetryServiceTest for the exact shape.
     *
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $recentEvents = [];

    /** Full count of resolved excursions with frictionCount > 0 in the window — not just what fits in the feed. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $dressingRoomFalloutCount = 0;

    /** Signed average fan-morale delta across clubs with ≥2 readings in the window; null if none qualify. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $communityMoraleDelta = null;

    /** Most notable investor_income ledger entry this window, parsed from its free-text description. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $dilutionEquityPercent = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $dilutionClubName = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $dilutionCounterparty = null;

    /** Count of active promises[] with a league_position/tier_reached termination condition. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $covenantActiveCount = 0;

    /** Total seasonal value-at-risk (pence) across those same active covenants. */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $covenantSeasonalValuePence = 0;

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

    public function getDressingRoomFalloutCount(): int { return $this->dressingRoomFalloutCount; }
    public function getCommunityMoraleDelta(): ?int { return $this->communityMoraleDelta; }
    public function getDilutionEquityPercent(): ?int { return $this->dilutionEquityPercent; }
    public function getDilutionClubName(): ?string { return $this->dilutionClubName; }
    public function getDilutionCounterparty(): ?string { return $this->dilutionCounterparty; }
    public function getCovenantActiveCount(): int { return $this->covenantActiveCount; }
    public function getCovenantSeasonalValuePence(): int { return $this->covenantSeasonalValuePence; }

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
        ?int $dilutionEquityPercent = null,
        ?string $dilutionClubName = null,
        ?string $dilutionCounterparty = null,
        int $dressingRoomFalloutCount = 0,
        ?int $communityMoraleDelta = null,
        int $covenantActiveCount = 0,
        int $covenantSeasonalValuePence = 0,
    ): void {
        $this->fixturesSimulated         = $fixturesSimulated;
        $this->capitalDeployedPence      = $capitalDeployedPence;
        $this->resultsWins               = $wins;
        $this->resultsDraws              = $draws;
        $this->resultsLosses             = $losses;
        $this->activeClubs               = $activeClubs;
        $this->weeksPlayed               = $weeksPlayed;
        $this->recentEvents              = $recentEvents;
        $this->dilutionEquityPercent     = $dilutionEquityPercent;
        $this->dilutionClubName          = $dilutionClubName;
        $this->dilutionCounterparty      = $dilutionCounterparty;
        $this->dressingRoomFalloutCount  = $dressingRoomFalloutCount;
        $this->communityMoraleDelta      = $communityMoraleDelta;
        $this->covenantActiveCount       = $covenantActiveCount;
        $this->covenantSeasonalValuePence = $covenantSeasonalValuePence;
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

    /** e.g. "5% — Bert's Fencing" or "No dilution activity" if nothing qualified this window. */
    public function getDilutionSummaryFormatted(): string
    {
        if ($this->dilutionEquityPercent === null) {
            return 'No dilution activity';
        }

        $counterparty = $this->dilutionCounterparty ?? 'an investor';

        return "{$this->dilutionEquityPercent}% — {$counterparty}";
    }

    /** e.g. "4 covenants // 3 fallout" — the redesigned 4th stat box. */
    public function getCovenantFalloutSummaryFormatted(): string
    {
        return "{$this->covenantActiveCount} covenants // {$this->dressingRoomFalloutCount} fallout";
    }
}
