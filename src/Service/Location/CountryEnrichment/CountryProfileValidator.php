<?php

declare(strict_types=1);

namespace App\Service\Location\CountryEnrichment;

use App\Entity\Location\CountryProfile;
use App\Enum\Location\DistanceUnit;
use App\Service\Location\Enrichment\EnrichmentValidator;
use Doctrine\DBAL\Connection;

/**
 * Decides, field by field, whether a provider's answer about a country is safe to store.
 *
 * THE STAKES ARE HIGHER HERE THAN FOR A TOWN, which is the reason this is strict where
 * EnrichmentValidator can afford to be forgiving. A bad town coordinate misplaces one town. A
 * bad BOUNDING BOX changes what every future town in that country is checked against — too
 * narrow and it silently refuses real coordinates for ever, too wide and it stops catching
 * the Birmingham-in-Alabama answer it exists for. The same goes for the land band: it is the
 * yardstick every town valuation is measured by.
 *
 * SO THE BOX IS CHECKED AGAINST REALITY, not just against itself. Towns that have already
 * been enriched carry coordinates somebody has reviewed, and a proposed box that excludes one
 * of them is provably wrong — no argument needed, no judgement call. That check is the single
 * most valuable thing in this file, and it gets better the more of a country is enriched.
 *
 * FILL GAPS ONLY, unless told otherwise. country_profile has no per-field lock columns the
 * way `location` does, so "a re-run never undoes a human correction" is expressed as: a field
 * that already holds a value is skipped. The overwrite flag is the operator saying otherwise,
 * explicitly, on that one run.
 */
final class CountryProfileValidator
{
    /**
     * Continents, exactly as the UN uses them.
     *
     * A closed list because `continent` is rendered and filtered on, and "Europe", "EU" and
     * "European Union" arriving in three different rows is the sort of thing nobody notices
     * until something groups by it.
     */
    public const CONTINENTS = [
        'Africa', 'Antarctica', 'Asia', 'Europe', 'North America', 'Oceania', 'South America',
    ];

    /**
     * Smallest a country's bounding box may be, in degrees.
     *
     * A box collapsed to near-nothing passes "min is less than max" and then rejects every
     * town in the country. Vatican City is about 0.01deg across, so this is well below any
     * real country while still catching a transposed or truncated answer.
     */
    public const MIN_BBOX_SPAN_DEGREES = 0.005;

    public function __construct(private readonly Connection $connection) {}

    /**
     * @param bool $overwrite write over values the profile already holds
     */
    public function validate(CountryProfile $country, CountryProfileFacts $facts, bool $overwrite = false): ValidatedCountryProfile
    {
        $accepted = [];
        $rejected = [];
        $skipped  = [];

        $this->validateBbox($country, $facts, $overwrite, $accepted, $rejected, $skipped);
        $this->validateLandBand($country, $facts, $overwrite, $accepted, $rejected, $skipped);
        $this->validateCodes($country, $facts, $overwrite, $accepted, $rejected, $skipped);
        $this->validateTimezone($country, $facts, $overwrite, $accepted, $rejected, $skipped);
        $this->validateContinent($country, $facts, $overwrite, $accepted, $rejected, $skipped);
        $this->validateDistanceUnit($country, $facts, $overwrite, $accepted, $rejected, $skipped);

        return new ValidatedCountryProfile($accepted, $rejected, $skipped);
    }

