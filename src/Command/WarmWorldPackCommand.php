<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\User;
use App\Repository\CountryWorldPackCacheRepository;
use App\Repository\LeagueRepository;
use App\Service\ClubInitializationService;
use App\Service\WorldInitializationService;
use App\Service\WorldPackCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:worldpack:warm',
    description: 'Pre-generate and cache the NPC league pack for a country.',
)]
class WarmWorldPackCommand extends Command
{
    public function __construct(
        private readonly LeagueRepository                $leagueRepository,
        private readonly CountryWorldPackCacheRepository $cacheRepository,
        private readonly WorldInitializationService      $worldInitializationService,
        private readonly WorldPackCacheService           $worldPackCacheService,
        private readonly EntityManagerInterface          $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2 country code (e.g. EN, ES)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Delete existing cache entry/entries before regenerating')
            ->addOption('tier', null, InputOption::VALUE_REQUIRED, 'Process a single tier only (1–8). Recommended for cron use.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Tiers per batch when running all tiers (ignored with --tier)', 3)
            ->addOption('memory-warning', null, InputOption::VALUE_REQUIRED, 'Memory threshold in MB at which to log a warning', 256);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $country = strtoupper(trim((string) $input->getArgument('country')));
        $tierOpt = $input->getOption('tier');

        if (strlen($country) !== 2) {
            $io->error("Country code must be exactly 2 characters. Got: '{$country}'.");
            return Command::FAILURE;
        }

        if (ClubInitializationService::countryToNationality($country) === null) {
            $io->error("Unknown country code '{$country}'. Supported: EN IT DE ES BR AR NL FR PT NG GH JP KR SE DK IE CI SN CN");
            return Command::FAILURE;
        }

        // ── Single-tier mode (used by cron) ──────────────────────────────────
        if ($tierOpt !== null) {
            return $this->executeSingleTier($io, $output, $country, (int) $tierOpt, (bool) $input->getOption('force'));
        }

        // ── All-tiers batch mode (interactive / admin) ────────────────────────
        return $this->executeAllTiers($io, $output, $input, $country);
    }

