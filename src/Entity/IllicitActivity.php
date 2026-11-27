<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\IllicitActivityKind;
use App\Repository\IllicitActivityRepository;
use App\Service\IllicitActivity\IllicitActivityReport;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One cheat a club reported in its sync (2026-11-27), starting with sham sponsors the Personal
 * Assistant pitched.
 *
 * RECORD ONLY. The device does every financial calculation; nothing here changes a club balance,
 * a ledger or an owner balance. Investigations and penalties are a later slice.
 *
 * NEVER DELETED. The app sends its full list on every sync. A record the server has seen but a
 * later payload leaves out (a restored save, a rollback) keeps its row and gets `missingSince`;
 * it is cleared if the record comes back. One row per (club, clientId).
 */
#[ORM\Entity(repositoryClass: IllicitActivityRepository::class)]
#[ORM\Table(name: 'club_illicit_activity')]
#[ORM\UniqueConstraint(name: 'uq_club_illicit_activity_client', columns: ['club_id', 'client_id'])]
#[ORM\Index(name: 'idx_club_illicit_activity_kind', columns: ['kind'])]
class IllicitActivity
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ENDED  = 'ended';
    public const STATUSES      = [self::STATUS_ACTIVE, self::STATUS_ENDED];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Club $club;

    /** The device's own id for the record (UUIDv7), stable for the record's life. */
    #[ORM\Column(length: 64)]
    private string $clientId;

    #[ORM\Column(length: 32, enumType: IllicitActivityKind::class)]
    private IllicitActivityKind $kind;

    #[ORM\Column(type: 'integer')]
    private int $startWeek;

    #[ORM\Column(type: 'integer')]
    private int $startSeason;

    /** 0–100, fixed by the device when the cheat started. No effect yet. */
    #[ORM\Column(type: 'smallint')]
    private int $suspicion;

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $endedWeek = null;

    /** Kind-specific, already validated (IllicitActivityValidator). Money in real pence. */
    #[ORM\Column(type: 'json')]
    private array $details = [];

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\ManyToOne(targetEntity: SyncRecord::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SyncRecord $lastSyncRecord = null;

    /** Set when a sync's full list left this record out; cleared when it reappears. */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $missingSince = null;

    public function __construct(Club $club, string $clientId, IllicitActivityKind $kind, \DateTimeImmutable $seenAt)
    {
        $this->id          = new UuidV7();
        $this->club        = $club;
        $this->clientId    = $clientId;
        $this->kind        = $kind;
        $this->firstSeenAt = $seenAt;
        $this->lastSeenAt  = $seenAt;
        $this->startWeek   = 0;
        $this->startSeason = 0;
        $this->suspicion   = 0;
        $this->status      = self::STATUS_ACTIVE;
    }

    /** Overwrite the reported fields from the newest payload. `kind` and `clientId` never change. */
    public function applyReport(IllicitActivityReport $r, \DateTimeImmutable $seenAt, ?SyncRecord $syncRecord): void
    {
        $this->startWeek      = $r->startWeek;
        $this->startSeason    = $r->startSeason;
        $this->suspicion      = $r->suspicion;
        $this->status         = $r->status;
        $this->endedWeek      = $r->endedWeek;
        $this->details        = $r->details;
        $this->lastSeenAt     = $seenAt;
        $this->lastSyncRecord = $syncRecord;
        $this->missingSince   = null;
    }

    public function markMissing(\DateTimeImmutable $at): void
    {
        $this->missingSince ??= $at;
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getClientId(): string { return $this->clientId; }
    public function getKind(): IllicitActivityKind { return $this->kind; }
    public function getStartWeek(): int { return $this->startWeek; }
    public function getStartSeason(): int { return $this->startSeason; }
    public function getSuspicion(): int { return $this->suspicion; }
    public function getStatus(): string { return $this->status; }
    public function getEndedWeek(): ?int { return $this->endedWeek; }
    /** @return array<string, mixed> */
    public function getDetails(): array { return $this->details; }
    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function getLastSyncRecord(): ?SyncRecord { return $this->lastSyncRecord; }
    public function getMissingSince(): ?\DateTimeImmutable { return $this->missingSince; }

    /** For the admin list: the deal's name, when the kind has one. */
    public function getDetailName(): ?string
    {
        $name = $this->details['name'] ?? null;

        return \is_string($name) ? $name : null;
    }

    /** For the admin detail page. */
    public function getDetailsJson(): string
    {
        return (string) json_encode($this->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** For the admin list: weekly payment in pence, when the kind has one. */
    public function getDetailWeeklyPayment(): ?int
    {
        $v = $this->details['weeklyPayment'] ?? null;

        return \is_int($v) ? $v : null;
    }
}
