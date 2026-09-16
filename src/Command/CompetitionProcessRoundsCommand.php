<?php

namespace App\Command;

use App\Service\Competition\CompetitionRoundProcessorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron entry point (every 1 min — matches must fire near their scheduled timestamp).
 * Due-state lives directly on CompetitionRound.scheduledAt/status, not a GameConfig
 * side-table, so this does the work via CompetitionRoundProcessorService directly
 * rather than dispatching a second sub-command the way PostCommunityStatTickCommand
 * dispatches app:post-community-stat.
 */
#[AsCommand(
    name: 'app:competition:process-rounds',
    description: 'Executes every CompetitionRound whose scheduledAt is due.',
)]
class CompetitionProcessRoundsCommand extends Command
{
    public function __construct(
        private readonly CompetitionRoundProcessorService $processor,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $processed = $this->processor->processDueRounds(new \DateTimeImmutable());

        $io->writeln($processed > 0 ? "Processed {$processed} due round(s)." : 'No round is due.');

        return Command::SUCCESS;
    }
}
