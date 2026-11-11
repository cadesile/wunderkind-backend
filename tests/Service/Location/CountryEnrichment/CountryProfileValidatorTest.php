<?php

declare(strict_types=1);

namespace App\Tests\Service\Location\CountryEnrichment;

use App\Entity\Location\CountryProfile;
use App\Enum\Location\DistanceUnit;
use App\Service\Location\CountryEnrichment\CountryProfileFacts;
use App\Service\Location\CountryEnrichment\CountryProfileValidator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * What a country's answer has to clear before it is stored.
 *
 * These are worth more than the town validator's equivalents, because the two fields at the
 * centre of them are not facts about one row — they are the yardstick every town in the
 * country is measured against afterwards. A bounding box that is too narrow silently refuses
 * real coordinates for ever; a land band in the wrong place makes every valuation look
 * implausible. Neither failure announces itself.
 */
class CountryProfileValidatorTest extends TestCase
{
    public function testAGoodAnswerIsAccepted(): void
    {
        $verdict = $this->validate($this->country(), $this->facts());

        self::assertSame([], $verdict->rejected);
        self::assertSame(
            ['minLat' => 49.9, 'maxLat' => 60.9, 'minLng' => -8.6, 'maxLng' => 1.8],
            $verdict->accepted['bbox'],
        );
        self::assertSame('GBR', $verdict->accepted['isoAlpha3']);
        self::assertSame('Europe', $verdict->accepted['continent']);
        self::assertSame('Europe/London', $verdict->accepted['defaultTimezone']);
        self::assertSame('GBP', $verdict->accepted['currencyCode']);
    }

    public function testABoxWithItsEdgesTheWrongWayRoundIsRefused(): void
    {
        $verdict = $this->validate($this->country(), $this->facts(
            bbox: ['minLat' => 60.9, 'maxLat' => 49.9, 'minLng' => -8.6, 'maxLng' => 1.8],
        ));

        self::assertArrayHasKey('bbox', $verdict->rejected);
        self::assertArrayNotHasKey('bbox', $verdict->accepted);
        // The other fields in the same answer are untouched: validation is per field.
        self::assertArrayHasKey('isoAlpha3', $verdict->accepted);
    }

    public function testABoxCollapsedToAPointIsRefused(): void
    {
        // Passes "min is less than max" and would then reject every town in the country.
        $verdict = $this->validate($this->country(), $this->facts(
            bbox: ['minLat' => 51.5, 'maxLat' => 51.5001, 'minLng' => -0.1, 'maxLng' => -0.0999],
        ));

        self::assertStringContainsString('collapsed', $verdict->rejected['bbox'] ?? '');
    }

    public function testABoxOutsideTheGlobeIsRefused(): void
    {
        $verdict = $this->validate($this->country(), $this->facts(
            bbox: ['minLat' => 49.9, 'maxLat' => 95.0, 'minLng' => -8.6, 'maxLng' => 1.8],
        ));

        self::assertStringContainsString('-90..90', $verdict->rejected['bbox'] ?? '');
    }

    /**
     * The check that makes this validator worth having.
     *
     * Towns already enriched carry coordinates a human has had the chance to review. A box
     * that excludes one of them is provably wrong — and storing it would start refusing
     * answers that are known to be right, for ever, with no symptom but a growing
     * needs-review queue.
     */
    public function testABoxThatExcludesTownsAlreadyPlacedIsRefused(): void
    {
        $verdict = $this->validate(
            $this->country(),
            $this->facts(bbox: ['minLat' => 49.9, 'maxLat' => 55.0, 'minLng' => -8.6, 'maxLng' => 1.8]),
            // A town already sits at 57.1 north — a Scottish one, outside the proposed box.
            placed: ['min_lat' => 50.1, 'max_lat' => 57.1, 'min_lng' => -5.0, 'max_lng' => 1.0],
        );

        self::assertArrayHasKey('bbox', $verdict->rejected);
        self::assertStringContainsString('already placed', $verdict->rejected['bbox']);
        // The observed extent is named, so the operator can widen the box by hand.
        self::assertStringContainsString('57.1', $verdict->rejected['bbox']);
    }

    public function testABoxThatContainsEveryPlacedTownIsAccepted(): void
    {
        $verdict = $this->validate(
            $this->country(),
            $this->facts(),
            placed: ['min_lat' => 50.1, 'max_lat' => 57.1, 'min_lng' => -5.0, 'max_lng' => 1.0],
        );

        self::assertArrayNotHasKey('bbox', $verdict->rejected);
    }

    public function testHalfALandBandIsRefused(): void
    {
        $verdict = $this->validate($this->country(), $this->facts(landPenceMin: 5_000_000, landPenceMax: null));

        self::assertStringContainsString('one end', $verdict->rejected['landBand'] ?? '');
    }

    public function testAnInvertedLandBandIsRefused(): void
    {
        $verdict = $this->validate($this->country(), $this->facts(landPenceMin: 900_000_000, landPenceMax: 5_000_000));

        self::assertArrayHasKey('landBand', $verdict->rejected);
    }

    public function testALandBandOutsideThePlausibleRangeIsRefused(): void
    {
        // £10 a plot: inside its own band, nowhere near a real site.
        $verdict = $this->validate($this->country(), $this->facts(landPenceMin: 1_000, landPenceMax: 5_000_000));

        self::assertStringContainsString('plausible range', $verdict->rejected['landBand'] ?? '');
    }

