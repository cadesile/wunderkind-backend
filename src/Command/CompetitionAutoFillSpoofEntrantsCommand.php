<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Competition\CompetitionAutoFillService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron entry point (every 1 min — the finest configurable auto-fill delay is 5 minutes, so
 * this needs tighter precision than the other 5/10-min-cadence competition cron jobs).
 */
#[AsCommand(
    name: 'app:competition:auto-fill-spoof-entrants',
    description: 'Fills every REGISTERING instance whose template has opted into auto-fill and is past its delay with spoof entrants.',
)]
class CompetitionAutoFillSpoofEntrantsCommand extends Command
{
    public function __construct(
        private readonly CompetitionAutoFillService $autoFillService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filled = $this->autoFillService->autoFillDueInstances(new \DateTimeImmutable());

        $io->writeln($filled > 0 ? "Auto-filled {$filled} instance(s)." : 'No instance is due for auto-fill.');

        return Command::SUCCESS;
    }
}
