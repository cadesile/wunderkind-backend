<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\Location;
use App\Service\Location\Haversine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Distances have to be right, and the PHP and SQL implementations have to agree.
 *
 * Rival ranking happens in SQL (ORDER BY over Haversine::SQL_DISTANCE_KM) while anything
 * rendering or re-checking a distance uses Haversine::distanceKm(). If those two drifted, a
 * rival list and the distance shown beside it would disagree — invisible in a unit test of
 * either one alone, and obvious to a player comparing two screens. So this test runs the SQL
 * fragment against the real database and compares it to the PHP result.
 *
 * There is no PostGIS (postgres:16-alpine in both compose files), which is why there is a
 * hand-rolled formula to test at all.
 */
class HaversineTest extends KernelTestCase
{
    /** Real-world pairs, to catch a formula that is self-consistent but wrong. */
    public const KNOWN_PAIRS = [
        // name                    lat1,      lng1,      lat2,      lng2,      km
        'London - Manchester' => [51.5074, -0.1278, 53.4808, -2.2426, 261.98],
        'London - Paris'      => [51.5074, -0.1278, 48.8566, 2.3522, 343.56],
        'New York - LA'       => [40.7128, -74.0060, 34.0522, -118.2437, 3935.75],
    ];

    public function testKnownDistances(): void
    {
        foreach (self::KNOWN_PAIRS as $label => [$lat1, $lng1, $lat2, $lng2, $expectedKm]) {
            self::assertEqualsWithDelta(
                $expectedKm,
                Haversine::distanceKm($lat1, $lng1, $lat2, $lng2),
                1.0,
                "{$label} should be about {$expectedKm} km",
            );
        }
    }

    /**
     * Two identical points must be 0, not NAN.
     *
     * The dot product for a point against itself can land a hair above 1.0 in floating point,
     * and acos(1.0000000002) is NAN. The min/max clamp in distanceKm() is what stops a town
     * comparing against itself from poisoning a whole rival ranking with NAN.
     */
    public function testIdenticalPointsAreZeroNotNan(): void
    {
        $km = Haversine::distanceKm(51.5074, -0.1278, 51.5074, -0.1278);

        self::assertFalse(is_nan($km), 'Identical points produced NAN — the acos clamp is missing.');
        self::assertEqualsWithDelta(0.0, $km, 0.0001);
    }

    /** Antipodal points are the other end of the same clamp. */
    public function testAntipodalPointsAreHalfTheCircumference(): void
    {
        $km = Haversine::distanceKm(0.0, 0.0, 0.0, 180.0);

        self::assertFalse(is_nan($km));
        self::assertEqualsWithDelta(M_PI * Haversine::EARTH_RADIUS_KM, $km, 1.0);
    }

    public function testDistanceIsSymmetric(): void
    {
        [$lat1, $lng1, $lat2, $lng2] = self::KNOWN_PAIRS['London - Manchester'];

        self::assertEqualsWithDelta(
            Haversine::distanceKm($lat1, $lng1, $lat2, $lng2),
            Haversine::distanceKm($lat2, $lng2, $lat1, $lng1),
            0.0001,
        );
    }

    public function testMileConversionRoundTrips(): void
    {
        self::assertEqualsWithDelta(100.0, Haversine::milesToKm(Haversine::kmToMiles(100.0)), 0.0001);
        // 1 mile is 1.609344 km by definition, so this is a fixed check, not an approximation.
        self::assertEqualsWithDelta(1.609344, Haversine::milesToKm(1.0), 0.000001);
    }

