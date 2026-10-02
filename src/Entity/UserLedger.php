<?php

namespace App\Entity;

use App\Enum\UserLedgerEntryType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Centralized, user-level financial audit trail — currently just dividend draws the client
 * reports against one of the user's clubs. Historically a club's "manager dividend" (see
 * EconomicService::processFinancialYearEnd()) just folded back into that same club's own
 * totalCareerEarnings; now that a single owner avatar persists across every club a User owns
 * (see "Owner Identity" in CLAUDE.md), this is the one place that centralizes earnings across
 * clubs for that owner.
 *
 * Every row snapshots the user's overall balance immediately before and after itself
 * (balanceAfterPence = balanceBeforePence + amountPence), so the running total is
 * reconstructible from history alone rather than trusted to only the latest row. The single
 * writer is UserLedgerService::recordDividendDraws() — both the live sync path
 * (SyncService) and the one-time app:backfill-user-ledger historical pass go through it, so
 * the before/after chaining logic exists in exactly one place.
 *
 * sourceSyncRecord + sourceLedgerIndex identify exactly which ledger-array entry, in which
 * sync payload, produced this row — the uq_user_ledger_source_entry unique constraint is what
 * makes re-running the backfill command a safe no-op.
 */
#[ORM\Entity(repositoryClass: \App\Repository\UserLedgerRepository::class)]
#[ORM\Table(name: 'user_ledger')]
#[ORM\Index(columns: ['user_id', 'recorded_at'], name: 'idx_user_ledger_user_recorded')]
#[ORM\UniqueConstraint(name: 'uq_user_ledger_source_entry', columns: ['source_sync_record_id', 'source_ledger_index'])]
class UserLedger
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The club this entry is attributed to — the club whose sync reported the draw. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Club $club;

    #[ORM\Column(length: 30, enumType: UserLedgerEntryType::class)]
    private UserLedgerEntryType $type;

    /** Value of this entry, pence/cents. Positive for a dividend draw — credits the user's overall balance. */
    #[ORM\Column(type: 'integer')]
    private int $amountPence;

    /** User's overall centralized balance immediately before this entry, pence/cents. */
    #[ORM\Column(type: 'integer')]
    private int $balanceBeforePence;

    /** User's overall centralized balance immediately after this entry, pence/cents (always balanceBeforePence + amountPence). */
    #[ORM\Column(type: 'integer')]
    private int $balanceAfterPence;

    /** In-game date the draw occurred, as reported by the client — the originating sync's clientTimestamp. */
    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** Real-world date the server recorded this entry — the originating sync's serverTimestamp (an actual live sync, or the historical sync being replayed by the backfill command). */
    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    /** Free-text client description, carried over verbatim from the ledger entry for audit context. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    /**
     * The SyncRecord whose payload['ledger'] reported this draw — lets an auditor trace a row
     * back to the exact payload. Nullable + SET NULL (not CASCADE): a rollback purges
     * superseded SyncRecord rows (SyncRecordRepository::deleteByClubFromWeek()), but a
     * dividend draw already recorded against a user's balance must survive that purge — this
     * is an audit trail, same reasoning as PlayerCareerStatSnapshot::$syncRecord.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_sync_record_id', nullable: true, onDelete: 'SET NULL')]
    private ?SyncRecord $sourceSyncRecord;

    /** Index of this entry within sourceSyncRecord's payload['ledger'] array — see the class docblock on the unique constraint this backs. */
    #[ORM\Column(name: 'source_ledger_index', type: 'integer', nullable: true)]
    private ?int $sourceLedgerIndex;

    public function __construct(
        User $user,
        Club $club,
        UserLedgerEntryType $type,
        int $amountPence,
        int $balanceBeforePence,
        \DateTimeImmutable $occurredAt,
        \DateTimeImmutable $recordedAt,
        SyncRecord $sourceSyncRecord,
        int $sourceLedgerIndex,
    ) {
        $this->id                = new UuidV7();
        $this->user               = $user;
        $this->club               = $club;
        $this->type               = $type;
        $this->amountPence        = $amountPence;
        $this->balanceBeforePence = $balanceBeforePence;
        $this->balanceAfterPence  = $balanceBeforePence + $amountPence;
        $this->occurredAt         = $occurredAt;
        $this->recordedAt         = $recordedAt;
        $this->sourceSyncRecord   = $sourceSyncRecord;
        $this->sourceLedgerIndex  = $sourceLedgerIndex;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function getClub(): Club { return $this->club; }
    public function getType(): UserLedgerEntryType { return $this->type; }

    public function getAmountPence(): int { return $this->amountPence; }
    public function getBalanceBeforePence(): int { return $this->balanceBeforePence; }
    public function getBalanceAfterPence(): int { return $this->balanceAfterPence; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description; }

    public function getSourceSyncRecord(): ?SyncRecord { return $this->sourceSyncRecord; }
    public function getSourceLedgerIndex(): ?int { return $this->sourceLedgerIndex; }
}