    /**
     * @param array<string, mixed>  $accepted
     * @param array<string, string> $rejected
     * @param list<string>          $skipped
     */
    private function validateBbox(
        CountryProfile $country,
        CountryProfileFacts $facts,
        bool $overwrite,
        array &$accepted,
        array &$rejected,
        array &$skipped,
    ): void {
        if ($facts->bbox === null) {
            return;
        }

        if (!$overwrite && EnrichmentValidator::isUsableBbox($country->getBbox())) {
            $skipped[] = 'bbox';

            return;
        }

        $bbox = $facts->bbox;

        if (!EnrichmentValidator::isUsableBbox($bbox)) {
            // The same predicate EnrichmentValidator uses to decide whether a STORED box is
            // worth consulting. A box this refuses would be ignored at town-enrichment time
            // anyway, so storing it would mean a country that looks configured and is not.
            $rejected['bbox'] = 'the box is incomplete or its edges are the wrong way round';

            return;
        }

        if (abs($bbox['maxLat'] - $bbox['minLat']) > 180.0 || abs($bbox['maxLng'] - $bbox['minLng']) > 360.0) {
            $rejected['bbox'] = 'the box is larger than the planet';

            return;
        }

        foreach ([$bbox['minLat'], $bbox['maxLat']] as $lat) {
            if (abs($lat) > 90.0) {
                $rejected['bbox'] = sprintf('latitude %.4f is outside -90..90', $lat);

                return;
            }
        }

        foreach ([$bbox['minLng'], $bbox['maxLng']] as $lng) {
            if (abs($lng) > 180.0) {
                $rejected['bbox'] = sprintf('longitude %.4f is outside -180..180', $lng);

                return;
            }
        }

        if (
            $bbox['maxLat'] - $bbox['minLat'] < self::MIN_BBOX_SPAN_DEGREES
            || $bbox['maxLng'] - $bbox['minLng'] < self::MIN_BBOX_SPAN_DEGREES
        ) {
            $rejected['bbox'] = 'the box has collapsed to a point and would reject every town';

            return;
        }

        $extent = $this->placedExtent($country->getCode());

        if ($extent !== null && !$this->contains($bbox, $extent)) {
            // PROVABLY WRONG, so it is refused rather than flagged. Towns already enriched
            // have coordinates a human has had the chance to review, and a box that excludes
            // one of them would start rejecting answers that are known to be right. Naming
            // the observed extent is what lets the operator widen the box by hand on the
            // country's own form rather than guessing at what went wrong.
            $rejected['bbox'] = sprintf(
                'it excludes towns already placed in this country, which span %.4f..%.4f lat and %.4f..%.4f lng',
                $extent['minLat'],
                $extent['maxLat'],
                $extent['minLng'],
                $extent['maxLng'],
            );

            return;
        }

        $accepted['bbox'] = $bbox;
    }

    /**
     * @param array<string, mixed>  $accepted
     * @param array<string, string> $rejected
     * @param list<string>          $skipped
     */
    private function validateLandBand(
        CountryProfile $country,
        CountryProfileFacts $facts,
        bool $overwrite,
        array &$accepted,
        array &$rejected,
        array &$skipped,
    ): void {
        $min = $facts->landPenceMin;
        $max = $facts->landPenceMax;

        if ($min === null && $max === null) {
            return;
        }

        // BOTH OR NEITHER. A band with one end is not a band: EnrichmentValidator reads the
        // pair, and half of one would silently fall back to the global backstop on the other
        // side while looking configured.
        if ($min === null || $max === null) {
            $rejected['landBand'] = 'only one end of the band was supplied';

            return;
        }

        $hasBand = $country->getLandPenceMin() !== null && $country->getLandPenceMax() !== null;

        if (!$overwrite && $hasBand) {
            $skipped[] = 'landBand';

            return;
        }

        if ($min <= 0 || $max <= 0) {
            $rejected['landBand'] = 'a plot cannot be worth nothing';

            return;
        }

        if ($min >= $max) {
            $rejected['landBand'] = sprintf('the cheapest plot (%d) is not below the dearest (%d)', $min, $max);

            return;
        }

        // The global backstop is exactly the right outer bound for a per-country band: a band
        // wider than it adds nothing, and one outside it would accept valuations the town
        // validator would refuse anyway.
        if ($min < EnrichmentValidator::LAND_PENCE_MIN || $max > EnrichmentValidator::LAND_PENCE_MAX) {
            $rejected['landBand'] = sprintf(
                'the band falls outside the plausible range of £%s to £%s',
                number_format(EnrichmentValidator::LAND_PENCE_MIN / 100),
                number_format(EnrichmentValidator::LAND_PENCE_MAX / 100),
            );

            return;
        }

        $accepted['landPenceMin'] = $min;
        $accepted['landPenceMax'] = $max;
    }

    /**
     * @param array<string, mixed>  $accepted
     * @param array<string, string> $rejected
     * @param list<string>          $skipped
     */
    private function validateCodes(
        CountryProfile $country,
        CountryProfileFacts $facts,
        bool $overwrite,
        array &$accepted,
        array &$rejected,
        array &$skipped,
    ): void {
        $codes = [
            'isoAlpha3'             => [$facts->isoAlpha3, $country->getIsoAlpha3(), 3, 'an ISO 3166-1 alpha-3 code is three letters'],
            'currencyCode'          => [$facts->currencyCode, $country->getCurrencyCode(), 3, 'an ISO 4217 code is three letters'],
            'landPriceCurrencyCode' => [$facts->landPriceCurrencyCode, $country->getLandPriceCurrencyCode(), 3, 'an ISO 4217 code is three letters'],
        ];

        foreach ($codes as $field => [$value, $stored, $length, $complaint]) {
            if ($value === null) {
                continue;
            }

            if (!$overwrite && $stored !== null && $stored !== '') {
                $skipped[] = $field;

                continue;
            }

            $upper = strtoupper(trim($value));

            if (!preg_match('/^[A-Z]{' . $length . '}$/', $upper)) {
                $rejected[$field] = sprintf("'%s' is not valid — %s", $value, $complaint);

                continue;
            }

            $accepted[$field] = $upper;
        }
    }

