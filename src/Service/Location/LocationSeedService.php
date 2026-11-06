<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Enum\Country;
use App\Repository\Location\CountryProfileRepository;
use App\Service\ClubNameNormalizer;
use App\Service\CountryContent\CountryContentRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Moves the hardcoded town records out of PHP and into the `location` table.
 *
 * Source is CountryContentRegistry::PLACE_NAMES_BY_COUNTRY — 865 curated records across 25
 * countries, each `['name' =>, 'population_size' =>, 'region' =>, 'is_capital' =>]`. Each
 * becomes one `country_profile` row (if absent), one `location`, and one `land_plot` numbered
 * 1, so one-club-per-town holds until an operator adds more plots.
 *
 * THE SHARED IMPLEMENTATION BEHIND BOTH FACES. app:locations:seed and the admin "Seed" buttons
 * both call this and neither contains any seeding logic of its own, so the two cannot drift —
 * the same reasoning that made LocationEnrichmentOrchestrator shared between the enrich command
 * and the enrichment screen. This class does no presentation: it returns counts and the caller
 * renders them.
 *
 * ACT-ONLY-WHEN-EMPTY, SCOPED PER COUNTRY — SeedDefaultLanguageCommand's philosophy, not
 * SeedExcursionsCommand's upsert-by-code. These rows get corrected by hand (coordinates, land
 * values, locked fields) and they carry claims and real money; an upsert on every deploy would
 * permanently stomp an admin's corrections, which is the hazard SeedDefaultLanguageCommand's own
 * docblock warns about, here with higher stakes. Forcing is the single explicit escape hatch,
 * and it only ADDS missing towns — it never rewrites one that already exists.
 *
 * WHAT IT DELIBERATELY DOES NOT WRITE: `lat`, `lng` and `land_pence` are left NULL. No
 * coordinate or valuation data exists anywhere in PHP, and those arrive later via AI enrichment.
 * Writing 0/0 instead would put every un-enriched town at the same point in the Atlantic, where
 * the rival pass would treat them as each other's nearest neighbours. Callers report the count
 * left for enrichment so this is visible rather than surprising.
 */
final class LocationSeedService
{
    /** Recorded on every row this writes, so its provenance is never ambiguous. */
    public const POPULATION_SOURCE = 'CountryContentRegistry (seeded)';

    public function __construct(
        private readonly CountryProfileRepository      $countryProfileRepository,
        private readonly LocationClassificationService $classificationService,
        private readonly SluggerInterface              $slugger,
        private readonly EntityManagerInterface        $em,
    ) {}

    /** Every country the registry actually has places for. */
    /** @return list<Country> */
    public function seedableCountries(): array
    {
        return array_values(array_filter(
            Country::cases(),
            static fn (Country $c): bool => CountryContentRegistry::places($c) !== null,
        ));
    }

    /**
     * Resolve one country code to a seedable case.
     *
     * @throws \InvalidArgumentException with a message fit to show an operator
     */
    public function resolveCountry(string $code): Country
    {
        $code    = strtoupper(trim($code));
        $country = Country::tryFrom($code);

        if ($country === null) {
            throw new \InvalidArgumentException(sprintf(
                "'%s' is not an App\\Enum\\Country case. A genuinely new country needs an enum case "
                . 'first — see docs/world-generation/adding-a-country.md.',
                $code,
            ));
        }

        if (CountryContentRegistry::places($country) === null) {
            throw new \InvalidArgumentException(sprintf(
                "'%s' has no entry in CountryContentRegistry::PLACE_NAMES_BY_COUNTRY, so there is "
                . 'nothing to seed.',
                $code,
            ));
        }

        return $country;
    }

