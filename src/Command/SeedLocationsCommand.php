<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Enum\Country;
use App\Repository\Location\CountryProfileRepository;
use App\Service\ClubNameNormalizer;
use App\Service\CountryContent\CountryContentRegistry;
use App\Service\Location\CountryReadinessService;
use App\Service\Location\LocationClassificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Moves the hardcoded town records out of PHP and into the `location` table.
 *
 * Source is CountryContentRegistry::PLACE_NAMES_BY_COUNTRY — 865 curated records across 25
 * countries, each `['name' =>, 'population_size' =>, 'region' =>, 'is_capital' =>]`. Each
 * becomes one `country_profile` row (if absent), one `location`, and one `land_plot`
 * numbered 1, so one-club-per-town holds until an operator adds more plots.
 *
 * ACT-ONLY-WHEN-EMPTY, SCOPED PER COUNTRY — SeedDefaultLanguageCommand's philosophy, not
 * SeedExcursionsCommand's upsert-by-code. These rows get corrected by hand (coordinates, land
 * values, locked fields) and they will carry claims and real money; an upsert on every deploy
 * would permanently stomp an admin's corrections, which is the hazard
 * SeedDefaultLanguageCommand's own docblock warns about, here with higher stakes.
 * `--force-country=XX` is the single explicit escape hatch, and it only ADDS missing towns —
 * it never rewrites one that already exists.
 *
 * WHAT IT DELIBERATELY DOES NOT WRITE: `lat`, `lng` and `land_pence` are left NULL. No
 * coordinate or valuation data exists anywhere in PHP, and those arrive later via AI
 * enrichment (app:locations:enrich). Writing 0/0 instead would put every un-enriched town at
 * the same point in the Atlantic, where the rival pass would treat them as each other's
 * nearest neighbours. The command reports the count it left for enrichment so this is visible
 * rather than surprising.
 *
 * `population` and `region` ARE seeded, with population_source recording that they came from
 * here — enrichment fills gaps only and will not overwrite them.
 */
#[AsCommand(
    name: 'app:locations:seed',
    description: 'Seeds country_profile, location and land_plot rows from CountryContentRegistry. Per-country no-op once a country has locations.',
)]
class SeedLocationsCommand extends Command
{
    /** Recorded on every row this command writes, so its provenance is never ambiguous. */
    public const POPULATION_SOURCE = 'CountryContentRegistry (seeded)';

