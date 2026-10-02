<?php

namespace App\Command;

use App\Entity\SyncRecord;
use App\Service\UserLedgerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-time historical pass: scans every existing SyncRecord's archived payload for
 * 'dividend_draw' ledger entries predating UserLedger's existence, and backfills a UserLedger
 * row for each — see UserLedgerService, the same writer the live sync path uses.
 *
 * Pre-filters with a raw jsonb containment query (payload is stored as `json`, not `jsonb`,
 * so the cast is explicit) rather than hydrating every SyncRecord ever created — most sync
 * payloads carry no dividend draw at all, and this table can be large (see
 * BackfillAppearancesCommand's docblock on the OOM this kind of table previously caused via a
 * non-streaming load).
 *
 * Processes candidates in real-world chronological order (server_timestamp ASC) — not
 * per-club — because the running balance this produces is per-*user*, across every club that
 * user owns. A per-user running balance is kept in memory for the whole pass (not re-queried
 * per row): a fresh DB query wouldn't see a balance written earlier in this same pass until
 * the next flush, so relying on it would under-count a user with several historical draws.
 *
 * Idempotent: re-running this command is a safe no-op for already-backfilled rows — see
 * UserLedgerRepository::existsForSource() / uq_user_ledger_source_entry.
 */
#[AsCommand(
    name: 'app:backfill-user-ledger',
    description: 'Scans historical sync payloads for dividend_draw ledger entries and backfills UserLedger rows',
)]
final class BackfillUserLedgerCommand extends Command
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserLedgerService      $userLedgerService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $ids = $this->em->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT sr.id
                FROM sync_record sr
                WHERE sr.payload::jsonb -> 'ledger' @> '[{"category":"dividend_draw"}]'::jsonb
                ORDER BY sr.server_timestamp ASC
                SQL,
        );

        $io->text(sprintf('Found %d sync record(s) carrying a dividend_draw ledger entry.', count($ids)));

        /** @var array<string, int> $runningBalances user id => last known balanceAfterPence this pass */
        $runningBalances = [];
        $processed       = 0;
        $created         = 0;

        foreach ($ids as $id) {
            $syncRecord = $this->em->find(SyncRecord::class, $id);
            if ($syncRecord === null || !$syncRecord->isValid()) {
                continue;
            }

            $club   = $syncRecord->getClub();
            $user   = $club->getUser();
            $userId = (string) $user->getId();

            $rows = $this->userLedgerService->recordDividendDraws(
                $user,
                $club,
                $syncRecord,
                $syncRecord->getPayload()['ledger'] ?? [],
                $syncRecord->getClientTimestamp(),
                $syncRecord->getServerTimestamp(),
                $runningBalances[$userId] ?? null,
            );

            if ($rows !== []) {
                $runningBalances[$userId] = end($rows)->getBalanceAfterPence();
                $created += count($rows);
            }

            $processed++;
            if ($processed % self::BATCH_SIZE === 0) {
                $this->em->flush();
                $this->em->clear();
                $io->text(sprintf('...%d processed, %d UserLedger row(s) created so far', $processed, $created));
            }
        }

        $this->em->flush();

        $io->success(sprintf(
            'Backfill complete: %d sync record(s) processed, %d UserLedger row(s) created.',
            $processed,
            $created,
        ));

        return Command::SUCCESS;
    }
}