    /**
     * Seed several countries.
     *
     * @param list<Country> $countries
     *
     * @return array{rows: list<array<string, mixed>>, locations: int, plots: int}
     */
    public function seedMany(array $countries, ?string $forcedCode = null, bool $dryRun = false): array
    {
        $rows      = [];
        $locations = 0;
        $plots     = 0;

        foreach ($countries as $country) {
            $row = $this->seedCountry($country, $forcedCode === $country->value, $dryRun);

            $rows[]     = $row;
            $locations += $row['locations'];
            $plots      += $row['plots'];
        }

        return ['rows' => $rows, 'locations' => $locations, 'plots' => $plots];
    }

    /**
     * @return array{country: string, locations: int, plots: int, outcome: string,
     *     citySizes: array<string, int>}
     */
    public function seedCountry(Country $country, bool $forced = false, bool $dryRun = false): array
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
                'outcome'   => sprintf(
                    'skipped — %d location(s) already exist (force to add missing towns)',
                    $existingCount,
                ),
                'citySizes' => [],
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

        // Existing slugs and normalised names, so a forced run can add without colliding with
        // uq_location_country_slug and without duplicating a town already present.
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

        $citySizes = [];

        if (!$dryRun && $locations > 0) {
            $this->em->flush();
            $this->em->clear();

            // city_size is a property of the whole country's set, so it can only be computed
            // once every town for this country is in.
            $citySizes = $this->classificationService->reclassifyCountry($code);
        }

        $outcome = $locations === 0
            ? 'nothing to add — every registry town already present'
            : ($forced && $existingCount > 0 ? 'forced: missing towns added' : 'seeded');