    public function __construct(
        private readonly CountryProfileRepository      $countryProfileRepository,
        private readonly LocationClassificationService $classificationService,
        private readonly CountryReadinessService       $readinessService,
        private readonly SluggerInterface              $slugger,
        private readonly EntityManagerInterface        $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'country',
                InputArgument::OPTIONAL,
                'ISO 3166-1 alpha-2 code (e.g. EN, JP). Omit to seed every country the registry has places for.',
            )
            ->addOption(
                'force-country',
                null,
                InputOption::VALUE_REQUIRED,
                'Add any MISSING towns to a country that already has locations. Implies that country as the scope. Never rewrites an existing row.',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be written and change nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $forced   = $input->getOption('force-country');
        $forced   = is_string($forced) ? strtoupper(trim($forced)) : null;
        $argument = $input->getArgument('country');

        if ($argument !== null && $forced !== null && strtoupper(trim((string) $argument)) !== $forced) {
            $io->error('--force-country must name the same country as the argument, or the argument must be omitted.');

            return Command::FAILURE;
        }

        // --force-country implies its own scope. Without this, `--force-country=EN` with no
        // argument fell through to "seed every country", which is a surprising amount of
        // writing for a flag that names one country.
        $scope = $argument !== null ? (string) $argument : $forced;

        $countries = $this->resolveCountries($scope, $io);

        if ($countries === null) {
            return Command::FAILURE;
        }

        if ($countries === []) {
            $io->warning('No countries have place data in CountryContentRegistry.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note('Dry run — nothing will be written.');
        }

        $rows             = [];
        $totalLocations   = 0;
        $totalPlots       = 0;
        $awaitingEnrichment = 0;

        foreach ($countries as $country) {
            $result = $this->seedCountry($country, $forced === $country->value, $dryRun, $io);

            $rows[]             = $result;
            $totalLocations    += $result['locations'];
            $totalPlots        += $result['plots'];
            $awaitingEnrichment += $result['locations'];
        }

        $io->table(
            ['Country', 'Locations added', 'Plots added', 'Outcome'],
            array_map(static fn (array $r): array => [
                $r['country'],
                (string) $r['locations'],
                (string) $r['plots'],
                $r['outcome'],
            ], $rows),
        );

        if ($dryRun) {
            $io->success(sprintf(
                'Dry run: would add %d location(s) and %d plot(s).',
                $totalLocations,
                $totalPlots,
            ));

            return Command::SUCCESS;
        }

        if ($awaitingEnrichment > 0) {
            // Said loudly on purpose: a seeded country is NOT ready to go live, and the
            // reason is not obvious from the row count alone.
            $io->warning(sprintf(
                '%d location(s) have no coordinates and no land value — there is none in PHP to seed. '
                . 'Run app:locations:enrich to fill them, then app:locations:rivals.',
                $awaitingEnrichment,
            ));
        }

        $this->reportReadiness($countries, $io);

        $io->success(sprintf('Seeded %d location(s) and %d plot(s).', $totalLocations, $totalPlots));

        return Command::SUCCESS;
    }

    /**
     * @return list<Country>|null null signals a bad argument (already reported)
     */
    private function resolveCountries(?string $argument, SymfonyStyle $io): ?array
    {
        if ($argument !== null) {
            $code    = strtoupper(trim($argument));
            $country = Country::tryFrom($code);

            if ($country === null) {
                $io->error("'{$code}' is not an App\\Enum\\Country case. A genuinely new country needs an enum case first — see docs/world-generation/adding-a-country.md.");

                return null;
            }

            if (CountryContentRegistry::places($country) === null) {
                $io->error("'{$code}' has no entry in CountryContentRegistry::PLACE_NAMES_BY_COUNTRY, so there is nothing to seed.");

                return null;
            }

            return [$country];
        }

        return array_values(array_filter(
            Country::cases(),
            static fn (Country $c): bool => CountryContentRegistry::places($c) !== null,
        ));
    }

    /**
     * @return array{country: string, locations: int, plots: int, outcome: string}
     */
    private function seedCountry(Country $country, bool $forced, bool $dryRun, SymfonyStyle $io): array
    {
        $code   = $country->value;
        $places = CountryContentRegistry::places($country) ?? [];

        $existingCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM location WHERE country_code = :code',
            ['code' => $code],
        );

        if ($existingCount > 0 && !$forced) {
            return [
                'country'   => $code,
                'locations' => 0,
                'plots'     => 0,
                'outcome'   => "skipped — {$existingCount} location(s) already exist (use --force-country={$code} to add missing towns)",
            ];
        }

        $profile = $this->countryProfileRepository->findByCode($code);

        if ($profile === null) {
            // name and defaultLocale are MIRRORED from the enum, so an admin can correct a
            // label without a deploy while the enum stays the canonical code list.
            $profile = new CountryProfile($code, $country->label());
            $profile->setDefaultLocale($country->locale());

            if (!$dryRun) {
                $this->em->persist($profile);
                $this->em->flush();
            }
        }

        // Existing slugs and normalised names, so --force-country can add without colliding
        // with uq_location_country_slug and without duplicating a town already present.
        $existing = $this->em->getConnection()->fetchAllAssociative(
            'SELECT slug, town_normalised FROM location WHERE country_code = :code',
            ['code' => $code],
        );

        $usedSlugs = [];
        $usedTowns = [];
        foreach ($existing as $row) {
            $usedSlugs[(string) $row['slug']]            = true;
            $usedTowns[(string) $row['town_normalised']] = true;
        }

        $locations = 0;
        $plots     = 0;

        foreach ($places as $place) {
            $town       = (string) ($place['name'] ?? '');
            $normalised = ClubNameNormalizer::normalize($town);

            if ($town === '' || isset($usedTowns[$normalised])) {
                continue;
            }

            $slug = $this->uniqueSlug($code, $town, $usedSlugs);

            $usedSlugs[$slug]       = true;
            $usedTowns[$normalised] = true;

            ++$locations;
            ++$plots;

            if ($dryRun) {
                continue;
            }

            $location = new Location($profile, $town, $slug, $normalised);
            $location->setRegion((string) ($place['region'] ?? ''));
            $location->setIsCapital((bool) ($place['is_capital'] ?? false));

            $population = $place['population_size'] ?? null;
            if ($population !== null) {
                $location->setPopulation((int) $population);
                $location->setPopulationSource(self::POPULATION_SOURCE);
            }

            $this->em->persist($location);
            // Exactly one plot per town — see LandPlot's docblock on why the level exists now.
            $this->em->persist(new LandPlot($location, 1));
        }

        if (!$dryRun && $locations > 0) {
            $this->em->flush();
            $this->em->clear();

            // city_size is a property of the whole country's set, so it can only be computed
            // once every town for this country is in.
            $sizes = $this->classificationService->reclassifyCountry($code);

            if ($sizes !== []) {
                $io->writeln(sprintf(
                    '  <info>%s</info> classified: %s',
                    $code,
                    implode(', ', array_map(
                        static fn (string $size, int $n): string => "{$size}={$n}",
                        array_keys($sizes),
                        $sizes,
                    )),
                ));
            }
        }

        $outcome = $locations === 0
            ? 'nothing to add — every registry town already present'
            : ($forced && $existingCount > 0 ? 'forced: missing towns added' : 'seeded');

        return [
            'country'   => $code,
            'locations' => $locations,
            'plots'     => $plots,
            'outcome'   => $outcome,
        ];
    }

