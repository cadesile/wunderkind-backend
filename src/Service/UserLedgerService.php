<?php

namespace App\Service;

use App\Entity\Club;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\UserLedgerEntryType;
use App\Repository\UserLedgerRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single writer for UserLedger rows. Scans a sync payload's free-form ledger entries
 * (SyncRequest::$ledger — the same array SyncService already archives verbatim onto
 * SyncRecord::$payload) for the 'dividend_draw' category and records one UserLedger row per
 * match, chaining the user's overall centralized balance across entries.
 *
 * Two callers: SyncService::process() for live syncs, and the one-time
 * app:backfill-user-ledger command replaying every historical SyncRecord. Both must compute
 * balanceBefore/After identically, which is why that logic lives here once instead of being
 * duplicated per caller.
 *
 * `dividend_draw` is sent as **real pence, not the 100x-inflated value every pre-existing
 * `ledger[].category` carries** (see `03_data/output/schema.md`'s "sync_record.payload field
 * conventions" in .context) — deliberately: that inflation is a bug in the client's existing
 * on-device ledger-writing engine, and this is a brand-new category with no shipped client
 * code yet, so there's no reason to inherit it. Same convention as
 * `promises[].offer.amountPence`, which is correctly-scaled for the identical reason. See
 * `docs/api/user-ledger.md` for the frontend-facing contract. Do **not** route this amount
 * through `LiveTelemetryService::ledgerAmountToPence()`.
 */
class UserLedgerService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserLedgerRepository   $userLedgerRepository,
    ) {}

    /**
     * @param  array<int, array{category?: string, amount?: int, description?: string}|object{category: string, amount: int, description: string}> $ledgerEntries
     * @param  int|null $knownBalanceBefore Skip the DB lookup and chain from this balance instead —
     *         used by the backfill command, which keeps its own in-memory running balance per user
     *         across many SyncRecord rows processed in one pass (a fresh query per row would miss
     *         balances from rows created earlier in the same pass but not yet flushed).
     * @return UserLedger[] newly created entries, in payload order
     */
    public function recordDividendDraws(
        User $user,
        Club $club,
        SyncRecord $sourceSyncRecord,
        array $ledgerEntries,
        \DateTimeImmutable $occurredAt,
        \DateTimeImmutable $recordedAt,
        ?int $knownBalanceBefore = null,
    ): array {
        $created = [];
        $balance = $knownBalanceBefore;

        foreach ($ledgerEntries as $index => $entry) {
            $category    = is_array($entry) ? ($entry['category'] ?? '') : $entry->category;
            $description = is_array($entry) ? ($entry['description'] ?? '') : $entry->description;
            $amount      = (int) (is_array($entry) ? ($entry['amount'] ?? 0) : $entry->amount);

            if ($category !== UserLedgerEntryType::DIVIDEND_DRAW->value || $amount === 0) {
                continue;
            }

            // Idempotent: re-running the backfill command, or re-processing a resent sync
            // whose SyncRecord happens to be reused, must not double-count the same draw.
            if ($this->userLedgerRepository->existsForSource($sourceSyncRecord, $index)) {
                continue;
            }

            $balance ??= $this->userLedgerRepository->getCurrentBalance($user);

            $ledgerRow = UserLedger::fromSyncLedgerEntry(
                $user,
                $club,
                UserLedgerEntryType::DIVIDEND_DRAW,
                $amount,
                $balance,
                $occurredAt,
                $recordedAt,
                $sourceSyncRecord,
                $index,
            );
            if ($description !== '') {
                $ledgerRow->setDescription($description);
            }

            $this->em->persist($ledgerRow);

            $balance  = $ledgerRow->getBalanceAfterPence();
            $created[] = $ledgerRow;
        }

        return $created;
    }

    /**
     * Record one backend-originated entry — a grant, a bonus, a purchase, a sell-back, a reversal.
     *
     * THE SINGLE WRITER for everything that is not a sync-reported dividend draw, for the same
     * reason recordDividendDraws() is the single writer for those: the before/after chaining has
     * to exist in exactly one place or the running balance stops being reconstructible.
     *
     * TAKES A PER-USER ADVISORY LOCK, and that is not belt-and-braces. getCurrentBalance() is an
     * unlocked `SELECT ... LIMIT 1`, so two concurrent writers for one user read the same
     * balanceBefore and the chain FORKS — after which getCurrentBalance() returns whichever row
     * happens to sort last, and the account's balance depends on a race. That was survivable
     * while the only writer was sync, which the device serialises per club; it is not survivable
     * now that a player can tap "buy" twice. pg_advisory_xact_lock rather than
     * `SELECT ... FOR UPDATE` on the user row, to avoid contending with the auth and
     * refresh-token paths that legitimately read it.
     *
     * IDEMPOTENT on (sourceKind, sourceRef, type): a repeat call returns the ORIGINAL entry and
     * writes nothing. Checked rather than left to uq_user_ledger_backend_source, because a
     * unique violation out of flush() closes the shared EntityManager and this runs on a
     * user-facing write.
     *
     * @throws \InvalidArgumentException if a debit is not negative — a positive LAND_PURCHASE
     *         would credit the account it was meant to charge, and that is a programming error
     *         worth failing loudly on rather than reconciling later
     */
    public function append(
        User $user,
        ?Club $club,
        UserLedgerEntryType $type,
        int $amountPence,
        string $sourceKind,
        string $sourceRef,
        ?string $description = null,
        ?\DateTimeImmutable $occurredAt = null,
    ): UserLedger {
        if ($type->isDebit() && $amountPence > 0) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be recorded as a negative amount; %d would credit the account instead of charging it.',
                $type->value,
                $amountPence,
            ));
        }

        if ($sourceKind === '' || $sourceRef === '') {
            throw new \InvalidArgumentException(
                'A backend ledger entry needs a sourceKind and a sourceRef — they are its only idempotency guard.',
            );
        }

        return $this->em->wrapInTransaction(function () use (
            $user,
            $club,
            $type,
            $amountPence,
            $sourceKind,
            $sourceRef,
            $description,
            $occurredAt,
        ): UserLedger {
            // Serialises every balance read-modify-write for this one user, for the duration of
            // the transaction. Released automatically on commit or rollback.
            $this->em->getConnection()->executeStatement(
                "SELECT pg_advisory_xact_lock(hashtext('user_ledger:' || :userId))",
                ['userId' => (string) $user->getId()],
            );

            $existing = $this->userLedgerRepository->findByBackendSource($sourceKind, $sourceRef, $type);

            if ($existing !== null) {
                return $existing;
            }

            $entry = UserLedger::forBackendEvent(
                $user,
                $club,
                $type,
                $amountPence,
                $this->userLedgerRepository->getCurrentBalance($user),
                $sourceKind,
                $sourceRef,
                $occurredAt,
            );

            if ($description !== null && $description !== '') {
                $entry->setDescription($description);
            }

            $this->em->persist($entry);
            $this->em->flush();

            return $entry;
        });
    }
}
