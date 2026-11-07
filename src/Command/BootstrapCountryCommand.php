<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StarterConfig;
use App\Enum\Country;
use App\Repository\LeagueRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Service\Location\CountryGoLiveService;
use App\Repository\StarterConfigRepository;
use App\Service\CountryContent\CountryContentRegistry;
use App\Service\LeagueService;
use App\Service\NpcClubGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Chains the steps that today require 1 admin click (Generate League Structure) + 8 admin
 * clicks (Generate Clubs, once per tier) + 2 separate CLI commands (app:pool:warm,
 * app:worldpack:warm) into one command — see docs/world-generation/adding-a-country.md.
 */
#[AsCommand(
    name: 'app:country:bootstrap',
    description: 'Generate leagues, NPC clubs, pool data and the worldpack cache for a country in one pass.',
)]
class BootstrapCountryCommand extends Command
{
    public function __construct(
        private readonly LeagueService              $leagueService,
        private readonly LeagueRepository           $leagueRepository,
        private readonly NpcClubGenerationService    $npcClubGenerationService,
        private readonly StarterConfigRepository     $starterConfigRepository,
        private readonly CountryProfileRepository    $countryProfileRepository,
        private readonly CountryGoLiveService        $goLiveService,
        private readonly EntityManagerInterface      $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2 country code (e.g. US, JP)')
            ->addOption('clubs-per-tier', null, InputOption::VALUE_REQUIRED, 'NPC clubs to generate per tier', 8)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Proceed even if club-generation content is missing (clubs fall back to generic placeholder names)')
            ->addOption('delete-existing', null, InputOption::VALUE_NONE, 'Delete existing NPC clubs per tier before regenerating')
            ->addOption('skip-pool', null, InputOption::VALUE_NONE, 'Skip running app:pool:warm for this country')
            ->addOption('skip-worldpack', null, InputOption::VALUE_NONE, 'Skip running app:worldpack:warm for this country')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Also mark this country LIVE, which is what makes it player-visible at club creation. Off by default: generating a country and opening it to players are separate decisions. Refused if the readiness checks do not pass')
            ->addOption('enable-only', null, InputOption::VALUE_NONE, 'Skip every generation step and ONLY flip player-visibility. Use this on a country that is already bootstrapped — a plain --enable re-runs club generation and would add a second full set of clubs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $countryCode = strtoupper(trim((string) $input->getArgument('country')));
        $clubsPerTier = max(1, (int) $input->getOption('clubs-per-tier'));
        $force        = (bool) $input->getOption('force');

        $country = Country::tryFrom($countryCode);
        if ($country === null) {
            $io->error("Unknown country code '{$countryCode}'. Add it to App\\Enum\\Country first.");
            return Command::FAILURE;
        }

        // --enable-only exists because club generation below is additive, not
        // idempotent: it creates `clubs-per-tier` clubs every run unless
        // --delete-existing is passed. So re-running `--enable` against an
        // already-bootstrapped country to flip visibility silently doubles its
        // club count. Visibility is the one step with no generation side effect,
        // so it gets a way to run on its own.
        if ($input->getOption('enable-only')) {
            $this->enableForPlayers($io, $countryCode);

            return Command::SUCCESS;
        }

        $missing = CountryContentRegistry::missingCategories($country);
        if ($missing !== [] && !$force) {
            $io->warning(sprintf(
                "%s is missing club-generation content: %s.\nClubs will use generic placeholder names (e.g. \"Capital FC\"). Re-run with --force to proceed anyway, or author the content in CountryContentRegistry first.",
                $countryCode,
                implode(', ', $missing),
            ));
            return Command::FAILURE;
        }
        if ($missing !== []) {
            $io->warning(sprintf('%s is missing: %s — proceeding anyway (--force).', $countryCode, implode(', ', $missing)));
        }

        // ── 1. Leagues (skips existing tiers) ────────────────────────────────
        $result = $this->leagueService->generateLeaguesForCountry($countryCode);
        $io->section('Leagues');
        $io->text(sprintf('%d new league(s) created (existing tiers skipped).', count($result['created'])));

        // ── 2. NPC clubs, one tier at a time ──────────────────────────────────
        //
        // Driven off the tiers that ACTUALLY EXIST, not 1..8. A country with a shallower
        // pyramid used to get clubs generated for tiers it has no league for; those rows
        // land with league_id NULL, which makes them invisible to every world pack while
        // still counting in every admin total — garbage accumulating on each run.
        $io->section('NPC Clubs');
        $deleteExisting = (bool) $input->getOption('delete-existing');
        $tiers = array_map(
            static fn ($league) => $league->getTier(),
            $this->leagueRepository->findByCountry($countryCode),
        );
        sort($tiers);

        foreach ($tiers as $tier) {
            $clubs = $this->npcClubGenerationService->generateClubs($clubsPerTier, $tier, $countryCode, $deleteExisting);
            $io->text(sprintf('Tier %d: %d club(s) generated.', $tier, count($clubs)));
        }

        // ── 3. Starter ability-range defaults, if this country has none yet ──
        $config = $this->starterConfigRepository->getConfig();
        $ranges = $config->getLeagueAbilityRanges();
        if (!isset($ranges[$countryCode])) {
            $ranges[$countryCode] = StarterConfig::defaultTierRanges();
            $config->setLeagueAbilityRanges($ranges);
            $this->em->flush();
            $io->text("Seeded default tier ability ranges for {$countryCode} on StarterConfig.");
        }

        // ── 4. Pool warm ───────────────────────────────────────────────────
        if (!$input->getOption('skip-pool')) {
            $io->section('Pool Warm');
            $poolCommand = $this->getApplication()?->find('app:pool:warm');
            if ($poolCommand !== null) {
                $poolCommand->run(new ArrayInput(['country' => $countryCode]), $output);
            }
        }

        // ── 5. World pack warm (all tiers) ───────────────────────────────────
        if (!$input->getOption('skip-worldpack')) {
            $io->section('World Pack Warm');
            $worldpackCommand = $this->getApplication()?->find('app:worldpack:warm');
            if ($worldpackCommand !== null) {
                $worldpackCommand->run(new ArrayInput(['country' => $countryCode]), $output);
            }
        }

        // ── 6. Player-visibility toggle (opt-in) ─────────────────────────────
        if ($input->getOption('enable')) {
            $this->enableForPlayers($io, $countryCode);
        }

        $io->success("Bootstrap complete for {$countryCode}. Run `app:country:check {$countryCode}` to verify.");
        return Command::SUCCESS;
    }

    /**
     * Idempotent: a country already LIVE is a no-op.
     *
     * USED TO APPEND TO StarterConfig::enabledCountries. Player-visibility is now the country
     * profile's own LIVE status, so this sets that instead — one flag, held where whether the
     * country is ready is actually known.
     *
     * IT IS NOW A READINESS CHECK, which it deliberately was not before. While visibility was
     * a separate list, --enable could be taken as the operator's explicit instruction and
     * `app:country:check` was where you went to find out whether the country was finished.
     * Setting LIVE is not a free instruction: it is what GET /api/locations answers from.
     * Covered by BootstrapCountryEnableTest.
     */
    private function enableForPlayers(SymfonyStyle $io, string $countryCode): void
    {
        $profile = $this->countryProfileRepository->findByCode($countryCode);

        if ($profile === null) {
            $io->warning("{$countryCode} has no country profile, so it cannot be made live.");

            return;
        }

        // THROUGH THE SAME GATE AS THE ADMIN SCREENS, and that is newly load-bearing. This
        // used to append to StarterConfig::enabledCountries, which was a separate axis from
        // the profile's status — so setting it without a readiness check harmed nothing.
        // Player-visibility IS the LIVE status now, and CountryReadinessService exists so a
        // half-built country cannot leak out; a CLI that set LIVE directly would be a fourth
        // way in that skipped the gate the other three enforce.
        $result = $this->goLiveService->goLive($profile);

        if (!$result['ok']) {
            $io->warning($result['message']);
            $io->text('Run app:country:check ' . $countryCode . ' for the full verdict.');

            return;
        }

        $io->success($result['message'] . ' It is now player-visible.');
    }
}