    /**
     * `en-salford`, deduplicated within the country.
     *
     * The slug is display and log convenience only — nothing references it, and the primary
     * key is a UuidV7 precisely so a renamed or re-spelled town is not a data migration. It
     * still has to be unique per country, because uq_location_country_slug says so.
     *
     * @param array<string, true> $used
     */
    private function uniqueSlug(string $code, string $town, array $used): string
    {
        $base = strtolower($code) . '-' . strtolower($this->slugger->slug($town)->toString());
        $base = trim(substr($base, 0, 72), '-');

        if ($base === strtolower($code) . '-' || $base === '') {
            // A town whose name slugifies to nothing (non-Latin script with no transliteration
            // available). Fall back to a stable hash so the row is still addressable.
            $base = strtolower($code) . '-' . substr(md5($town), 0, 8);
        }

        if (!isset($used[$base])) {
            return $base;
        }

        for ($n = 2; $n < 1000; $n++) {
            $candidate = "{$base}-{$n}";

            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }

        return $base . '-' . substr(md5($town . microtime()), 0, 6);
    }

    /** @param list<Country> $countries */
    private function reportReadiness(array $countries, SymfonyStyle $io): void
    {
        $rows = [];

        foreach ($countries as $country) {
            $verdict = $this->readinessService->evaluate($country->value);

            $rows[] = [
                $country->value,
                $verdict['ready'] ? 'ready' : 'not ready',
                $verdict['blocking'] === [] ? '—' : implode(', ', $verdict['blocking']),
            ];
        }

        $io->section('Readiness (CountryReadinessService)');
        $io->table(['Country', 'Verdict', 'Blocking checks'], $rows);
        $io->writeln('A country is gated out of GET /api/locations until its profile status is LIVE.');
    }
}
