<?php

declare(strict_types=1);

namespace App\Service\Club;

use App\Entity\Club\ClubSale;
use App\Enum\UserLedgerEntryType;
use App\Repository\ClubRepository;
use App\Service\OwnerSpotlightService;
use App\Service\UserLedgerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Paying a player for their club, then destroying it.
 *
 * The whole of a buyout, in the order it has to happen. Called from a Messenger handler for one
 * sale at a time, so a go-live batch is the same code with more rows and not a second
 * implementation.
 *
 *     1. credit the account   (idempotent on the sale id)
 *     2. scrub the club       (ClubScrubService — irreversible)
 *     3. mark the sale COMPLETED with the scrub's counts
 *
 * CREDIT FIRST, SCRUB SECOND, AND NOT IN ONE TRANSACTION TOGETHER. This is the only ordering
 * that fails safely, and it is the same reasoning PlotSellBackService::execute() documents for
 * the same pair of operations. UserLedgerService::append() opens its own transaction and takes a
 * per-user advisory lock, so it cannot be enclosed in ours anyway — but more importantly:
 *
 *   - Crash AFTER the credit, BEFORE the scrub: the player has their money and still has their
 *     club. Visible, recoverable, and the retry pays nothing further because the credit is
 *     idempotent on `('club_sale', saleId, CLUB_SALE)`. The sale sits PENDING until re-run.
 *   - Crash AFTER a scrub that ran first: the club and every record of what it was worth are
 *     gone, and there is nothing left to recompute a payout from. Unrecoverable.
 *
 * So the recoverable failure is the one we choose to have.
 *
 * THE HANDLER DOES NOT CATCH. Exceptions are allowed to reach the `failed` transport, the house
 * policy for destructive and financial work, so `messenger:failed:show` surfaces them instead of
 * a swallowed error leaving a PENDING row nobody looks at.
 *
 * NO plot_claim_valuation ROW IS WRITTEN, which is a deliberate departure from the sell-back
 * path. That table is documented as the working of a SELL-BACK — it is OneToOne-unique per
 * claim, it carries sell-back's own reversal fields, and PlotSellBackService::reverse() expects
 * to own it. A buyout is not a sell-back and must not be reversible by that path, since the club
 * it paid for no longer exists to reinstate. The full working lives on `club_sale.working_json`
 * instead, and the released claim records `release_reason = 'club_deleted'`; the claim id is in
 * the stored working, so the two are joinable for support.
 *
 * A BUYOUT IS NOT REVERSIBLE. Correct a mistaken one the way every other money mistake here is
 * corrected — a REVERSAL entry on the ledger — never by editing this row or re-running it. The
 * club cannot come back.
 */
final class ClubSaleService
{
    /** The ledger idempotency namespace. Paired with the sale id as `sourceRef`. */
    public const SOURCE_KIND = 'club_sale';

    public function __construct(
        private readonly ClubRepository        $clubRepository,
        private readonly ClubScrubService      $scrubService,
        private readonly UserLedgerService     $ledgerService,
        private readonly OwnerSpotlightService $ownerSpotlight,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface       $logger,
    ) {}

    /**
     * Execute an authorised sale.
     *
     * SAFE TO RE-RUN at any point. A COMPLETED sale returns immediately; a PENDING one whose
     * credit already landed gets the existing entry back from append() rather than a second
     * credit; a PENDING one whose club is already gone finishes the bookkeeping instead of
     * failing. That last case is the crash-between-steps recovery and is why the club is looked
     * up by stored id rather than being carried on the message.
     */
    public function execute(ClubSale $sale): void
    {
        if ($sale->isCompleted()) {
            $this->logger->info('Club sale already completed; nothing to do', [
                'saleId' => (string) $sale->getId(),
            ]);

            return;
        }

        $agreedPence = $sale->getAgreedPence();

        if ($agreedPence < 0) {
            // A debit dressed as a buyout. Refused here rather than relying on append()'s own
            // guard, because CLUB_SALE is not in isDebit() — append() would happily write it.
            throw new \InvalidArgumentException(sprintf(
                'Club sale %s has a negative agreed figure (%d); a buyout can only credit.',
                (string) $sale->getId(),
                $agreedPence,
            ));
        }

        // Looked up by the stored id, not carried on the message: the row is the work order, and
        // on a retry after a part-done run the club may legitimately be gone already.
        $club = $this->clubRepository->find($sale->getClubId());

        // STEP 1 — the money. Before the club is touched, and committed on its own.
        if ($agreedPence > 0) {
            $entry = $this->ledgerService->append(
                $sale->getUser(),
                // Attributed to NO club. The club is being destroyed and the credit is an
                // account-level fact that must outlive it — `user_ledger.club_id` would be
                // nulled by its own ON DELETE SET NULL moments later anyway. Same choice, for
                // the same reason, as PlotSellBackService's sell-back credit.
                null,
                UserLedgerEntryType::CLUB_SALE,
                $agreedPence,
                self::SOURCE_KIND,
                (string) $sale->getId(),
                // PLAYER-VISIBLE: returned by GET /api/account/ledger beside the type's label.
                $sale->getLedgerNote() ?? sprintf('Club sale — %s', $sale->getClubName()),
            );

            // Recorded before the scrub, so a crash in step 2 still leaves a row naming the
            // entry it paid. Without this, "did this sale already pay?" could only be answered
            // by searching the ledger by source ref.
            $sale->recordLedgerEntry($entry);
            $this->em->flush();
        } else {
            // Zero is allowed: an operator scrubbing a worthless or test club should not be
            // forced to write a £0 credit into somebody's visible payment history.
            $this->logger->info('Club sale authorised at zero; no ledger entry written', [
                'saleId' => (string) $sale->getId(),
            ]);
        }

        // STEP 2 — the club. Irreversible from here.
        $counts = $club !== null
            ? $this->scrubService->scrub($club)
            : ['club' => 0, 'note_already_absent' => 1];

        // STEP 3 — the receipt.
        $sale->markCompleted($counts);
        $this->em->flush();

        // The newest ledger row for this account changes the public Rich List, so the cached
        // board has to go. After the commit, like every other invalidation on these paths.
        if ($agreedPence > 0) {
            $this->ownerSpotlight->clear();
        }

        $this->logger->info('Club sale completed', [
            'saleId'      => (string) $sale->getId(),
            'clubId'      => $sale->getClubId(),
            'clubName'    => $sale->getClubName(),
            'agreedPence' => $agreedPence,
            'counts'      => $counts,
        ]);
    }
}
