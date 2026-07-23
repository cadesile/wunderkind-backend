<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\LeaderboardCategory;
use App\Service\LeaderboardCalculationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:leaderboards:generate',
    description: 'Recalculate leaderboard scores/ranks and invalidate the leaderboard cache for one or all categories',
)]
class GenerateLeaderboardsCommand extends Command
{
    public function __construct(
        private readonly LeaderboardCalculationService $calculationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('category', 'c', InputOption::VALUE_OPTIONAL, 'Single category slug to regenerate (default: all)')
            ->addOption('period', 'p', InputOption::VALUE_OPTIONAL, 'Period to regenerate: all-time | current-week | both', 'both')
            ->addOption('warm-cache', null, InputOption::VALUE_NONE, 'Also populate the cache pool after recalculation (default: invalidate only, let next read populate)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $categoryOption = $input->getOption('category');
        if ($categoryOption !== null) {
            $category = LeaderboardCategory::tryFrom($categoryOption);
            if ($category === null) {
                $valid = implode(', ', array_column(LeaderboardCategory::cases(), 'value'));
                $io->error("Invalid category '{$categoryOption}'. Valid values: {$valid}");
                return Command::FAILURE;
            }
            $categories = [$category];
        } else {
            $categories = LeaderboardCategory::cases();
        }

        $periods = match ($input->getOption('period')) {
            'all-time'     => ['all-time'],
            'current-week' => [(new \DateTimeImmutable())->format('o-\WW')],
            'both'         => ['all-time', (new \DateTimeImmutable())->format('o-\WW')],
            default        => null,
        };

        if ($periods === null) {
            $io->error("Invalid --period value. Must be: all-time, current-week, or both.");
            return Command::FAILURE;
        }

        $warmCache = (bool) $input->getOption('warm-cache');

        $progressBar = $io->createProgressBar(count($categories) * count($periods));
        $progressBar->start();

        foreach ($categories as $category) {
            foreach ($periods as $period) {
                $this->calculationService->recalculate($category, $period);
                $this->calculationService->invalidate($category, $period);

                if ($warmCache) {
                    $this->calculationService->getLeaderboard($category, $period, 1, 20);
                }

                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $io->newLine(2);
        $io->success('Leaderboards regenerated.');

        return Command::SUCCESS;
    }
}
