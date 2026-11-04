<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Enum\CitySize;
use App\Enum\Location\CountryProfileStatus;
use App\Enum\Location\DistanceUnit;
use App\Enum\Location\EnrichmentStatus;
use App\Enum\Location\LandPlotStatus;
use App\Enum\Location\LocationStatus;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\Location\LandPlotRepository;
use App\Repository\Location\LocationRepository;
use App\Service\ClubNameNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moving enriched geography between environments without paying for it twice.
 *
 * THE POINT OF THIS CLASS IS THE MONEY. Enrichment is one billed external API call per town,
 * and 865 towns is a real spend plus a real review pass over 865 AI-sourced land valuations.
 * Doing that again on dev, and again on prod, and again after a database rebuild, is the kind
 * of waste that is invisible until the invoice arrives. So: enrich once, review once, export,
 * and import everywhere else.
 *
 * MATCHED ON (countryCode, slug), NEVER ON ID. UUIDs differ between environments, and an
 * export file that assumed otherwise would only ever import cleanly into the database it came
 * from. (countryCode, slug) is already uniquely indexed, so the match is exact and cheap.
 * Existing rows keep their own ids, which is what keeps claims pointing at the right towns.
 *
 * ADMIN LOCKS ARE RESPECTED, and this is the subtle part. is*Locked means a human corrected
 * that field, and an import must no more overwrite a correction than a re-enrichment may —
 * otherwise "refresh the geography" silently throws away the review work this whole export
 * exists to preserve. A locked field is skipped and counted.
 *
 * WHAT IS DELIBERATELY NOT EXPORTED:
 *   - plot_claim, plot_hold, user_ledger. Real money and real player data. Moving a claim
 *     between environments would be moving somebody's purchase.
 *   - location_rival and stadium_estimate. Derived caches, both rebuildable
 *     (app:locations:rivals, app:plots:refresh-estimates) and both keyed by uuid, so carrying
 *     them would mean an id-mapping pass for data that costs nothing to recompute.
 *   - enrichment_claimed_at. A lease, i.e. runtime state. Importing one would make a town look
 *     claimed-for-work by a worker that does not exist in the target environment.
 *   - location_enrichment_run / _attempt. Forensic history, specific to where it happened.
 *
 * THERE IS NO clearAll(), unlike LeagueImportExportService. location is referenced by
 * plot_claim with ON DELETE RESTRICT precisely because a location can carry real money — so a
 * truncate-then-import is either blocked by the database or, worse, deletes the claims first.
 * Import is an upsert, always.
 */
final class LocationImportExportService
{
    private const EXPORT_VERSION = 1;

    public function __construct(
        private readonly CountryProfileRepository $countryRepository,
        private readonly LocationRepository       $locationRepository,
        private readonly LandPlotRepository       $plotRepository,
        private readonly EntityManagerInterface   $em,
    ) {}

    // ── Export ────────────────────────────────────────────────────────────────

    /**
     * @param list<string>|null $countryCodes null exports every country
     *
     * @return array<string, mixed>
     */
    public function export(?array $countryCodes = null): array
    {
        $codes = $countryCodes === null
            ? null
            : array_map(static fn (string $c): string => strtoupper(trim($c)), $countryCodes);

        $countries = [];
        $locations = [];

        foreach ($this->countryRepository->findAllOrderedByName() as $country) {
            if ($codes !== null && !in_array($country->getCode(), $codes, true)) {
                continue;
            }

            $countries[] = $this->exportCountry($country);

            $rows  = $this->locationRepository->findByCountry($country->getCode());
            $plots = $this->plotRepository->findActivePlotsWithClaimsByLocationIds(
                array_map(static fn (Location $l): string => (string) $l->getId(), $rows),
            );

            foreach ($rows as $location) {
                $locations[] = $this->exportLocation($location, $plots[(string) $location->getId()] ?? []);
            }
        }

        return [
            'version'    => self::EXPORT_VERSION,
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'countries'  => $countries,
            'locations'  => $locations,
        ];
    }

