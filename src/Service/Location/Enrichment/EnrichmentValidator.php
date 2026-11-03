<?php

declare(strict_types=1);

namespace App\Service\Location\Enrichment;

use App\Entity\Location\Location;

/**
 * Decides, field by field, whether a provider's answer is believable enough to store.
 *
 * THIS IS WHAT MAKES "BELIEVABLE REALISM" A SAFE TARGET RATHER THAN A HOPE. The accuracy bar
 * for this feature is deliberately low — a town's coordinates only need to put it in roughly
 * the right place, because all they do is rank rivals by distance. But "roughly right" and
 * "wrong by a thousand miles" are not the same thing, and a language model will occasionally
 * produce the latter with complete confidence: a town placed in the sea, a population of
 * eighty million, a plot priced at four billion pounds. Those are not rounding errors, they are
 * the failure mode.
 *
 * Four checks, each refusing one field rather than the whole response:
 *
 *   1. COORDINATES INSIDE THE COUNTRY. The single most valuable check, because it catches the
 *      two most common failures at once — the 0,0 null-island answer, and a same-named town in
 *      the wrong country (there is a Birmingham in Alabama). Needs CountryProfile::$bbox; with
 *      no box, it falls back to a globe-range check, which still catches 0,0 only if 0,0 is
 *      outside... it is not, so a country with no bbox genuinely gets weaker protection. That
 *      is why 0,0 is ALSO refused explicitly.
 *   2. POPULATION PLAUSIBLE, and — where the seed already supplied one — not wildly different
 *      from it. A large divergence does not overwrite the seeded figure; it flags the row.
 *   3. LAND VALUE positive and inside the country's band, so one hallucinated valuation cannot
 *      price a plot beyond every player's reach.
 *   4. REGION non-empty and not absurdly long, since it is only ever flavour text.
 *
 * ADMIN LOCKS WIN, ALWAYS. A locked field is skipped before it is even examined, so no re-run
 * can undo a human correction. That is reported as `skipped`, not as a rejection.
 *
 * FILL GAPS ONLY. Population and region are seeded from CountryContentRegistry and are real
 * curated data; enrichment does not overwrite them. It supplies what is missing — coordinates
 * and land value — and uses its population answer only as a cross-check against the seed.
 */
final class EnrichmentValidator
{
    /** Smallest and largest believable town population. */
    public const POPULATION_MIN = 1;
    public const POPULATION_MAX = 50_000_000;

    /**
     * How far an AI population may diverge from a seeded one before the row is flagged.
     *
     * 0.5 means "within half to one-and-a-half times". Generous on purpose: census years and
     * city-vs-metropolitan-area definitions genuinely disagree by this much, so a tighter band
     * would flag most of the set and teach everyone to ignore the flag.
     */
    public const POPULATION_DIVERGENCE_RATIO = 0.5;

    /**
     * Absolute floor and ceiling on a plot valuation, pence, when a country has no band.
     *
     * REAL PROPERTY VALUES, matching the rest of the economy. The app tells players land is
     * "priced from real local values", and StadiumEstimateService prices the ground on top of it
     * at real construction rates (~£1,500/seat), so a plot has to be a real figure too or the
     * land component becomes rounding noise beside a £135M stadium.
     *
     * The floor was £100 in the first draft, which was a game-scale number: it would have waved
     * through an AI answer pricing a stadium-sized site at £150. £25,000 is deliberately
     * generous — a cheap plot in a cheap country — and is only a backstop for countries with no
     * CountryProfile band of their own, which is where the real per-country checking belongs.
     */
    public const LAND_PENCE_MIN = 25_000_00;
    public const LAND_PENCE_MAX = 1_000_000_000_00;

    public const REGION_MAX_LENGTH = 120;

    public function validate(Location $location, EnrichmentResult $result): ValidatedEnrichment
    {
        $accepted = [];
        $rejected = [];
        $skipped  = [];

        $this->validateCoordinates($location, $result, $accepted, $rejected, $skipped);
        $this->validatePopulation($location, $result, $accepted, $rejected, $skipped);
        $this->validateLandPence($location, $result, $accepted, $rejected, $skipped);
        $this->validateRegion($location, $result, $accepted, $rejected);

        return new ValidatedEnrichment($accepted, $rejected, $skipped);
    }

