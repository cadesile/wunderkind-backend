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
    private int $goalsScored = 0;

    /**
     * Real, anonymised promotion/relegation/title events from recent SeasonRecord
     * rows — never a club name. Each entry: {time: string, text: string}.
     *
     * @var array<int, array{time: string, text: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $recentEvents = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $generatedAt;

    public function __construct()
    {
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getFixturesSimulated(): int { return $this->fixturesSimulated; }
    public function getCapitalDeployedPence(): int { return $this->capitalDeployedPence; }
    public function getGoalsScored(): int { return $this->goalsScored; }
    /** @return array<int, array{time: string, text: string}> */
    public function getRecentEvents(): array { return $this->recentEvents; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }

    /** @param array<int, array{time: string, text: string}> $recentEvents */
    public function update(int $fixturesSimulated, int $capitalDeployedPence, int $goalsScored, array $recentEvents): void
    {
        $this->fixturesSimulated    = $fixturesSimulated;
        $this->capitalDeployedPence = $capitalDeployedPence;
        $this->goalsScored          = $goalsScored;
        $this->recentEvents         = $recentEvents;
        $this->generatedAt          = new \DateTimeImmutable();
    }

    /** e.g. "£14.6M" / "£850K" / "£0" — compact, matches the widget's Press Start 2P counter style. */
    public function getCapitalDeployedFormatted(): string
    {
        $pounds = $this->capitalDeployedPence / 100;

        if ($pounds >= 1_000_000) {
            return '£' . rtrim(rtrim(number_format($pounds / 1_000_000, 1), '0'), '.') . 'M';
        }
        if ($pounds >= 1_000) {
            return '£' . rtrim(rtrim(number_format($pounds / 1_000, 1), '0'), '.') . 'K';
        }

        return '£' . number_format($pounds, 0);
    }
}
