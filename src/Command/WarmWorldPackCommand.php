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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Delete existing cache entries before regenerating')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of tiers to process per batch', 3)
            ->addOption('memory-warning', null, InputOption::VALUE_REQUIRED, 'Memory threshold in MB at which to log a per-tier warning', 256);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io              = new SymfonyStyle($input, $output);
        $country         = strtoupper(trim((string) $input->getArgument('country')));
        $batchSize       = max(1, (int) $input->getOption('batch-size'));
        $memWarningBytes = (int) $input->getOption('memory-warning') * 1024 * 1024;

        if (strlen($country) !== 2) {
            $io->error("Country code must be exactly 2 characters (e.g. EN, ES). Got: '{$country}'.");
            return Command::FAILURE;
        }

        if (ClubInitializationService::countryToNationality($country) === null) {
            $io->error("Unknown country code '{$country}'. Supported codes: EN, IT, DE, ES, BR, AR, NL, FR, PT, NG, GH, JP, KR, SE, DK, IE, CI, SN, CN");
            return Command::FAILURE;
        }

        $leagues = $this->leagueRepository->findByCountry($country);
        if (empty($leagues)) {
            $io->error("No leagues found for country '{$country}'. Seed leagues first.");
            return Command::FAILURE;
        }

        if ($input->getOption('force')) {
            $deleted = $this->worldPackCacheService->deleteByCountry($country);
            $io->note("--force: deleted {$deleted} existing cache entries for {$country}.");
        }

        // buildTierPack() uses $club->getCurrentLeague() only to decide whether to
        // include the player's club in fixture generation. For pre-warming, no player
        // club exists, so we create a transient stub (never persisted) with no league.
        // User constructor requires a string $email, so we provide a throwaway value.
        $dummyUser = new User('__warmup__@warmup.local');
        $dummyClub = new Club('__warmup__', $dummyUser);
        $dummyClub->setCountry($country);

        $totalTiers = count($leagues);
        $batches    = array_chunk($leagues, $batchSize);
        $batchCount = count($batches);

        $output->writeln("Warming <info>{$country}</info>: <info>{$totalTiers}</info> tiers in batches of <info>{$batchSize}</info>");

        $generated = 0;
        $skipped   = 0;
        $failed    = 0;
        $startTime = microtime(true);

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
                        $status = "generated ({$clubCount} clubs, {$playerCount} players)";
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

            // Free the identity map between batches to prevent unbounded memory growth.
            $this->entityManager->clear();

            $batchNum = $batchIndex + 1;
            $progressBar->clear();
            $output->writeln(sprintf(
                'Batch [%d/%d] complete — %d generated, %d skipped, %d failed',
                $batchNum, $batchCount, $batchGenerated, $batchSkipped, $batchFailed
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
