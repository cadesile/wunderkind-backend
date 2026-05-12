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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2 country code (e.g. EN, ES)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Delete existing cache entries before regenerating');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $country = strtoupper(trim((string) $input->getArgument('country')));

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

        foreach ($leagues as $league) {
            $tier       = $league->getTier();
            $alreadyHit = $this->cacheRepository->findForCountryAndTier($country, $tier) !== null;

            $io->write("[tier {$tier}] ");

            try {
                $payload = $this->worldPackCacheService->getOrBuild(
                    $country,
                    $tier,
                    fn() => $this->worldInitializationService->buildTierPack($dummyClub, $country, $tier)
                );
            } catch (\Throwable $e) {
                $io->warning("tier {$tier} failed: " . $e->getMessage() . " — skipping.");
                continue;
            }

            if ($alreadyHit) {
                $io->writeln('already cached — skipped');
            } else {
                $clubCount   = count($payload['clubs'] ?? []);
                $playerCount = array_sum(array_map(fn($c) => count($c['players'] ?? []), $payload['clubs'] ?? []));
                $io->writeln("generated ({$clubCount} clubs, {$playerCount} players)");
            }
        }

        $io->success("World pack warmed for {$country}.");
        return Command::SUCCESS;
    }
}
