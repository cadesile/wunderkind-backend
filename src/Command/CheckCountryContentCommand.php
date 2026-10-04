<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Country;
use App\Repository\CountryWorldPackCacheRepository;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Repository\StarterConfigRepository;
use App\Service\CountryContent\CountryContentRegistry;
use App\Service\NameGeneratorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only audit of how fully a country is stood up — the "what's missing" answer
 * app:country:bootstrap's own warning points at. See docs/world-generation/adding-a-country.md.
 */
#[AsCommand(
    name: 'app:country:check',
    description: 'Report which generation content/data exists for a country and what is still missing.',
)]
class CheckCountryContentCommand extends Command
{
    public function __construct(
        private readonly LeagueRepository                $leagueRepository,
        private readonly NpcClubRepository                $npcClubRepository,
        private readonly CountryWorldPackCacheRepository  $cacheRepository,
        private readonly StarterConfigRepository          $starterConfigRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2 country code (e.g. US, JP)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $countryCode = strtoupper(trim((string) $input->getArgument('country')));
        $country     = Country::tryFrom($countryCode);

        if ($country === null) {
            $io->error("'{$countryCode}' is not a Country case — add it to App\\Enum\\Country first.");
            return Command::FAILURE;
        }

        $io->title("Country content check: {$countryCode} ({$country->label()})");

        $io->section('Country enum');
        $io->table(['Property', 'Value'], [
            ['Nationality', $country->nationality()],
            ['Locale', $country->locale()],
            ['Generation-capable', $country->isGenerationCapable() ? 'yes' : 'no'],
        ]);

        $io->section('CountryContentRegistry content');
        $missing = CountryContentRegistry::missingCategories($country);
        $io->table(['Category', 'Status'], [
            ['Places', in_array('places', $missing, true) ? 'MISSING (generic fallback)' : count(CountryContentRegistry::places($country) ?? []) . ' entries'],
            ['Prestige suffixes', in_array('prestigeSuffixes', $missing, true) ? 'MISSING (falls back to [FC])' : count(CountryContentRegistry::prestigeSuffixes($country)) . ' entries'],
            ['Generic suffixes', in_array('genericSuffixes', $missing, true) ? 'MISSING (falls back to [FC])' : count(CountryContentRegistry::genericSuffixes($country)) . ' entries'],
            ['Stadium formats', in_array('stadiumFormats', $missing, true) ? 'MISSING (generic fallback)' : count(CountryContentRegistry::stadiumFormats($country)) . ' entries'],
            ['World region (skin tone)', CountryContentRegistry::worldRegion($country)?->name ?? 'none (uniform fallback)'],
            ['Business cluster', CountryContentRegistry::businessCluster($country) ?? 'none (falls back to _fallback)'],
        ]);

        $io->section('Name pool');
        $hasPool = NameGeneratorService::hasNamePool($country->nationality());
        $io->text($hasPool
            ? "Name pool present for '{$country->nationality()}'."
            : "MISSING — generated players/staff will silently fall back to 'English' names.");

        $io->section('Leagues');
        $leagues = $this->leagueRepository->findByCountry($countryCode);
        $io->text(sprintf('%d of 8 tiers have a League row.', count($leagues)));

        $io->section('NPC clubs');
        $clubCounts = $this->npcClubRepository->getCountsByCountryAndTier()[$countryCode] ?? [];
        $clubRows = [];
        for ($tier = 1; $tier <= 8; $tier++) {
            $clubRows[] = [$tier, $clubCounts[$tier] ?? 0];
        }
        $io->table(['Tier', 'NPC clubs'], $clubRows);

        $io->section('World pack cache');
        $cachedTiers = $this->cacheRepository->findCachedTiers($countryCode);
        $io->text(sprintf('Cached tiers: %s', $cachedTiers === [] ? 'none' : implode(', ', $cachedTiers)));

        $io->section('Starter config');
        $config = $this->starterConfigRepository->getConfig();
        $io->table(['Setting', 'Value'], [
            ['leagueAbilityRanges entry', isset($config->getLeagueAbilityRanges()[$countryCode]) ? 'present' : 'MISSING'],
            ['enabledCountries (player-visible)', in_array($countryCode, $config->getEnabledCountries(), true) ? 'yes' : 'no'],
        ]);

        if ($missing !== [] || !$hasPool || count($leagues) < 8) {
            $io->warning("{$countryCode} is not fully stood up — see the sections above.");
            return Command::SUCCESS;
        }

        $io->success("{$countryCode} has complete generation content.");
        return Command::SUCCESS;
    }
}
