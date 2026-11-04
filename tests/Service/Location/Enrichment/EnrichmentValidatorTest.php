<?php

declare(strict_types=1);

namespace App\Tests\Service\Location\Enrichment;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\Location;
use App\Enum\Location\EnrichmentOutcome;
use App\Service\Location\Enrichment\EnrichmentResult;
use App\Service\Location\Enrichment\EnrichmentValidator;
use PHPUnit\Framework\TestCase;

/**
 * The validator is the only thing standing between a confident language model and the database.
 *
 * Accuracy for this feature is deliberately "believable realism" — coordinates only rank rivals
 * by distance, so a few hundred metres do not matter. What does matter is that the failure mode
 * of an LLM is not a small error but an occasional enormous one, stated with total confidence:
 * a town in the sea, a population of eighty million, a plot priced at four billion. Every case
 * below is one of those.
 *
 * Pure TestCase — the validator takes an entity and a result object and returns a verdict.
 */
class EnrichmentValidatorTest extends TestCase
{
    private EnrichmentValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new EnrichmentValidator();
    }

    /** England's real bounding box, give or take. */
    private function country(bool $withBbox = true, ?int $landMin = null, ?int $landMax = null): CountryProfile
    {
        $country = new CountryProfile('EN', 'England');

        if ($withBbox) {
            $country->setBbox(['minLat' => 49.9, 'maxLat' => 55.8, 'minLng' => -6.4, 'maxLng' => 1.8]);
        }

        $country->setLandPenceMin($landMin);
        $country->setLandPenceMax($landMax);

        return $country;
    }

    private function location(?CountryProfile $country = null, ?int $population = null, string $region = 'West Midlands'): Location
    {
        $location = new Location($country ?? $this->country(), 'Birmingham', 'en-birmingham', 'birmingham');
        $location->setRegion($region);

        if ($population !== null) {
            $location->setPopulation($population);
        }

        return $location;
    }

    // ───────────────────────────── coordinates ─────────────────────────────

    public function testAcceptsCoordinatesInsideTheCountry(): void
    {
        $verdict = $this->validator->validate(
            $this->location(),
            new EnrichmentResult(lat: 52.4862, lng: -1.8904),
        );

        self::assertSame([], $verdict->rejected);
        self::assertTrue($verdict->hasCoordinates());
        self::assertSame(52.4862, $verdict->accepted['lat']);
        self::assertSame(EnrichmentOutcome::ACCEPTED, $verdict->outcome());
    }

    /**
     * The null-island answer, refused by name.
     *
     * 0,0 is a valid lat/lng and is inside no country's box, so it has to be caught explicitly
     * — and it must be, because it is the most common way a model says "I don't know" without
     * saying so. Left unchecked, every un-enriched town would sit on the same spot in the
     * Atlantic and the rival pass would make them all each other's nearest neighbour.
     */
    public function testRejectsNullIsland(): void
    {
        $verdict = $this->validator->validate(
            $this->location(),
            new EnrichmentResult(lat: 0.0, lng: 0.0),
        );

        self::assertArrayHasKey('coordinates', $verdict->rejected);
        self::assertStringContainsString('null-island', $verdict->rejected['coordinates']);
        self::assertFalse($verdict->hasCoordinates());
    }

    /** And still refused when a country has no bbox to check against. */
    public function testRejectsNullIslandEvenWithoutABbox(): void
    {
        $verdict = $this->validator->validate(
            $this->location($this->country(withBbox: false)),
            new EnrichmentResult(lat: 0.0, lng: 0.0),
        );

        self::assertArrayHasKey('coordinates', $verdict->rejected);
    }

    /**
     * The wrong Birmingham.
     *
     * There is a Birmingham in Alabama, and asking a model for "Birmingham" without pinning the
     * country is exactly how you get it. The bbox check is what catches a plausible-looking
     * coordinate for a real place that is not this place.
     */
    public function testRejectsCoordinatesInTheWrongCountry(): void
    {
        $verdict = $this->validator->validate(
            $this->location(),
            new EnrichmentResult(lat: 33.5186, lng: -86.8104),
        );

        self::assertArrayHasKey('coordinates', $verdict->rejected);
        self::assertStringContainsString('outside EN', $verdict->rejected['coordinates']);
        self::assertSame(EnrichmentOutcome::REJECTED, $verdict->outcome());
    }

    public function testRejectsImpossibleCoordinates(): void
    {
        foreach ([[91.0, 0.5], [45.0, 181.0], [-90.5, 0.0]] as [$lat, $lng]) {
            $verdict = $this->validator->validate(
                $this->location(),
                new EnrichmentResult(lat: $lat, lng: $lng),
            );

            self::assertArrayHasKey('coordinates', $verdict->rejected, "{$lat},{$lng} should be refused");
        }
    }

    /** Half a coordinate is not a location. */
    public function testRejectsHalfACoordinate(): void
    {
        $verdict = $this->validator->validate($this->location(), new EnrichmentResult(lat: 52.4862));

        self::assertArrayHasKey('coordinates', $verdict->rejected);
        self::assertStringContainsString('only one of lat/lng', $verdict->rejected['coordinates']);
    }

    /** No bbox means weaker checks, not no checks — a valid in-range coordinate passes. */
    public function testAcceptsAnyValidCoordinateWhenCountryHasNoBbox(): void
    {
        $verdict = $this->validator->validate(
            $this->location($this->country(withBbox: false)),
            new EnrichmentResult(lat: 33.5186, lng: -86.8104),
        );

        self::assertSame([], $verdict->rejected);
        self::assertTrue($verdict->hasCoordinates());
    }

    // ───────────────────────────── population ─────────────────────────────

    /** Fill-gaps-only: a seeded population is curated data and is never replaced. */
    /**
     * A plausible AI population now REPLACES the seeded one.
     *
     * This test asserted the opposite until the fill-gaps-only rule was dropped. Population is
     * the single input city_size derives from, and city_size drives NPC club generation — so
     * leaving the seeded figure authoritative meant enrichment could never reach the clubs
     * standing in that town.
     */
    public function testAPlausiblePopulationReplacesTheSeededOne(): void
    {
        $verdict = $this->validator->validate(
            $this->location(population: 1_149_000),
            new EnrichmentResult(population: 1_200_000),
        );

        self::assertSame(1_200_000, $verdict->accepted['population']);
        self::assertNotContains('population', $verdict->skipped);
    }

    /** An admin lock still outranks the model, exactly as it does for every other field. */
    public function testALockedPopulationIsStillNeverOverwritten(): void
    {
        $location = $this->location(population: 1_149_000);
        $location->setIsPopulationLocked(true);

        $verdict = $this->validator->validate($location, new EnrichmentResult(population: 1_200_000));

        self::assertArrayNotHasKey('population', $verdict->accepted);
        self::assertContains('population', $verdict->skipped);
    }

    /**
     * A wildly divergent population is REFUSED, and the seeded figure stands.
     *
     * The guard that survived dropping fill-gaps-only, and the reason it had to. The
     * characteristic failure is the model resolving a same-named town in another country and
     * returning its population; that is normally an order of magnitude out, and accepting it
     * would silently re-band the whole country, because city_size is a percentile split across
     * the entire set rather than a threshold on one row.
     */
    public function testAWildlyDivergentPopulationIsRefusedAndTheSeedStands(): void
    {
        $verdict = $this->validator->validate(
            $this->location(population: 1_149_000),
            // Fourteen times smaller — a different town, not a different census year.
            new EnrichmentResult(population: 80_000),
        );

        self::assertArrayNotHasKey('population', $verdict->accepted);
        self::assertArrayHasKey('population_divergence', $verdict->rejected);
        self::assertStringContainsString('seeded value kept', $verdict->rejected['population_divergence']);
    }

    /** A divergence inside the band is not worth anyone's attention. */
    public function testSmallPopulationDivergenceIsNotFlagged(): void
    {
        $verdict = $this->validator->validate(
            $this->location(population: 1_000_000),
            new EnrichmentResult(population: 1_200_000),
        );

        self::assertSame([], $verdict->rejected);
    }

    /** With no seeded figure, a plausible population is genuinely useful and is written. */
    public function testAcceptsPopulationWhenNoneWasSeeded(): void
    {
        $verdict = $this->validator->validate(
            $this->location(population: null),
            new EnrichmentResult(population: 270_000),
        );

        self::assertSame(270_000, $verdict->accepted['population']);
    }

    public function testRejectsImplausiblePopulation(): void
    {
        foreach ([0, -5, 900_000_000] as $population) {
            $verdict = $this->validator->validate(
                $this->location(population: null),
                new EnrichmentResult(population: $population),
            );

            self::assertArrayHasKey('population', $verdict->rejected, "{$population} should be refused");
        }
    }

    // ───────────────────────────── land value ─────────────────────────────

    public function testAcceptsLandValueInsideTheCountryBand(): void
    {
        $verdict = $this->validator->validate(
            $this->location($this->country(landMin: 1_000_00, landMax: 100_000_00)),
            new EnrichmentResult(landPence: 45_000_00),
        );

        self::assertSame(45_000_00, $verdict->accepted['landPence']);
    }

    /** One hallucinated valuation must not price a plot beyond every player's reach. */
    public function testRejectsLandValueOutsideTheCountryBand(): void
    {
        $verdict = $this->validator->validate(
            $this->location($this->country(landMin: 1_000_00, landMax: 100_000_00)),
            new EnrichmentResult(landPence: 4_000_000_000_00),
        );

        self::assertArrayHasKey('landPence', $verdict->rejected);
        self::assertStringContainsString("EN's band", $verdict->rejected['landPence']);
    }

    /**
     * The fallback land band is real property money, not game tokens.
     *
     * Pinned for the same reason StadiumEstimateService::COST_PER_SEAT_PENCE is: these look like
     * implausibly large numbers to a reader who does not know the economy is deliberately
     * real-world, and are exactly what someone would shrink. A plot has to be a real figure or
     * the land component is rounding noise beside a £135M ground.
     */
    public function testTheFallbackLandBandIsRealPropertyMoney(): void
    {
        self::assertGreaterThanOrEqual(
            10_000_00,
            EnrichmentValidator::LAND_PENCE_MIN,
            'a stadium-sized plot below £10,000 is not a real valuation — see the constant docblock',
        );
        self::assertGreaterThanOrEqual(
            100_000_000_00,
            EnrichmentValidator::LAND_PENCE_MAX,
            'the ceiling must leave room for prime city land',
        );
    }

    /** A plot priced like a game token is refused when the country has no band of its own. */
    public function testRejectsAGameScaleLandValue(): void
    {
        $verdict = $this->validator->validate(
            $this->location(),
            new EnrichmentResult(landPence: 150_00),
        );

        self::assertArrayHasKey('landPence', $verdict->rejected);
    }

    public function testRejectsNonPositiveLandValueWithNoCountryBand(): void
    {
        $verdict = $this->validator->validate($this->location(), new EnrichmentResult(landPence: 0));

        self::assertArrayHasKey('landPence', $verdict->rejected);
    }

    // ───────────────────────────── locks ─────────────────────────────

    /**
     * An admin's correction can never be undone by a re-run.
     *
     * Locked fields are skipped before they are even examined, and skipping is not a rejection
     * — the stored value already stands, so the response is not "partly bad".
     */
    public function testLockedFieldsAreSkippedNotRejected(): void
    {
        $location = $this->location(population: null);
        $location->setIsCoordinatesLocked(true);
        $location->setIsPopulationLocked(true);
        $location->setIsLandLocked(true);

        $verdict = $this->validator->validate(
            $location,
            // Every value here is nonsense, and none of it should matter.
            new EnrichmentResult(lat: 0.0, lng: 0.0, population: 999_999_999, landPence: -1),
        );

        self::assertSame([], $verdict->accepted);
        self::assertSame([], $verdict->rejected, 'a locked field must not be reported as rejected');
        self::assertSame(['coordinates', 'population', 'landPence'], $verdict->skipped);
        self::assertSame(EnrichmentOutcome::ACCEPTED, $verdict->outcome());
    }

    // ───────────────────────────── region ─────────────────────────────

    public function testSeededRegionIsNotOverwritten(): void
    {
        $verdict = $this->validator->validate(
            $this->location(region: 'West Midlands'),
            new EnrichmentResult(region: 'Something Else'),
        );

        self::assertArrayNotHasKey('region', $verdict->accepted);
    }

    public function testFillsAnEmptyRegion(): void
    {
        $verdict = $this->validator->validate(
            $this->location(region: ''),
            new EnrichmentResult(region: 'West Midlands'),
        );

        self::assertSame('West Midlands', $verdict->accepted['region']);
    }

    public function testRejectsAnAbsurdlyLongRegion(): void
    {
        $verdict = $this->validator->validate(
            $this->location(region: ''),
            new EnrichmentResult(region: str_repeat('a', 500)),
        );

        self::assertArrayHasKey('region', $verdict->rejected);
    }

    // ───────────────────────────── outcomes ─────────────────────────────

    /**
     * The headline case: good fields written, bad fields named, row flagged for review.
     *
     * Throwing the usable coordinates away because the valuation was absurd would make
     * enrichment far less useful than it is.
     */
    public function testPartialResponseKeepsTheGoodHalfAndNamesTheBad(): void
    {
        $verdict = $this->validator->validate(
            $this->location($this->country(landMin: 1_000_00, landMax: 100_000_00), population: null),
            new EnrichmentResult(lat: 52.4862, lng: -1.8904, population: 1_100_000, landPence: 9_999_999_999),
        );

        self::assertTrue($verdict->hasCoordinates());
        self::assertSame(1_100_000, $verdict->accepted['population']);
        self::assertArrayHasKey('landPence', $verdict->rejected);
        self::assertSame(EnrichmentOutcome::PARTIAL, $verdict->outcome());
    }

    public function testAnEmptyResponseIsNeitherAcceptedNorRejected(): void
    {
        $verdict = $this->validator->validate($this->location(), new EnrichmentResult());

        self::assertSame([], $verdict->accepted);
        self::assertSame([], $verdict->rejected);
        self::assertSame(EnrichmentOutcome::REJECTED, $verdict->outcome());
    }

    public function testAllBadResponseIsRejected(): void
    {
        $verdict = $this->validator->validate(
            $this->location(population: null),
            new EnrichmentResult(lat: 0.0, lng: 0.0, population: -1, landPence: -1),
        );

        self::assertSame([], $verdict->accepted);
        self::assertCount(3, $verdict->rejected);
        self::assertSame(EnrichmentOutcome::REJECTED, $verdict->outcome());
    }
}