    private function executeSingleTier(
        SymfonyStyle    $io,
        OutputInterface $output,
        string          $country,
        int             $tier,
        bool            $force,
    ): int {
        if ($tier < 1 || $tier > 8) {
            $io->error("Tier must be between 1 and 8. Got: {$tier}.");
            return Command::FAILURE;
        }

        // Silently succeed if this tier has no league (country may have fewer than 8 tiers).
        $league = $this->leagueRepository->findByCountryAndTier($country, $tier);
        if ($league === null) {
            $output->writeln("<comment>[{$country}/T{$tier}] No league — skipping.</comment>");
            return Command::SUCCESS;
        }

        $dummyUser = new User('__warmup__@warmup.local');
        $dummyClub = new Club('__warmup__', $dummyUser);
        $dummyClub->setCountry($country);

        $start = microtime(true);
        try {
            $builder = fn() => $this->worldInitializationService->buildTierPack($dummyClub, $country, $tier);

            // forceRebuild generates the payload first, then atomically replaces the old
            // entry — so a failed build never leaves the cache empty.
            // getOrBuild skips existing entries (used when --force is not set).
            $payload = $force
                ? $this->worldPackCacheService->forceRebuild($country, $tier, $builder)
                : $this->worldPackCacheService->getOrBuild($country, $tier, $builder);
            $clubCount   = count($payload['clubs'] ?? []);
            $playerCount = array_sum(array_map(fn($c) => count($c['players'] ?? []), $payload['clubs'] ?? []));
            $duration    = round(microtime(true) - $start, 1);
            $peakMb      = round(memory_get_peak_usage(true) / 1024 / 1024, 1);

            $io->success(sprintf(
                '[%s/T%d] generated — %d clubs, %d players | %.1fs | peak %sMB',
                $country, $tier, $clubCount, $playerCount, $duration, $peakMb
            ));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('[%s/T%d] FAILED: %s', $country, $tier, $e->getMessage()));
            return Command::FAILURE;
        }
    }

    private function executeAllTiers(
        SymfonyStyle    $io,
        OutputInterface $output,
        InputInterface  $input,
        string          $country,
    ): int {
        $batchSize       = max(1, (int) $input->getOption('batch-size'));
        $memWarningBytes = (int) $input->getOption('memory-warning') * 1024 * 1024;

        $leagues = $this->leagueRepository->findByCountry($country);
        if (empty($leagues)) {
            $io->error("No leagues found for '{$country}'. Seed leagues first.");
            return Command::FAILURE;
        }

        if ($input->getOption('force')) {
            $deleted = $this->worldPackCacheService->deleteByCountry($country);
            $io->note("--force: deleted {$deleted} existing cache entries for {$country}.");
        }

        $dummyUser = new User('__warmup__@warmup.local');
        $dummyClub = new Club('__warmup__', $dummyUser);
        $dummyClub->setCountry($country);

        $totalTiers = count($leagues);
        $batches    = array_chunk($leagues, $batchSize);
        $batchCount = count($batches);
        $generated  = 0;
        $skipped    = 0;
        $failed     = 0;
        $startTime  = microtime(true);

        $output->writeln("Warming <info>{$country}</info>: <info>{$totalTiers}</info> tiers in batches of <info>{$batchSize}</info>");

        $progressBar = new ProgressBar($output, $totalTiers);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $progressBar->start();

        foreach ($batches as $batchIndex => $batchLeagues) {
            $batchGenerated = 0;
            $batchSkipped   = 0;
            $batchFailed    = 0;

            foreach ($batchLeagues as $league) {
                $tier       = $league->getTier();
                $alreadyHit = $this->cacheRepository->findForCountryAndTier($country, $tier) !== null;

                try {
                    $payload = $this->worldPackCacheService->getOrBuild(
                        $country,
                        $tier,
                        fn() => $this->worldInitializationService->buildTierPack($dummyClub, $country, $tier)
                    );

                    if ($alreadyHit) {
                        $batchSkipped++;
                        $skipped++;
                        $status = 'skipped (cached)';
                    } else {
                        $batchGenerated++;
                        $generated++;
                        $clubCount   = count($payload['clubs'] ?? []);
                        $playerCount = array_sum(array_map(fn($c) => count($c['players'] ?? []), $payload['clubs'] ?? []));
                        $status      = "generated ({$clubCount} clubs, {$playerCount} players)";
                    }
                } catch (\Throwable $e) {
                    $batchFailed++;
                    $failed++;
                    $status = 'FAILED: ' . $e->getMessage();
                }

                $memNow = memory_get_usage(true);
                $memMb  = round($memNow / 1024 / 1024, 1);

                $progressBar->clear();
                $output->writeln("  [tier {$tier}] {$status} | mem: {$memMb}MB");
                if ($memNow > $memWarningBytes) {
                    $output->writeln(sprintf(
                        '  <comment>[WARN] tier %d memory (%sMB) exceeds threshold (%dMB)</comment>',
                        $tier, $memMb, $memWarningBytes / 1024 / 1024
                    ));
                }
                $progressBar->display();
                $progressBar->advance();
            }

            $this->entityManager->clear();

            $progressBar->clear();
            $output->writeln(sprintf(
                'Batch [%d/%d] complete — %d generated, %d skipped, %d failed',
                $batchIndex + 1, $batchCount, $batchGenerated, $batchSkipped, $batchFailed
            ));
            $progressBar->display();
        }

        $progressBar->finish();
        $output->writeln('');

        $duration = round(microtime(true) - $startTime, 1);
        $peakMb   = round(memory_get_peak_usage(true) / 1024 / 1024, 1);

        $io->table(
            ['Metric', 'Value'],
            [
                ['Generated',   $generated],
                ['Skipped',     $skipped],
                ['Failed',      $failed],
                ['Peak memory', "{$peakMb}MB"],
                ['Duration',    "{$duration}s"],
            ]
        );

        if ($failed > 0) {
            $io->error("{$failed} tier(s) failed for {$country}.");
            return Command::FAILURE;
        }

        $io->success("World pack warmed for {$country}.");
        return Command::SUCCESS;
    }
}
