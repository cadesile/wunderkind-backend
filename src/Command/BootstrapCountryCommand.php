<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StarterConfig;
use App\Enum\Country;
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
        private readonly NpcClubGenerationService    $npcClubGenerationService,
        private readonly StarterConfigRepository     $starterConfigRepository,
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
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Also add this country to StarterConfig::enabledCountries (player-visible at club creation) — off by default since generation-capable and player-visible are deliberately separate concepts');
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

        // ── 1. Leagues (tiers 1-8, skips existing) ───────────────────────────
        $createdLeagues = $this->leagueService->generateLeaguesForCountry($countryCode);
        $io->section('Leagues');
        $io->text(sprintf('%d new league(s) created (existing tiers skipped).', count($createdLeagues)));

        // ── 2. NPC clubs, one tier at a time ──────────────────────────────────
        $io->section('NPC Clubs');
        $deleteExisting = (bool) $input->getOption('delete-existing');
        for ($tier = 1; $tier <= 8; $tier++) {
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
            $enabled = $config->getEnabledCountries();
            if (!in_array($countryCode, $enabled, true)) {
                $enabled[] = $countryCode;
                $config->setEnabledCountries($enabled);
                $this->em->flush();
                $io->text("Added {$countryCode} to StarterConfig::enabledCountries.");
            }
        }

        $io->success("Bootstrap complete for {$countryCode}. Run `app:country:check {$countryCode}` to verify.");
        return Command::SUCCESS;
    }
}