    /**
     * @param array<string, int|float|string> $accepted
     * @param array<string, string>           $rejected
     * @param list<string>                    $skipped
     */
    private function validateCoordinates(
        Location $location,
        EnrichmentResult $result,
        array &$accepted,
        array &$rejected,
        array &$skipped,
    ): void {
        if ($location->isCoordinatesLocked()) {
            $skipped[] = 'coordinates';

            return;
        }

        if (!$result->hasCoordinates()) {
            if ($result->lat !== null || $result->lng !== null) {
                // One without the other is useless — a half coordinate is not a location.
                $rejected['coordinates'] = 'only one of lat/lng was supplied';
            }

            return;
        }

        $lat = $result->lat;
        $lng = $result->lng;

        if (abs($lat) > 90.0 || abs($lng) > 180.0) {
            $rejected['coordinates'] = sprintf('%.4f,%.4f is outside the valid lat/lng range', $lat, $lng);

            return;
        }

        // Refused explicitly, not merely as a bbox miss: 0,0 is inside the valid range and
        // inside no country's box, and it is the single most common null answer.
        if (abs($lat) < 0.0001 && abs($lng) < 0.0001) {
            $rejected['coordinates'] = '0,0 is the null-island answer, not a real location';

            return;
        }

        $bbox = $location->getCountry()->getBbox();

        if ($this->isUsableBbox($bbox)) {
            $inside = $lat >= $bbox['minLat'] && $lat <= $bbox['maxLat']
                && $lng >= $bbox['minLng'] && $lng <= $bbox['maxLng'];

            if (!$inside) {
                $rejected['coordinates'] = sprintf(
                    '%.4f,%.4f falls outside %s (%.2f..%.2f, %.2f..%.2f)',
                    $lat,
                    $lng,
                    $location->getCountryCode(),
                    $bbox['minLat'],
                    $bbox['maxLat'],
                    $bbox['minLng'],
                    $bbox['maxLng'],
                );

                return;
            }
        }

        $accepted['lat'] = $lat;
        $accepted['lng'] = $lng;
    }

    /**
     * @param array<string, int|float|string> $accepted
     * @param array<string, string>           $rejected
     * @param list<string>                    $skipped
     */
    private function validatePopulation(
        Location $location,
        EnrichmentResult $result,
        array &$accepted,
        array &$rejected,
        array &$skipped,
    ): void {
        if ($result->population === null) {
            return;
        }

        if ($location->isPopulationLocked()) {
            $skipped[] = 'population';

            return;
        }

        $population = $result->population;

        if ($population < self::POPULATION_MIN || $population > self::POPULATION_MAX) {
            $rejected['population'] = sprintf(
                '%d is outside the believable range %d..%d',
                $population,
                self::POPULATION_MIN,
                self::POPULATION_MAX,
            );

            return;
        }

        $seeded = $location->getPopulation();

        if ($seeded === null) {
            $accepted['population'] = $population;

            return;
        }

        // FILL GAPS ONLY: a seeded population is curated data and stands. The AI figure is used
        // purely as a cross-check, and a big divergence flags the row for a human rather than
        // replacing good data with a guess.
        $skipped[] = 'population';

        if ($seeded > 0) {
            $ratio = abs($population - $seeded) / $seeded;

            if ($ratio > self::POPULATION_DIVERGENCE_RATIO) {
                $rejected['population_divergence'] = sprintf(
                    'provider said %d but the seeded figure is %d (%.0f%% apart) — seeded value kept',
                    $population,
                    $seeded,
                    $ratio * 100,
                );
            }
        }
    }

    /**
     * @param array<string, int|float|string> $accepted
     * @param array<string, string>           $rejected
     * @param list<string>                    $skipped
     */
    private function validateLandPence(
        Location $location,
        EnrichmentResult $result,
        array &$accepted,
        array &$rejected,
        array &$skipped,
    ): void {
        if ($result->landPence === null) {
            return;
        }

        if ($location->isLandLocked()) {
            $skipped[] = 'landPence';

            return;
        }

        $landPence = $result->landPence;
        $country    = $location->getCountry();
        $min        = $country->getLandPenceMin() ?? self::LAND_PENCE_MIN;
        $max        = $country->getLandPenceMax() ?? self::LAND_PENCE_MAX;

        if ($landPence < $min || $landPence > $max) {
            $rejected['landPence'] = sprintf(
                '%d pence is outside %s\'s band %d..%d',
                $landPence,
                $location->getCountryCode(),
                $min,
                $max,
            );

            return;
        }

        $accepted['landPence'] = $landPence;
    }

    /**
     * @param array<string, int|float|string> $accepted
     * @param array<string, string>           $rejected
     */
    private function validateRegion(
        Location $location,
        EnrichmentResult $result,
        array &$accepted,
        array &$rejected,
    ): void {
        if ($result->region === null) {
            return;
        }

        // Region is seeded for every curated town and is flavour text on the generated club;
        // only fill it when it is genuinely absent.
        if (trim($location->getRegion()) !== '') {
            return;
        }

        $region = trim($result->region);

        if ($region === '') {
            return;
        }

        if (mb_strlen($region) > self::REGION_MAX_LENGTH) {
            $rejected['region'] = sprintf(
                'region is %d characters, over the %d limit',
                mb_strlen($region),
                self::REGION_MAX_LENGTH,
            );

            return;
        }

        $accepted['region'] = $region;
    }

    /** @param array<string, mixed>|null $bbox */
    private function isUsableBbox(?array $bbox): bool
    {
        if ($bbox === null) {
            return false;
        }

        foreach (['minLat', 'maxLat', 'minLng', 'maxLng'] as $key) {
            if (!isset($bbox[$key]) || !is_numeric($bbox[$key])) {
                return false;
            }
        }

        return $bbox['minLat'] < $bbox['maxLat'] && $bbox['minLng'] < $bbox['maxLng'];
    }
}