    /** @return array<string, mixed> */
    private function exportCountry(CountryProfile $c): array
    {
        return [
            'code'                  => $c->getCode(),
            'name'                  => $c->getName(),
            'isoAlpha3'             => $c->getIsoAlpha3(),
            'continent'             => $c->getContinent(),
            'defaultTimezone'       => $c->getDefaultTimezone(),
            'currencyCode'          => $c->getCurrencyCode(),
            'landPriceCurrencyCode' => $c->getLandPriceCurrencyCode(),
            'defaultLocale'         => $c->getDefaultLocale(),
            'distanceUnit'          => $c->getDistanceUnit()->value,
            'status'                => $c->getStatus()->value,
            // Carried because EnrichmentValidator checks every AI coordinate against it. An
            // import that brought towns but not the box they are validated against would make
            // the target environment unable to re-enrich a correction.
            'bbox'                  => $c->getBbox(),
            'landPenceMin'          => $c->getLandPenceMin(),
            'landPenceMax'          => $c->getLandPenceMax(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $plotRows
     *
     * @return array<string, mixed>
     */
    private function exportLocation(Location $l, array $plotRows): array
    {
        return [
            // The natural key. Not the uuid — see the class docblock.
            'countryCode'      => $l->getCountryCode(),
            'slug'             => $l->getSlug(),
            'town'             => $l->getTown(),
            'localisedNames'   => $l->getLocalisedNames(),
            'region'           => $l->getRegion(),
            'lat'              => $l->getLat(),
            'lng'              => $l->getLng(),
            'population'       => $l->getPopulation(),
            'populationSource' => $l->getPopulationSource(),
            'populationAsOf'   => $l->getPopulationAsOf()?->format('Y-m-d'),
            'landPence'        => $l->getLandPence(),
            'landValuedAt'     => $l->getLandValuedAt()?->format(\DateTimeInterface::ATOM),
            'landSource'       => $l->getLandSource(),
            'isCapital'        => $l->isCapital(),
            'citySize'         => $l->getCitySize()->value,
            'status'           => $l->getStatus()->value,
            // The locks travel WITH the data. A reviewed, corrected town stays protected in the
            // target environment — otherwise the next sweep there would undo the review that
            // this export exists to carry.
            'isCoordinatesLocked' => $l->isCoordinatesLocked(),
            'isPopulationLocked'  => $l->isPopulationLocked(),
            'isLandLocked'        => $l->isLandLocked(),
            // The whole reason to export at all: "this row is already done, do not pay again".
            'enrichmentStatus'   => $l->getEnrichmentStatus()->value,
            'enrichedAt'         => $l->getEnrichedAt()?->format(\DateTimeInterface::ATOM),
            'enrichmentProvider' => $l->getEnrichmentProvider(),
            'enrichmentModel'    => $l->getEnrichmentModel(),
            'plots'              => array_map(
                static fn (array $p): array => [
                    'plotNumber'        => $p['plotNumber'],
                    'landPenceOverride' => $p['landPenceOverride'],
                ],
                $plotRows,
            ),
        ];
    }

    // ── Import ────────────────────────────────────────────────────────────────

    /**
     * Upsert countries and locations from an export file.
     *
     * APPLIES WHAT IT UNDERSTANDS AND REPORTS THE REST, rather than aborting on the first bad
     * row — the ConfigImportExportService convention. One malformed town in an 865-row
     * hand-edited file should not discard the other 864.
     *
     * @param array<string, mixed> $data
     *
     * @return array{applied: bool, countriesCreated: int, countriesUpdated: int,
     *     locationsCreated: int, locationsUpdated: int, plotsCreated: int,
     *     lockedFieldsSkipped: int, errors: list<string>}
     */
    public function import(array $data, bool $dryRun = false): array
    {
        $result = [
            'applied'             => false,
            'countriesCreated'    => 0,
            'countriesUpdated'    => 0,
            'locationsCreated'    => 0,
            'locationsUpdated'    => 0,
            'plotsCreated'        => 0,
            'lockedFieldsSkipped' => 0,
            'errors'              => [],
        ];

        if (($data['version'] ?? null) !== self::EXPORT_VERSION) {
            $result['errors'][] = sprintf(
                'Unsupported export version %s — this build reads version %d.',
                var_export($data['version'] ?? null, true),
                self::EXPORT_VERSION,
            );

            return $result;
        }

        // COUNTRIES FIRST, ALWAYS. location.country_code is a FK to country_profile with ON
        // DELETE RESTRICT, so a location whose country has not been created yet cannot be
        // inserted at all.
        foreach ($this->asList($data['countries'] ?? []) as $i => $row) {
            try {
                $this->importCountry($row, $result);
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('countries[%d]: %s', $i, $e->getMessage());
            }
        }

        // Flushed before the locations so they can resolve their country by reference.
        $this->em->flush();

        foreach ($this->asList($data['locations'] ?? []) as $i => $row) {
            try {
                $this->importLocation($row, $result);
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('locations[%d]: %s', $i, $e->getMessage());
            }
        }

        if ($dryRun) {
            // Nothing is written: the unit of work is discarded rather than flushed, so a
            // --dry-run reports exactly the counts a real run would without touching a row.
            $this->em->clear();

            return $result;
        }

        $this->em->flush();
        $result['applied'] = true;

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $result
     */
    private function importCountry(array $row, array &$result): void
    {
        $code = strtoupper(trim((string) ($row['code'] ?? '')));

        if ($code === '') {
            throw new \InvalidArgumentException('missing code');
        }

        $country = $this->countryRepository->findByCode($code);

        if ($country === null) {
            $country = new CountryProfile($code, (string) ($row['name'] ?? $code));
            $this->em->persist($country);
            $result['countriesCreated']++;
        } else {
            $result['countriesUpdated']++;
        }

        if (isset($row['name'])) {
            $country->setName((string) $row['name']);
        }

        $country->setIsoAlpha3($this->nullableString($row['isoAlpha3'] ?? null));
        $country->setContinent($this->nullableString($row['continent'] ?? null));
        $country->setDefaultTimezone($this->nullableString($row['defaultTimezone'] ?? null));
        $country->setCurrencyCode($this->nullableString($row['currencyCode'] ?? null));
        $country->setLandPriceCurrencyCode($this->nullableString($row['landPriceCurrencyCode'] ?? null));

        if (isset($row['defaultLocale'])) {
            $country->setDefaultLocale((string) $row['defaultLocale']);
        }

        if (isset($row['distanceUnit']) && ($unit = DistanceUnit::tryFrom((string) $row['distanceUnit'])) !== null) {
            $country->setDistanceUnit($unit);
        }

        // STATUS IS IMPORTED, which means an import can take a country live. That is intended —
        // the readiness gate is enforced when an ADMIN edits a profile, and an import is a
        // deliberate act by an admin who already has the file.
        if (isset($row['status']) && ($status = CountryProfileStatus::tryFrom((string) $row['status'])) !== null) {
            $country->setStatus($status);
        }

        if (array_key_exists('bbox', $row)) {
            $country->setBbox(is_array($row['bbox']) ? $row['bbox'] : null);
        }

        $country->setLandPenceMin($this->nullableInt($row['landPenceMin'] ?? null));
        $country->setLandPenceMax($this->nullableInt($row['landPenceMax'] ?? null));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $result
     */
    private function importLocation(array $row, array &$result): void
    {
        $code = strtoupper(trim((string) ($row['countryCode'] ?? '')));
        $slug = trim((string) ($row['slug'] ?? ''));

        if ($code === '' || $slug === '') {
            throw new \InvalidArgumentException('missing countryCode or slug');
        }

        $country = $this->countryRepository->findByCode($code);

        if ($country === null) {
            throw new \InvalidArgumentException(sprintf('no country profile "%s" — include it in the file', $code));
        }

        $location = $this->locationRepository->findBySlug($code, $slug);
        $town     = (string) ($row['town'] ?? $slug);

        if ($location === null) {
            $location = new Location($country, $town, $slug, ClubNameNormalizer::normalize($town));
            $this->em->persist($location);
            $result['locationsCreated']++;
        } else {
            $result['locationsUpdated']++;
        }

        $location->setTown($town);
        $location->setTownNormalised(ClubNameNormalizer::normalize($town));

        if (array_key_exists('localisedNames', $row)) {
            $location->setLocalisedNames(is_array($row['localisedNames']) ? $row['localisedNames'] : null);
        }

        if (isset($row['region'])) {
            $location->setRegion((string) $row['region']);
        }

        $location->setIsCapital((bool) ($row['isCapital'] ?? false));

        if (isset($row['citySize']) && ($size = CitySize::tryFrom((string) $row['citySize'])) !== null) {
            $location->setCitySize($size);
        }

        if (isset($row['status']) && ($status = LocationStatus::tryFrom((string) $row['status'])) !== null) {
            $location->setStatus($status);
        }

        // ── The three lockable groups ────────────────────────────────────────
        //
        // Each is skipped when the TARGET row is locked, because that lock records a human
        // decision in this environment and an import is not entitled to overrule it. Note the
        // check is against the existing row, not the incoming one.

        if ($location->isCoordinatesLocked()) {
            $result['lockedFieldsSkipped']++;
        } else {
            $location->setCoordinates(
                $this->nullableFloat($row['lat'] ?? null),
                $this->nullableFloat($row['lng'] ?? null),
            );
        }

        if ($location->isPopulationLocked()) {
            $result['lockedFieldsSkipped']++;
        } else {
            $location->setPopulation($this->nullableInt($row['population'] ?? null));

            if (isset($row['populationSource'])) {
                $location->setPopulationSource((string) $row['populationSource']);
            }

            $location->setPopulationAsOf($this->nullableDate($row['populationAsOf'] ?? null));
        }

        if ($location->isLandLocked()) {
            $result['lockedFieldsSkipped']++;
        } else {
            $location->setLandPence($this->nullableInt($row['landPence'] ?? null));
            $location->setLandValuedAt($this->nullableDate($row['landValuedAt'] ?? null));

            if (isset($row['landSource'])) {
                $location->setLandSource((string) $row['landSource']);
            }
        }

        // The incoming locks are applied AFTER the field writes above, so a file carrying a
        // locked field both writes its reviewed value into a fresh row and protects it there.
        $location->setIsCoordinatesLocked((bool) ($row['isCoordinatesLocked'] ?? false) || $location->isCoordinatesLocked());
        $location->setIsPopulationLocked((bool) ($row['isPopulationLocked'] ?? false) || $location->isPopulationLocked());
        $location->setIsLandLocked((bool) ($row['isLandLocked'] ?? false) || $location->isLandLocked());

        // ── Enrichment state: the reason this export is worth having ─────────

        if (isset($row['enrichmentStatus']) && ($es = EnrichmentStatus::tryFrom((string) $row['enrichmentStatus'])) !== null) {
            $location->setEnrichmentStatus($es);
        }

        $location->setEnrichedAt($this->nullableDate($row['enrichedAt'] ?? null));
        $location->setEnrichmentProvider($this->nullableString($row['enrichmentProvider'] ?? null));
        $location->setEnrichmentModel($this->nullableString($row['enrichmentModel'] ?? null));

        // Never imported: a lease belongs to the environment whose worker took it.
        $location->setEnrichmentClaimedAt(null);

        // Rivals are derived from coordinates that may have just changed, so the target
        // environment must recompute them. Nulling this is what makes
        // `app:locations:rivals --stale-only` pick the row up.
        $location->setRivalsComputedAt(null);

        $this->importPlots($location, $this->asList($row['plots'] ?? []), $result);
    }

    /**
     * @param list<mixed>          $plotRows
     * @param array<string, mixed> $result
     */
    private function importPlots(Location $location, array $plotRows, array &$result): void
    {
        // A location with no plots in the file still gets one: a town with no plot cannot be
        // bought at all, and seeding has always created exactly one.
        if ($plotRows === []) {
            $plotRows = [['plotNumber' => 1, 'landPenceOverride' => null]];
        }

        $existing = [];
        foreach ($this->plotRepository->findActiveByLocation($location) as $plot) {
            $existing[$plot->getPlotNumber()] = $plot;
        }

        foreach ($plotRows as $plotRow) {
            if (!is_array($plotRow)) {
                continue;
            }

            $number = (int) ($plotRow['plotNumber'] ?? 1);
            $plot   = $existing[$number] ?? null;

            if ($plot === null) {
                $plot = new LandPlot($location, $number);
                $this->em->persist($plot);
                $result['plotsCreated']++;
            }

            $plot->setLandPenceOverride($this->nullableInt($plotRow['landPenceOverride'] ?? null));
            $plot->setStatus(LandPlotStatus::ACTIVE);
        }

        // Plots present in the target but absent from the file are LEFT ALONE, never deleted.
        // One of them may carry an active claim, and land_plot is referenced by plot_claim with
        // ON DELETE RESTRICT — so deleting is either refused by the database or would mean
        // removing somebody's purchase first.
    }

    // ── Coercion ──────────────────────────────────────────────────────────────

    /** @return list<mixed> */
    private function asList(mixed $v): array
    {
        return is_array($v) ? array_values($v) : [];
    }

    private function nullableString(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }

        $t = trim($v);

        return $t === '' ? null : $t;
    }

    private function nullableInt(mixed $v): ?int
    {
        return is_int($v) || (is_string($v) && $v !== '' && ctype_digit(ltrim($v, '-'))) ? (int) $v : null;
    }

    private function nullableFloat(mixed $v): ?float
    {
        return is_int($v) || is_float($v) ? (float) $v : null;
    }

    private function nullableDate(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($v);
        } catch (\Throwable) {
            // A bad date is dropped rather than failing the whole row — the figure it stamps is
            // far more valuable than its provenance timestamp.
            return null;
        }
    }
}
