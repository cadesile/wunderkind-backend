<?php

namespace App\Command;

use App\Service\Competition\CompetitionResultsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron entry point (every 1 min — resolutions must fire near their scheduled timestamp).
 * Due state lives directly on CompetitionRound.status/matchesResolveAt, not a GameConfig
 * side-table, so this does the work via CompetitionResultsService directly rather than
 * dispatching a second sub-command.
 */
#[AsCommand(
    name: 'app:competition:resolve-rounds',
    description: 'Publishes results for every CompetitionRound whose matchesResolveAt is due.',
)]
class CompetitionResolveRoundsCommand extends Command
{
    public function __construct(
        private readonly CompetitionResultsService $resultsService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Queues rather than resolves. A round runs the match engine and narrative generation
        // per fixture, so doing every due round inline in one tick is what did not scale to
        // concurrent tournaments — see CompetitionResultsService::dispatchDueResults().
        // Drained by competition-consume.sh.
        $queued = $this->resultsService->dispatchDueResults(new \DateTimeImmutable());

        $io->writeln($queued > 0 ? "Queued {$queued} due round(s) for resolution." : 'No round is due to have results published.');

        return Command::SUCCESS;
    }
}