    /**
     * @param array<string, mixed>  $accepted
     * @param array<string, string> $rejected
     * @param list<string>          $skipped
     */
    private function validateTimezone(
        CountryProfile $country,
        CountryProfileFacts $facts,
        bool $overwrite,
        array &$accepted,
        array &$rejected,
        array &$skipped,
    ): void {
        if ($facts->defaultTimezone === null) {
            return;
        }

        if (!$overwrite && ($country->getDefaultTimezone() ?? '') !== '') {
            $skipped[] = 'defaultTimezone';

            return;
        }

        // Checked against PHP's own zone list rather than a pattern. A plausible-looking
        // 'Europe/Wales' would pass any regex and then throw the first time anything built a
        // DateTimeZone from it, far from here.
        if (!\in_array($facts->defaultTimezone, \DateTimeZone::listIdentifiers(), true)) {
            $rejected['defaultTimezone'] = sprintf("'%s' is not an IANA time zone", $facts->defaultTimezone);

            return;
        }

        $accepted['defaultTimezone'] = $facts->defaultTimezone;
    }

    /**
     * @param array<string, mixed>  $accepted
     * @param array<string, string> $rejected
     * @param list<string>          $skipped
     */
    private function validateContinent(
        CountryProfile $country,
        CountryProfileFacts $facts,
        bool $overwrite,
        array &$accepted,
        array &$rejected,
        array &$skipped,
    ): void {
        if ($facts->continent === null) {
            return;
        }

        if (!$overwrite && ($country->getContinent() ?? '') !== '') {
            $skipped[] = 'continent';

            return;
        }

        foreach (self::CONTINENTS as $continent) {
            if (strcasecmp($continent, trim($facts->continent)) === 0) {
                $accepted['continent'] = $continent;

                return;
            }
        }

        $rejected['continent'] = sprintf(
            "'%s' is not one of %s",
            $facts->continent,
            implode(', ', self::CONTINENTS),
        );
    }

    /**
     * @param array<string, mixed>  $accepted
     * @param array<string, string> $rejected
     * @param list<string>          $skipped
     */
    private function validateDistanceUnit(
        CountryProfile $country,
        CountryProfileFacts $facts,
        bool $overwrite,
        array &$accepted,
        array &$rejected,
        array &$skipped,
    ): void {
        if ($facts->distanceUnit === null) {
            return;
        }

        // distance_unit is NOT NULL with a default of KM, so "already set" cannot be read off
        // the column — every country has one. Only an explicit overwrite changes it, which
        // means the default stands unless an operator asks for it to be reconsidered.
        if (!$overwrite) {
            $skipped[] = 'distanceUnit';

            return;
        }

        $unit = DistanceUnit::tryFrom(strtolower(trim($facts->distanceUnit)));

        if ($unit === null) {
            $rejected['distanceUnit'] = sprintf("'%s' is neither km nor mi", $facts->distanceUnit);

            return;
        }

        $accepted['distanceUnit'] = $unit;
    }

    /**
     * The lat/lng extent of towns already placed in this country.
     *
     * Only ACTIVE locations with coordinates: a retired town's position is not evidence about
     * where the country is, and an unenriched one has none.
     *
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}|null
     */
    private function placedExtent(string $code): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT MIN(lat) AS min_lat, MAX(lat) AS max_lat, MIN(lng) AS min_lng, MAX(lng) AS max_lng
               FROM location
              WHERE country_code = :code AND status = 'active' AND lat IS NOT NULL AND lng IS NOT NULL",
            ['code' => $code],
        );

        if ($row === false || $row['min_lat'] === null) {
            return null;
        }

        return [
            'minLat' => (float) $row['min_lat'],
            'maxLat' => (float) $row['max_lat'],
            'minLng' => (float) $row['min_lng'],
            'maxLng' => (float) $row['max_lng'],
        ];
    }

    /**
     * @param array{minLat: float, maxLat: float, minLng: float, maxLng: float} $bbox
     * @param array{minLat: float, maxLat: float, minLng: float, maxLng: float} $extent
     */
    private function contains(array $bbox, array $extent): bool
    {
        return $bbox['minLat'] <= $extent['minLat']
            && $bbox['maxLat'] >= $extent['maxLat']
            && $bbox['minLng'] <= $extent['minLng']
            && $bbox['maxLng'] >= $extent['maxLng'];
    }
}
