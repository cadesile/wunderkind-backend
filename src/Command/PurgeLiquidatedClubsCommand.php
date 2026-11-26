<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Club\ClubLiquidationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Delete clubs liquidated at game over once `delete_after` has passed.
 *
 * The owner's plot is never touched: liquidation detached the claim, and purge() detaches again
 * before ClubScrubService runs, so the scrub's release step has nothing to release. One club per
 * transaction (the scrub's own), so one failure does not hold back the rest.
 */
#[AsCommand(
    name: 'app:clubs:purge-liquidated',
    description: 'Delete liquidated clubs whose delete_after date has passed. Owners keep their plots.',
)]
class PurgeLiquidatedClubsCommand extends Command
{
    public function __construct(private readonly ClubLiquidationService $liquidation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum clubs to delete', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be deleted and change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $due    = $this->liquidation->findDue(new \DateTimeImmutable(), max(1, (int) $input->getOption('limit')));

        if ($due === []) {
            $io->success('No liquidated clubs are due for deletion.');

            return Command::SUCCESS;
        }

        $failed = 0;
        foreach ($due as $club) {
            $label = sprintf('%s (%s, due %s)', $club->getName(), $club->getId(), $club->getDeleteAfter()?->format('Y-m-d'));
            if ($dryRun) {
                $io->writeln('would delete ' . $label);
                continue;
            }
            try {
                $this->liquidation->purge($club);
                $io->writeln('deleted ' . $label);
            } catch (\Throwable $e) {
                $failed++;
                $io->error(sprintf('could not delete %s: %s', $label, $e->getMessage()));
            }
        }

        if ($failed > 0) {
            return Command::FAILURE;
        }
        $io->success(sprintf('%s %d club(s).', $dryRun ? 'Would delete' : 'Deleted', count($due)));

        return Command::SUCCESS;
    }
}