        return [
            'country'   => $code,
            'locations' => $locations,
            'plots'     => $plots,
            'outcome'   => $outcome,
            'citySizes' => $citySizes,
        ];
    }

    /**
     * Import the bare town names — the ~4,200 that carry no population, region or capital flag.
     *
     * THE SECOND, LARGER REGISTRY. CountryContentRegistry holds two lists: 865 CURATED records
     * with a population and a region (what seedCountry() writes), and ~4,200 bare NAME STRINGS
     * with nothing else. England has 42 curated and 280 bare, so Prestwich, Telford and Doncaster
     * exist as names a player can already pick a club name from, but have never existed as
     * places a player could buy.
     *
     * They arrive with NO population, NO region and NO coordinates, status pending — and that is
     * the whole point. Enrichment supplies all of it, lazily, the first time somebody opens the
     * town. Two guards keep that backlog from distorting the world it joins:
     * LocationRepository::findForClassification() and findForGeneration() both ignore towns with
     * no population, so an unpriced town neither shifts a city-size percentile band nor becomes
     * a candidate for NPC club generation until it has been enriched.
     *
     * ADDS ONLY, MATCHED ON town_normalised. A name already present as a location is skipped, so
     * this is idempotent and safe to re-run after a curated seed.
     *
     * NEAR-DUPLICATES ARE REPORTED, NEVER MERGED. 18 bare names are a whole-word prefix of a
     * curated one — "Newcastle" against "Newcastle upon Tyne", "Frankfurt" against "Frankfurt am
     * Main". Some of those are the same city written two ways and ought to be merged by a human;
     * others are genuinely different places that merely read alike — "San Sebastián de los
     * Reyes" is a Madrid suburb and "San Sebastián" is in the Basque Country, and "San Miguel"
     * is not "San Miguel de Tucumán". Automatic merging would silently destroy real towns, so
     * both rows are created and the pair is reported for review.
     *
     * @return array{country: string, imported: int, skipped: int, duplicates: int,
     *     nearDuplicates: list<array{imported: string, existing: string}>}
     */
    public function importPlaceNames(Country $country, bool $dryRun = false): array
    {
        return $this->importNames($country, CountryContentRegistry::allPlaceNames($country), $dryRun);
    }

    /**
     * Add town names to a country's catalogue, from wherever they came from.
     *
     * Extracted from importPlaceNames() when a second source appeared — AI-suggested towns —
     * so the two cannot drift on the things that matter here: add-only, deduped on
     * town_normalised, near-duplicates reported rather than merged, and no fabricated
     * population, region or capital flag.
     *
     * @param list<string> $names
     *
     * @return array{country: string, imported: int, skipped: int, duplicates: int, nearDuplicates: list<array{imported: string, existing: string}>}
     */
    public function importNames(Country $country, array $names, bool $dryRun = false): array
    {
        $code = $country->value;

        $result = [
            'country'        => $code,
            'imported'       => 0,
            'skipped'        => 0,
            'duplicates'     => 0,
            'nearDuplicates' => [],
        ];

        if ($names === []) {
            return $result;
        }

        $profile = $this->countryProfileRepository->findByCode($code);

        if ($profile === null) {
            // Deliberate: importing bare names into a country that has never been seeded would
            // create a catalogue with no curated anchor at all — nothing priced, nothing
            // generatable, and a readiness gate that can never pass.
            throw new \InvalidArgumentException(sprintf(
                "'%s' has no country profile — run app:locations:seed %s first.",
                $code,
                $code,
            ));
        }

        $existing = $this->em->getConnection()->fetchAllAssociative(
            'SELECT slug, town, town_normalised FROM location WHERE country_code = :code',
            ['code' => $code],
        );

        $usedSlugs = [];
        $usedTowns = [];
        foreach ($existing as $row) {
            $usedSlugs[(string) $row['slug']]            = true;
            $usedTowns[(string) $row['town_normalised']] = (string) $row['town'];
        }

        // Snapshot of what was already in the database, so the two skip reasons stay
        // distinguishable: a name that was already a location, versus the registry listing the
        // same name twice. 124 of the ~4,200 are exact repeats once normalised, and an operator
        // reading "skipped 3,400" wants to know which kind.
        $preexisting = $usedTowns;

        foreach ($names as $raw) {
            $town       = trim((string) $raw);
            $normalised = ClubNameNormalizer::normalize($town);

            if ($town === '' || $normalised === '') {
                continue;
            }

            if (isset($usedTowns[$normalised])) {
                $result[isset($preexisting[$normalised]) ? 'skipped' : 'duplicates']++;
                continue;
            }

            foreach ($this->nearDuplicateOf($normalised, $usedTowns) as $match) {
                $result['nearDuplicates'][] = ['imported' => $town, 'existing' => $match];
            }

            $slug = $this->uniqueSlug($code, $town, $usedSlugs);

            $usedSlugs[$slug]       = true;
            $usedTowns[$normalised] = $town;

            $result['imported']++;

            if ($dryRun) {
                continue;
            }

            // No population, no region, no capital flag — there is none in this registry, and
            // writing a zero would be worse than writing nothing: it would look like data.
            $location = new Location($profile, $town, $slug, $normalised);

            $this->em->persist($location);
        }

        if (!$dryRun && $result['imported'] > 0) {
            $this->em->flush();
            $this->em->clear();
        }

        return $result;
    }

    /**
     * Existing towns this name is probably a variant of.
     *
     * Whole-word prefix in either direction, which is what separates "Newcastle" / "Newcastle
     * upon Tyne" from a coincidence. Reported, never acted on — see importPlaceNames().
     *
     * @param array<string, string> $usedTowns normalised => display name
     *
     * @return list<string>
     */
    private function nearDuplicateOf(string $normalised, array $usedTowns): array
    {
        $out = [];

        foreach ($usedTowns as $existingNorm => $existingTown) {
            if (str_starts_with($existingNorm, $normalised . ' ')
                || str_starts_with($normalised, $existingNorm . ' ')) {
                $out[] = $existingTown;
            }
        }

        return $out;
    }

    /**
     * `en-salford`, deduplicated within the country.
     *
     * The slug is display and log convenience only — nothing references it, and the primary key
     * is a UuidV7 precisely so a renamed or re-spelled town is not a data migration. It still
     * has to be unique per country, because uq_location_country_slug says so.
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
}