    public function testAnInventedTimeZoneIsRefused(): void
    {
        // Passes any pattern anyone would write, and throws the first time a DateTimeZone is
        // built from it — a long way from here.
        $verdict = $this->validate($this->country(), $this->facts(defaultTimezone: 'Europe/Wales'));

        self::assertStringContainsString('not an IANA time zone', $verdict->rejected['defaultTimezone'] ?? '');
    }

    public function testAnOffListContinentIsRefused(): void
    {
        $verdict = $this->validate($this->country(), $this->facts(continent: 'European Union'));

        self::assertArrayHasKey('continent', $verdict->rejected);
    }

    public function testAContinentIsNormalisedToTheCanonicalSpelling(): void
    {
        $verdict = $this->validate($this->country(), $this->facts(continent: 'europe'));

        self::assertSame('Europe', $verdict->accepted['continent']);
    }

    public function testBadlyShapedCodesAreRefused(): void
    {
        $verdict = $this->validate($this->country(), $this->facts(isoAlpha3: 'GB', currencyCode: 'Pound'));

        self::assertArrayHasKey('isoAlpha3', $verdict->rejected);
        self::assertArrayHasKey('currencyCode', $verdict->rejected);
    }

    public function testCodesAreUppercased(): void
    {
        $verdict = $this->validate($this->country(), $this->facts(isoAlpha3: 'gbr', currencyCode: 'gbp'));

        self::assertSame('GBR', $verdict->accepted['isoAlpha3']);
        self::assertSame('GBP', $verdict->accepted['currencyCode']);
    }

    /**
     * Fill gaps only, which is this feature's stand-in for the per-field locks `location` has.
     *
     * country_profile carries no lock columns, so the rule "a re-run never undoes a human
     * correction" has to be expressed as: a field that already holds a value is left alone.
     */
    public function testAFieldTheCountryAlreadyHasIsSkippedRatherThanRefused(): void
    {
        $country = $this->country();
        $country->setIsoAlpha3('XXX');
        $country->setBbox(['minLat' => 1.0, 'maxLat' => 2.0, 'minLng' => 1.0, 'maxLng' => 2.0]);

        $verdict = $this->validate($country, $this->facts());

        self::assertContains('isoAlpha3', $verdict->skipped);
        self::assertContains('bbox', $verdict->skipped);
        self::assertArrayNotHasKey('isoAlpha3', $verdict->accepted);
        self::assertArrayNotHasKey('isoAlpha3', $verdict->rejected, 'skipped must not read as refused');
    }

    public function testOverwriteReplacesAFieldTheCountryAlreadyHas(): void
    {
        $country = $this->country();
        $country->setIsoAlpha3('XXX');

        $verdict = $this->validate($country, $this->facts(), overwrite: true);

        self::assertSame('GBR', $verdict->accepted['isoAlpha3']);
        self::assertSame([], $verdict->skipped);
    }

    /**
     * distance_unit is NOT NULL with a default, so "already set" cannot be read off the column.
     *
     * Every country has one from the moment it is seeded, which means a fill-gaps run would
     * either always skip it or always overwrite it. Always skipping is the one that keeps the
     * promise the rest of the validator makes.
     */
    public function testDistanceUnitIsOnlyTouchedOnAnExplicitOverwrite(): void
    {
        $fillGaps = $this->validate($this->country(), $this->facts(distanceUnit: 'mi'));
        self::assertContains('distanceUnit', $fillGaps->skipped);

        $forced = $this->validate($this->country(), $this->facts(distanceUnit: 'mi'), overwrite: true);
        self::assertSame(DistanceUnit::MI, $forced->accepted['distanceUnit']);
    }

    public function testADeclinedFieldIsNeitherAcceptedNorRefused(): void
    {
        // A null is the model saying it does not know, which is a better answer than a guess
        // and must not be recorded as a failure.
        $verdict = $this->validate($this->country(), new CountryProfileFacts());

        self::assertSame([], $verdict->accepted);
        self::assertSame([], $verdict->rejected);
        self::assertSame([], $verdict->skipped);
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    private function country(): CountryProfile
    {
        return new CountryProfile('ZV', 'Validatia');
    }

    /** @param array{minLat: float, maxLat: float, minLng: float, maxLng: float}|null $bbox */
    private function facts(
        ?array $bbox = ['minLat' => 49.9, 'maxLat' => 60.9, 'minLng' => -8.6, 'maxLng' => 1.8],
        ?int $landPenceMin = 5_000_000,
        ?int $landPenceMax = 900_000_000,
        ?string $isoAlpha3 = 'GBR',
        ?string $continent = 'Europe',
        ?string $defaultTimezone = 'Europe/London',
        ?string $currencyCode = 'GBP',
        ?string $distanceUnit = null,
    ): CountryProfileFacts {
        return new CountryProfileFacts(
            bbox: $bbox,
            landPenceMin: $landPenceMin,
            landPenceMax: $landPenceMax,
            isoAlpha3: $isoAlpha3,
            continent: $continent,
            defaultTimezone: $defaultTimezone,
            currencyCode: $currencyCode,
            distanceUnit: $distanceUnit,
        );
    }

    /** @param array<string, float>|false $placed */
    private function validate(
        CountryProfile $country,
        CountryProfileFacts $facts,
        bool $overwrite = false,
        array|false $placed = false,
    ) {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn(
            $placed === false
                ? ['min_lat' => null, 'max_lat' => null, 'min_lng' => null, 'max_lng' => null]
                : $placed,
        );

        return (new CountryProfileValidator($connection))->validate($country, $facts, $overwrite);
    }
}