    /**
     * The bounding box must CONTAIN the circle it approximates, never clip it.
     *
     * It is only ever a prefilter, so being too large is free and being too small silently
     * drops a genuine nearest rival.
     */
    public function testBoundingBoxContainsEveryPointInRadius(): void
    {
        $lat    = 53.4808;
        $lng    = -2.2426;
        $radius = 40.0;

        $box = Haversine::boundingBox($lat, $lng, $radius);

        // Due north, south, east and west at exactly the radius must all fall inside.
        self::assertGreaterThanOrEqual($lat + ($radius / 111.32), $box['maxLat'] + 1e-9);
        self::assertLessThanOrEqual($lat - ($radius / 111.32), $box['minLat'] - 1e-9);

        $lngDelta = $radius / (111.32 * cos(deg2rad($lat)));
        self::assertGreaterThanOrEqual($lng + $lngDelta, $box['maxLng'] + 1e-9);
        self::assertLessThanOrEqual($lng - $lngDelta, $box['minLng'] - 1e-9);
    }

    /** Near a pole, cos(lat) approaches 0 — the box must widen, not divide by zero. */
    public function testBoundingBoxNearPoleStaysFinite(): void
    {
        $box = Haversine::boundingBox(89.9, 0.0, 50.0);

        foreach ($box as $key => $value) {
            self::assertTrue(is_finite($value), "{$key} is not finite near the pole");
        }

        self::assertLessThanOrEqual(90.0, $box['maxLat']);
        self::assertGreaterThanOrEqual(-180.0, $box['minLng']);
        self::assertLessThanOrEqual(180.0, $box['maxLng']);
    }

    /**
     * The SQL fragment and the PHP function must return the same number.
     *
     * This is the test that actually protects the rival feature. It runs the real fragment,
     * against the real Postgres, over real rows — so a change to either implementation that
     * is not mirrored in the other fails here.
     */
    public function testSqlFragmentAgreesWithPhp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $country = new CountryProfile('ZZ', 'Haversine Test Country');
        $em->persist($country);

        $origin = null;
        $rows   = [];

        foreach (self::KNOWN_PAIRS as $label => [$lat1, $lng1, $lat2, $lng2, $expectedKm]) {
            $slug = 'hv-' . substr(md5($label), 0, 12);

            $location = new Location($country, $label, $slug, strtolower($label));
            $location->setCoordinates($lat2, $lng2);
            $em->persist($location);

            $rows[(string) $location->getId()] = [$lat1, $lng1, $lat2, $lng2];

            $origin ??= [$lat1, $lng1];
        }

        $em->flush();

        try {
            // One query per origin, since the fragment is written against a single :lat/:lng.
            foreach ($rows as $id => [$lat1, $lng1, $lat2, $lng2]) {
                $sqlKm = (float) $em->getConnection()->fetchOne(
                    'SELECT ' . Haversine::SQL_DISTANCE_KM . ' FROM location l WHERE l.id = :id',
                    ['lat' => $lat1, 'lng' => $lng1, 'id' => $id],
                );

                self::assertEqualsWithDelta(
                    Haversine::distanceKm($lat1, $lng1, $lat2, $lng2),
                    $sqlKm,
                    0.01,
                    'SQL_DISTANCE_KM and distanceKm() disagree — they must be changed together.',
                );
            }

            // And the clamp, in SQL: a row compared against its own coordinates.
            $firstId = array_key_first($rows);
            [, , $selfLat, $selfLng] = $rows[$firstId];

            $selfKm = $em->getConnection()->fetchOne(
                'SELECT ' . Haversine::SQL_DISTANCE_KM . ' FROM location l WHERE l.id = :id',
                ['lat' => $selfLat, 'lng' => $selfLng, 'id' => $firstId],
            );

            self::assertNotNull($selfKm, 'acos() domain error — the least/greatest clamp is missing.');
            self::assertEqualsWithDelta(0.0, (float) $selfKm, 0.01);
        } finally {
            foreach (array_keys($rows) as $id) {
                $em->getConnection()->executeStatement('DELETE FROM location WHERE id = :id', ['id' => $id]);
            }
            $em->getConnection()->executeStatement("DELETE FROM country_profile WHERE code = 'ZZ'");
        }
    }
}
