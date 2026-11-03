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
// Partial, because both columns are NULL on every sync-sourced row and PostgreSQL treats NULLs
// as distinct — without the predicate this index would be useless for the rows it exists to
// protect, and would collide across the ones it does not. `type` is in the key because one plot
// claim legitimately writes both a LAND_PURCHASE and a STADIUM_PURCHASE against the same ref.
// The predicate is spelled exactly as pg_get_expr(indpred, indrelid) reports it, per CLAUDE.md.
#[ORM\UniqueConstraint(
    name: 'uq_user_ledger_backend_source',
    columns: ['source_kind', 'source_ref', 'type'],
    options: ['where' => '((source_kind IS NOT NULL) AND (source_ref IS NOT NULL))'],
)]
class UserLedger
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * The club this entry is attributed to, or NULL for an account-level entry.
     *
     * NULLABLE AND ON DELETE SET NULL, both changed from NOT NULL / no-action. Two reasons:
     *
     * 1. Account-level entries genuinely have no club. A starting grant, a quiz bonus, or a
     *    land purchase made before the player has founded a club are all facts about the
     *    ACCOUNT, and there is no club to attribute them to.
     *
     * 2. It fixes a real bug. While this was NOT NULL with a plain FK it could neither be
     *    cascaded nor nulled, so both delete paths had to DELETE the rows outright — and
     *    because the running balance is a chain of before/after snapshots, removing a club's
     *    most recent entries rolled the user's balance back to an earlier figure.
     *    DeleteAdminController's own docblock described that consequence and wished otherwise.
     *    Nulling the column keeps the rows, and the balance, intact.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Club $club = null;

    #[ORM\Column(length: 30, enumType: UserLedgerEntryType::class)]
    private UserLedgerEntryType $type;

    /** Value of this entry, pence/cents. Positive for a dividend draw — credits the user's overall balance. */
    /**
     * BIGINT, not integer, and that is not future-proofing — it is required.
     *
     * These were 4-byte integers, capping at £21,474,836. The economy is priced on real-world
     * values (StadiumEstimateService: ~£1,500/seat, so a London ground is ~£135M), and the whole
     * point of an account-level balance is that it accumulates across ten to fifteen clubs — so
     * both a single transaction and the running total routinely exceed that cap. The old type
     * made the ledger physically unable to record a flagship purchase.
     */
    #[ORM\Column(type: 'bigint')]
    private int $amountPence;

    /** User's overall centralized balance immediately before this entry, pence/cents. */
    #[ORM\Column(type: 'bigint')]
    private int $balanceBeforePence;

    /** User's overall centralized balance immediately after this entry, pence/cents (always balanceBeforePence + amountPence). */
    #[ORM\Column(type: 'bigint')]
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

    /**
     * What produced a BACKEND-originated entry, e.g. 'plot_claim' or 'starting_grant'.
     *
     * uq_user_ledger_source_entry makes sync-sourced rows idempotent, but it keys on
     * (sourceSyncRecord, sourceLedgerIndex) — both NULL for every backend entry, and PostgreSQL
     * treats NULLs as distinct, so it offers those rows no protection at all. A double-tapped
     * purchase would write two rows and debit twice. These two columns plus
     * uq_user_ledger_backend_source are that guard.
     */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $sourceKind = null;

    /** The specific thing within that source — a claim id, a reversed row's id, a grant scope. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sourceRef = null;

    /**
     * PRIVATE, so every row is created through a named factory that knows its own provenance.
     *
     * There are exactly two ways a ledger row comes into existence — read out of a sync payload,
     * or originated by the backend — and they have different required fields. A single public
     * constructor able to serve both would need Club and SyncRecord nullable, and would then
     * silently accept a sync-sourced row with no source: legal to the type system, and invisible
     * to uq_user_ledger_source_entry, which is the thing that makes re-running the backfill
     * command safe. Two factories make the distinction impossible to get wrong.
     */
    private function __construct(
        User $user,
        UserLedgerEntryType $type,
        int $amountPence,
        int $balanceBeforePence,
        \DateTimeImmutable $occurredAt,
        \DateTimeImmutable $recordedAt,
    ) {
        $this->id                 = new UuidV7();
        $this->user               = $user;
        $this->type               = $type;
        $this->amountPence        = $amountPence;
        $this->balanceBeforePence = $balanceBeforePence;
        // The invariant the whole table rests on: the running total is reconstructible from
        // history alone, so this is computed here and has no setter.
        $this->balanceAfterPence  = $balanceBeforePence + $amountPence;
        $this->occurredAt         = $occurredAt;
        $this->recordedAt         = $recordedAt;
        $this->sourceSyncRecord   = null;
        $this->sourceLedgerIndex  = null;
    }

    /**
     * An entry read out of a sync payload's free-form ledger array.
     *
     * Behaviourally identical to the constructor this replaced, down to the argument order, so
     * the sync path and its tests did not change shape. Club and SyncRecord stay REQUIRED: a
     * client-reported draw is always attributed to the club whose sync reported it, and always
     * carries the source that makes it idempotent.
     */
    public static function fromSyncLedgerEntry(
        User $user,
        Club $club,
        UserLedgerEntryType $type,
        int $amountPence,
        int $balanceBeforePence,
        \DateTimeImmutable $occurredAt,
        \DateTimeImmutable $recordedAt,
        SyncRecord $sourceSyncRecord,
        int $sourceLedgerIndex,
    ): self {
        $entry = new self($user, $type, $amountPence, $balanceBeforePence, $occurredAt, $recordedAt);

        $entry->club              = $club;
        $entry->sourceSyncRecord  = $sourceSyncRecord;
        $entry->sourceLedgerIndex = $sourceLedgerIndex;

        return $entry;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function getClub(): ?Club { return $this->club; }

    /** Detach from a club without losing the entry — used by both delete paths. */
    public function detachClub(): void { $this->club = null; }
    public function getType(): UserLedgerEntryType { return $this->type; }

    // Cast on read: Doctrine hydrates a bigint column as a string.
    public function getAmountPence(): int { return (int) $this->amountPence; }
    public function getBalanceBeforePence(): int { return (int) $this->balanceBeforePence; }
    public function getBalanceAfterPence(): int { return (int) $this->balanceAfterPence; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description; }

    public function getSourceSyncRecord(): ?SyncRecord { return $this->sourceSyncRecord; }
    public function getSourceLedgerIndex(): ?int { return $this->sourceLedgerIndex; }

    public function getSourceKind(): ?string { return $this->sourceKind; }
    public function getSourceRef(): ?string { return $this->sourceRef; }

    /**
     * An entry the backend originated, rather than one read out of a sync payload.
     *
     * A named factory rather than a second constructor signature, and the public constructor is
     * deliberately left as it is: it REQUIRES a SyncRecord and a Club, so it can only ever
     * produce a sync-sourced, club-attributed row. That is the guard. Loosening those
     * parameters to nullable would have been a smaller change but would silently permit a
     * sync-sourced row with no source, defeating uq_user_ledger_source_entry — and nothing in
     * the type system would have objected.
     *
     * `$club` is nullable because a purchase can precede a club existing at all.
     */
    public static function forBackendEvent(
        User $user,
        ?Club $club,
        UserLedgerEntryType $type,
        int $amountPence,
        int $balanceBeforePence,
        string $sourceKind,
        string $sourceRef,
        ?\DateTimeImmutable $occurredAt = null,
        ?\DateTimeImmutable $recordedAt = null,
    ): self {
        $now = new \DateTimeImmutable();

        $entry = new self(
            $user,
            $type,
            $amountPence,
            $balanceBeforePence,
            $occurredAt ?? $now,
            $recordedAt ?? $now,
        );

        $entry->club       = $club;
        $entry->sourceKind = $sourceKind;
        $entry->sourceRef  = $sourceRef;

        return $entry;
    }
}
