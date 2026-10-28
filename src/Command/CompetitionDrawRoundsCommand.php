<?php

namespace App\Command;

use App\Service\Competition\CompetitionDrawService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron entry point (every 1 min — draws must fire near their scheduled timestamp). Due
 * state lives directly on CompetitionRound.status/scheduledAt, not a GameConfig side-table,
 * so this does the work via CompetitionDrawService directly rather than dispatching a
 * second sub-command.
 */
#[AsCommand(
    name: 'app:competition:draw-rounds',
    description: 'Draws every CompetitionRound whose scheduledAt is due.',
)]
class CompetitionDrawRoundsCommand extends Command
{
    public function __construct(
        private readonly CompetitionDrawService $drawService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Queues rather than draws. The work moved to the `competition` transport so a tick
        // costs one row per due round instead of drawing them all inline — see
        // CompetitionDrawService::dispatchDueDraws(). Drained by competition-consume.sh.
        $queued = $this->drawService->dispatchDueDraws(new \DateTimeImmutable());

        $io->writeln($queued > 0 ? "Queued {$queued} due round(s) for drawing." : 'No round is due to be drawn.');

        return Command::SUCCESS;
    }
}
