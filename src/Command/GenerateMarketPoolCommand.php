<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\RecruitmentSource;
use App\Service\MarketPoolService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:market:generate',
    description: 'Generate market pool entities (unassigned players, coaches, scouts, agents)',
)]
class GenerateMarketPoolCommand extends Command
{
    public function __construct(private readonly MarketPoolService $pool)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('players',   null, InputOption::VALUE_OPTIONAL, 'Market players to generate (YOUTH_INTAKE)',       100)
            ->addOption('prospects', null, InputOption::VALUE_OPTIONAL, 'Prospect players to generate (SCOUTING_NETWORK)', 150)
            ->addOption('coaches',   null, InputOption::VALUE_OPTIONAL, 'Coaches to generate',                              20)
            ->addOption('scouts',    null, InputOption::VALUE_OPTIONAL, 'Scouts to generate',                               10)
            ->addOption('agents',    null, InputOption::VALUE_OPTIONAL, 'Universal agents',                                 30)
            ->addOption('replenish', 'r',  InputOption::VALUE_NONE,     'Top up pool to minimum thresholds only')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Wunderkind Factory - Market Pool Generator');

        if ($input->getOption('replenish')) {
            $io->text('Running pool replenishment...');
            $generated = $this->pool->replenishPool();
            if (empty($generated)) {
                $io->success('Pool is already above minimum thresholds — nothing generated.');
            } else {
                $io->success('Replenished: ' . implode(', ', $generated));
            }
            return Command::SUCCESS;
        }

        $playerCount   = (int) $input->getOption('players');
        $prospectCount = (int) $input->getOption('prospects');
        $coachCount    = (int) $input->getOption('coaches');
        $scoutCount    = (int) $input->getOption('scouts');
        $agentCount    = (int) $input->getOption('agents');

        try {
            if ($agentCount > 0) {
                $io->text(sprintf('Generating %d agents...', $agentCount));
                $bar = $io->createProgressBar($agentCount);
                $bar->start();
                $this->pool->generateAgents($agentCount);
                $bar->finish();
                $io->newLine(2);
            }

            if ($playerCount > 0) {
                $io->text(sprintf('Generating %d market players (YOUTH_INTAKE, academy = null)...', $playerCount));
                $bar = $io->createProgressBar($playerCount);
                $bar->start();
                $this->pool->generatePlayers($playerCount, null, RecruitmentSource::YOUTH_INTAKE);
                $bar->finish();
                $io->newLine(2);
            }

            if ($prospectCount > 0) {
                $io->text(sprintf('Generating %d prospect players (SCOUTING_NETWORK, academy = null)...', $prospectCount));
                $bar = $io->createProgressBar($prospectCount);
                $bar->start();
                $this->pool->generatePlayers($prospectCount, null, RecruitmentSource::SCOUTING_NETWORK);
                $bar->finish();
                $io->newLine(2);
            }

            if ($coachCount > 0) {
                $io->text(sprintf('Generating %d pool coaches (academy = null)...', $coachCount));
                $bar = $io->createProgressBar($coachCount);
                $bar->start();
                $this->pool->generateCoaches($coachCount);
                $bar->finish();
                $io->newLine(2);
            }

            if ($scoutCount > 0) {
                $io->text(sprintf('Generating %d scouts...', $scoutCount));
                $bar = $io->createProgressBar($scoutCount);
                $bar->start();
                $this->pool->generateScouts($scoutCount);
                $bar->finish();
                $io->newLine(2);
            }

        } catch (\Throwable $e) {
            $io->error('Failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Market pool generated successfully!');
        $io->definitionList(
            ['Agents'            => $agentCount],
            ['Market Players'    => $playerCount],
            ['Prospect Players'  => $prospectCount],
            ['Pool Coaches'      => $coachCount],
            ['Scouts'            => $scoutCount],
        );

        return Command::SUCCESS;
    }
}
