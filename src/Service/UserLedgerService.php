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
 * `ledger[].amount` is reported at 100x true pence by every category this codebase has
 * confirmed (see `03_data/output/schema.md`'s "sync_record.payload field conventions" in
 * .context — the bug is in the client's on-device ledger-writing engine itself, not specific
 * to any one category), so this reuses LiveTelemetryService::ledgerAmountToPence() rather than
 * treating a 'dividend_draw' entry's amount as already-correct pence.
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
            $rawAmount   = is_array($entry) ? ($entry['amount'] ?? 0) : $entry->amount;

            if ($category !== UserLedgerEntryType::DIVIDEND_DRAW->value) {
                continue;
            }

            // ledger[].amount is 100x true pence — see this class's docblock.
            $amount = LiveTelemetryService::ledgerAmountToPence($rawAmount);
            if ($amount === 0) {
                continue;
            }

            // Idempotent: re-running the backfill command, or re-processing a resent sync
            // whose SyncRecord happens to be reused, must not double-count the same draw.
            if ($this->userLedgerRepository->existsForSource($sourceSyncRecord, $index)) {
                continue;
            }

            $balance ??= $this->userLedgerRepository->getCurrentBalance($user);

            $ledgerRow = new UserLedger(
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
}
