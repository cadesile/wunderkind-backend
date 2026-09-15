<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\LiveTelemetryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:telemetry:generate',
    description: 'Recompute the 24h pyramid-activity aggregate shown on the landing page "Chairman\'s Terminal" widget',
)]
class GenerateLiveTelemetryCommand extends Command
{
    public function __construct(
        private readonly LiveTelemetryService $liveTelemetryService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->liveTelemetryService->refresh();

        $snapshot = $this->liveTelemetryService->getSnapshot();
        $io->success(sprintf(
            'Live telemetry refreshed: %d fixtures simulated, %s capital deployed, %s results, %d active clubs, %d weeks played (last 24h), %d feed event(s).',
            $snapshot->getFixturesSimulated(),
            $snapshot->getCapitalDeployedFormatted(),
            $snapshot->getResultsFormatted(),
            $snapshot->getActiveClubs(),
            $snapshot->getWeeksPlayed(),
            count($snapshot->getRecentEvents()),
        ));

        return Command::SUCCESS;
    }
}
