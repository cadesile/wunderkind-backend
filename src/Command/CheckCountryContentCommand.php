<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Country;
use App\Repository\CountryWorldPackCacheRepository;
use App\Repository\LeagueRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\NpcClubRepository;
use App\Repository\StarterConfigRepository;
use App\Service\CountryContent\CountryContentRegistry;
use App\Service\CountryContent\CountryNamingContentProvider;
use App\Service\CountryContent\NamePoolProvider;
use App\Service\Location\CountryReadinessService;
use App\Service\Location\LocationCatalogueService;
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
        private readonly CountryProfileRepository         $countryProfileRepository,
        private readonly LocationCatalogueService         $locationCatalogue,
        private readonly CountryReadinessService          $readinessService,
        private readonly CountryNamingContentProvider     $namingContent,
        private readonly NamePoolProvider                 $namePools,
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

        // Places moved to the `location` table; everything else below is still authored in PHP.
        $io->section('Locations (database)');
        $locationCount = $this->locationCatalogue->countPlaces($countryCode);
        $readiness     = $this->readinessService->evaluate($countryCode);

        $io->table(['Check', 'Status'], array_merge(
            [['Seeded locations', $locationCount > 0
                ? $locationCount . ' active'
                : 'NONE — run app:locations:seed ' . $countryCode . ' (generation falls back to placeholder town names)']],
            array_map(
                static fn (array $check): array => [
                    ucfirst(str_replace('_', ' ', $check['key'])),
                    ($check['ok'] ? 'OK — ' : 'BLOCKED — ') . $check['detail'],
                ],
                $readiness['checks'],
            ),
        ));

        $io->text($readiness['ready']
            ? '<info>Ready: this country may be set Live.</info>'
            : '<comment>Not ready: ' . implode(', ', $readiness['blocking']) . '</comment>');

        $io->section('Club-naming content');
        // Driven off describe(), so each row says WHERE its values came from. An operator
        // looking at "10 entries" needs to know whether that is the PHP fallback or something
        // they authored on the locations dashboard — the two behave identically and are
        // changed in completely different ways. `places` is not asked about here: it is
        // answered above, from the database.
        $fallbacks = [
            'prestigeSuffixes' => 'MISSING (falls back to [FC])',
            'genericSuffixes'  => 'MISSING (falls back to [FC])',
            'stadiumFormats'   => 'MISSING (generic fallback)',
        ];
        $labels = [
            'prestigeSuffixes' => 'Prestige suffixes',
            'genericSuffixes'  => 'Generic suffixes',
            'stadiumFormats'   => 'Stadium formats',
        ];
        $rows = [];

        foreach ($this->namingContent->describe($country) as $category => $state) {
            $rows[] = [
                $labels[$category],
                $state['values'] === []
                    ? $fallbacks[$category]
                    : sprintf(
                        '%d entries (%s)',
                        count($state['values']),
                        $state['source'] === CountryNamingContentProvider::SOURCE_OVERRIDE ? 'admin override' : 'PHP registry',
                    ),
            ];
        }

        $rows[] = ['World region (skin tone)', CountryContentRegistry::worldRegion($country)?->name ?? 'none (uniform fallback)'];
        $rows[] = ['Business cluster', CountryContentRegistry::businessCluster($country) ?? 'none (falls back to _fallback)'];

        $io->table(['Category', 'Status'], $rows);

        $io->section('Name pool');
        $hasPool = $this->namePools->has($country->nationality());
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
            ['profile LIVE (player-visible)', in_array($countryCode, $this->countryProfileRepository->liveCountryCodes(), true) ? 'yes' : 'no'],
        ]);

        if ($missing !== [] || !$hasPool || count($leagues) < 8) {
            $io->warning("{$countryCode} is not fully stood up — see the sections above.");
            return Command::SUCCESS;
        }

        $io->success("{$countryCode} has complete generation content.");
        return Command::SUCCESS;
    }
}
