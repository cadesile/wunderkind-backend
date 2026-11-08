<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Repository\Location\LocationRepository;

/**
 * The read side of the location table for world generation.
 *
 * Returns exactly the array shape NpcClubGenerationService has always consumed —
 * `['name', 'population_size', 'region', 'is_capital', 'city_size']` — so repointing the
 * generator at the database changed its call sites and nothing else. That shape compatibility
 * is deliberate: it is what let the existing generation tests go on passing untouched, which is
 * the only convincing evidence that moving 865 records out of PHP changed no behaviour.
 *
 * PROCESS-SCOPED MEMO, NOT A CACHE POOL. One query per country per process. That matters
 * because the callers are loops: `app:country:bootstrap` generates eight tiers for a country in
 * one process, and `generateClubName()` runs once per spoof entrant. A const lookup cost
 * nothing, so the memo keeps the new cost in the same order of magnitude.
 *
 * Deliberately NOT a Symfony cache pool, and not CountryWorldPackCacheService either. This data
 * is admin-mutable: a stale entry would generate clubs in towns an operator has just retired,
 * or price them from a valuation that has since been corrected. A cross-request cache would
 * need invalidation hooks on every admin write for a saving that only ever helps a single CLI
 * process, which is a bad trade. If this ever becomes a hot path on a web request, the honest
 * fix is to cache the generated world pack (which already has a cache), not this lookup.
 *
 * `getArrayResult()` on an explicit column projection, never hydrated entities: ORM 3 removed
 * PARTIAL hydration, so hydrating would decode all ~30 columns of every row, including a jsonb,
 * to read four of them.
 */
final class LocationCatalogueService
{
    /**
     * Fallback when a country has no location rows at all.
     *
     * Kept byte-identical to the array that was previously inlined twice in
     * NpcClubGenerationService, and extracted to one place rather than changed. It is the only
     * thing between a country with no seeded locations and a crash, and
     * `app:country:bootstrap --force` documents the resulting "Capital FC" club names in its
     * own warning text. Changing that behaviour is a separate decision from moving the data.
     *
     * @var list<array{name: string, population_size: int, region: string, is_capital?: bool}>
     */
    public const PLACEHOLDER_PLACES = [
        ['name' => 'Capital', 'population_size' => 500000, 'region' => 'Central', 'is_capital' => true],
        ['name' => 'Northern', 'population_size' => 100000, 'region' => 'North'],
        ['name' => 'Southern', 'population_size' => 100000, 'region' => 'South'],
        ['name' => 'Eastern', 'population_size' => 100000, 'region' => 'East'],
        ['name' => 'Western', 'population_size' => 100000, 'region' => 'West'],
        ['name' => 'Central', 'population_size' => 100000, 'region' => 'Central'],
    ];

    /** @var array<string, list<array{name: string, population_size: int, region: string, is_capital: bool, city_size: string}>> */
    private array $byCountry = [];

    public function __construct(
        private readonly LocationRepository $locationRepository,
    ) {}

    /**
     * Active locations for one country, in the generator's own shape.
     *
     * Returns `[]` for a country with nothing seeded — callers keep their own fallback
     * behaviour, exactly as they did when CountryContentRegistry::places() returned null.
     *
     * @return list<array{name: string, population_size: int, region: string, is_capital: bool, city_size: string}>
     */
    public function placesForGeneration(string $countryCode): array
    {
        $code = strtoupper(trim($countryCode));

        return $this->byCountry[$code] ??= $this->locationRepository->findForGeneration($code);
    }

    /**
     * The generator's place list, or the placeholder set when a country has none.
     *
     * The one place that decides between real data and the fallback, so the two call sites that
     * used to inline that `?? [...]` cannot drift apart again.
     *
     * @return list<array{name: string, population_size: int, region: string, is_capital?: bool, city_size?: string, land_pence?: ?int}>
     */
    public function placesForGenerationOrPlaceholder(string $countryCode): array
    {
        $places = $this->placesForGeneration($countryCode);

        return $places !== [] ? $places : self::PLACEHOLDER_PLACES;
    }

    /** @return list<string> */
    public function placeNames(string $countryCode): array
    {
        return array_map(
            static fn (array $place): string => $place['name'],
            $this->placesForGeneration($countryCode),
        );
    }

    public function hasPlaces(string $countryCode): bool
    {
        return $this->placesForGeneration($countryCode) !== [];
    }

    public function countPlaces(string $countryCode): int
    {
        return count($this->placesForGeneration($countryCode));
    }

    /**
     * Drop the memo.
     *
     * For the one caller that genuinely needs it: a long-lived process that has just written
     * locations and must then read them back — `app:locations:seed` reclassifying a country it
     * has only just created.
     */
    public function forget(?string $countryCode = null): void
    {
        if ($countryCode === null) {
            $this->byCountry = [];

            return;
        }

        unset($this->byCountry[strtoupper(trim($countryCode))]);
    }
}
