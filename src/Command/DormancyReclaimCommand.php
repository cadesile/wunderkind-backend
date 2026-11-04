<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Location\ClaimReleaseReason;
use App\Enum\Location\DormancyWarningStage;
use App\Repository\Location\ClubDormancyWarningRepository;
use App\Repository\Location\PlotClaimRepository;
use App\Service\Location\PlotSellBackService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reclaim plots from clubs that have been inactive for 30 days, paying the owner.
 *
 * REFUSES TO RECLAIM AN UNWARNED PLOT. A claim with no SENT one-day warning for the current
 * dormancy cycle is skipped and reported, not taken. That is what makes a missed
 * app:plots:dormancy-warn run merely delay a reclaim instead of silently costing somebody a
 * plot they were never told about — and it is why the warning rows exist at all.
 *
 * PAYS EXACTLY WHAT A VOLUNTARY SELL-BACK PAYS, through the same PlotSellBackService::execute().
 * That equality is deliberate and is asserted in PlotSellBackServiceTest: if a dormancy reclaim
 * paid MORE than selling, going quiet would be the optimal play, which is the opposite of what
 * this rule is for. If it paid less, the warnings would need to be provably reliable before that
 * was fair.
 *
 * The payout therefore includes the land, half the stadium, and the club's value — the club is
 * liquidated, so paying only for the land would confiscate everything built on the site. The
 * credit lands on the ACCOUNT, so a player who comes back after a break finds the money rather
 * than the plot.
 *
 * SAFE TO RE-RUN. The release is a conditional UPDATE and the credit is idempotent on the claim
 * id, so a second pass over the same claim pays nothing further.
 */
#[AsCommand(
    name: 'app:plots:dormancy-reclaim',
    description: 'Release plots held by clubs inactive for 30 days, crediting the owner.',
)]
class DormancyReclaimCommand extends Command
{
    public function __construct(
        private readonly PlotClaimRepository           $claimRepository,
        private readonly ClubDormancyWarningRepository $warningRepository,
        private readonly PlotSellBackService           $sellBack,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum claims to reclaim', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be reclaimed and change nothing')
            ->addOption(
                'force-unwarned',
                null,
                InputOption::VALUE_NONE,
                'Reclaim even without a sent warning. For support use on a known-broken cycle only.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io            = new SymfonyStyle($input, $output);
        $dryRun        = (bool) $input->getOption('dry-run');
        $forceUnwarned = (bool) $input->getOption('force-unwarned');
        $limit         = max(1, (int) $input->getOption('limit'));

        $now    = new \DateTimeImmutable();
        $cutoff = $now->modify(sprintf('-%d days', DormancyWarningStage::RECLAIM_AFTER_DAYS));

        $rows = $this->claimRepository->findDormantClaims($cutoff, $limit);

        if ($rows === []) {
            $io->success('No plots are due for reclaim.');

            return Command::SUCCESS;
        }

        $reclaimed = $unwarned = $skipped = 0;
        $paidTotal = 0;
        $table     = [];

        foreach ($rows as $row) {
            $claim = $this->claimRepository->find((string) $row['claim_id']);

            if ($claim === null || !$claim->isActive() || $claim->getClub() === null) {
                $skipped++;
                continue;
            }

            $lastActive = new \DateTimeImmutable((string) $row['last_active_at']);
            $cycleKey   = $lastActive->format('Y-m-d');

            if (!$forceUnwarned && !$this->wasWarned($claim->getId(), $cycleKey)) {
                // The guard that makes this defensible. Reported rather than silently skipped,
                // so an operator can see that the warning pass is behind.
                $unwarned++;
                $table[] = [$row['town'], $claim->getClub()->getName(), '—', 'skipped: not warned this cycle'];
                continue;
            }

            $quote = $this->sellBack->quote($claim);

            if ($dryRun) {
                $table[] = [$row['town'], $claim->getClub()->getName(), $this->pounds($quote['payoutPence']), 'would reclaim'];
                $reclaimed++;
                $paidTotal += $quote['payoutPence'];
                continue;
            }

            $entry = $this->sellBack->execute($claim, ClaimReleaseReason::DORMANCY);

            if ($entry === null) {
                // Released by someone else between the query and here — a legitimate race.
                $skipped++;
                continue;
            }

            $reclaimed++;
            $paidTotal += $entry->getAmountPence();
            $table[] = [$row['town'], $claim->getClub()->getName(), $this->pounds($entry->getAmountPence()), 'reclaimed'];
        }

        if ($table !== []) {
            $io->table(['Town', 'Club', 'Paid', 'Outcome'], $table);
        }

        $io->definitionList(
            ['Considered' => count($rows)],
            [$dryRun ? 'Would reclaim' : 'Reclaimed' => $reclaimed],
            [$dryRun ? 'Would credit' : 'Credited' => $this->pounds($paidTotal)],
            ['Skipped: never warned' => $unwarned],
            ['Skipped: already gone' => $skipped],
        );

        if ($unwarned > 0 && !$forceUnwarned) {
            $io->warning(sprintf(
                '%d plot(s) are past the deadline but have no sent warning for the current cycle. '
                . 'Run app:plots:dormancy-warn first — these are deliberately left alone.',
                $unwarned,
            ));
        }

        if ($dryRun) {
            $io->note('Dry run — nothing was released and nobody was credited.');
        }

        return Command::SUCCESS;
    }

    private function wasWarned(\Symfony\Component\Uid\UuidV7 $claimId, string $cycleKey): bool
    {
        $stages = $this->warningRepository->sentStagesByClaim([(string) $claimId], $cycleKey);

        return in_array(
            DormancyWarningStage::N_MINUS_1->value,
            $stages[(string) $claimId] ?? [],
            true,
        );
    }

    private function pounds(int $pence): string
    {
        return '£' . number_format($pence / 100, 2);
    }
}
