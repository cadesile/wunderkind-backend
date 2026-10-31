<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\LeaderboardCategory;
use App\Service\HallOfFameScoreService;
use App\Service\Leaderboard\ActivePeriodService;
use App\Service\Leaderboard\LeaderboardPeriod;
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
        private readonly HallOfFameScoreService $hallOfFameScoreService,
        private readonly ActivePeriodService $activePeriodService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('category', 'c', InputOption::VALUE_OPTIONAL, 'Single category slug to regenerate (default: all)')
            ->addOption('period', 'p', InputOption::VALUE_OPTIONAL, 'Period to regenerate: all-time | current-week | both (= every active period, including any enabled monthly/quarterly award cadence)', 'both')
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

        // 'both' means every period that should currently hold rows — which is 'all-time'
        // and the ISO week as before, plus the current key of any ENABLED monthly or
        // quarterly award definition. The option name is kept for the crontab's sake; the
        // list behind it is no longer hardcoded here.
        $periods = match ($input->getOption('period')) {
            'all-time'     => [LeaderboardPeriod::ALL_TIME],
            'current-week' => [LeaderboardPeriod::currentWeekKey()],
            'both'         => $this->activePeriodService->allActivePeriods(),
            default        => null,
        };

        if ($periods === null) {
            $io->error("Invalid --period value. Must be: all-time, current-week, or both.");
            return Command::FAILURE;
        }

        $warmCache = (bool) $input->getOption('warm-cache');

        // hall_of_fame is also mirrored onto Club::$hallOfFamePoints, which /api/club/status and
        // the sync response read. Backfill it so those surfaces agree with the board.
        if (in_array(LeaderboardCategory::HALL_OF_FAME, $categories, true)) {
            $changed = $this->hallOfFameScoreService->syncAllClubs();
            $io->text("Hall of Fame: updated Club.hallOfFamePoints on {$changed} club(s).");
        }

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
