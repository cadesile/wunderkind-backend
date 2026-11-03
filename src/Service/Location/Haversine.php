<?php

declare(strict_types=1);

namespace App\Service\Location;

/**
 * Great-circle distance, in PHP and in SQL, defined once.
 *
 * There is no PostGIS: both compose.yaml and docker-compose.prod.yml run
 * postgres:16-alpine, so there is no geography type and no GiST index to reach for. Rival
 * ranking is therefore a bounding-box prefilter against idx_location_lat_lng followed by this
 * expression in ORDER BY — fine at the ~865 rows the seed creates, and still fine at ~5,000.
 * Growing the table well past that is the point at which PostGIS becomes a real prod-image
 * change, not a code change.
 *
 * THE PHP FUNCTION AND THE SQL FRAGMENT MUST AGREE, which is why they live in the same file.
 * A rival list computed in SQL and a distance rendered in PHP that disagreed by a kilometre
 * would be invisible in testing and obvious to a player comparing two screens.
 *
 * The acos() argument is clamped with least/greatest in SQL and min/max in PHP: floating
 * point can push the dot product a hair outside [-1, 1] for two identical or antipodal
 * points, and acos() of 1.0000000002 is a domain error in Postgres and NAN in PHP.
 */
final class Haversine
{
    /** Mean Earth radius, kilometres. */
    public const EARTH_RADIUS_KM = 6371.0;

    public const KM_PER_MILE = 1.609344;

    /**
     * Distance in km from (:lat, :lng) to the aliased row's lat/lng columns.
     *
     * Written against the alias `l` and the column names `lat`/`lng`. Bind `:lat` and `:lng`
     * as floats. Use with raw SQL (DQL has no acos/radians).
     */
    public const SQL_DISTANCE_KM = '(6371.0 * acos(least(1, greatest(-1, '
        . 'sin(radians(CAST(:lat AS double precision))) * sin(radians(CAST(l.lat AS double precision))) '
        . '+ cos(radians(CAST(:lat AS double precision))) * cos(radians(CAST(l.lat AS double precision))) '
        . '* cos(radians(CAST(l.lng AS double precision)) - radians(CAST(:lng AS double precision)))'
        . '))))';

    private function __construct() {}

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dot = sin(deg2rad($lat1)) * sin(deg2rad($lat2))
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2) - deg2rad($lng1));

        return self::EARTH_RADIUS_KM * acos(min(1.0, max(-1.0, $dot)));
    }

    public static function kmToMiles(float $km): float
    {
        return $km / self::KM_PER_MILE;
    }

    public static function milesToKm(float $miles): float
    {
        return $miles * self::KM_PER_MILE;
    }

    /**
     * A latitude/longitude box that fully contains every point within $radiusKm.
     *
     * Only ever used to PREFILTER — the box is larger than the circle, so the haversine
     * ORDER BY still decides. Longitude degrees shrink with latitude, hence the cos() term;
     * it is floored so a location near a pole widens the box rather than dividing by ~0.
     *
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    public static function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = $radiusKm / 111.32;

        $cos      = max(0.01, cos(deg2rad($lat)));
        $lngDelta = $radiusKm / (111.32 * $cos);

        return [
            'minLat' => max(-90.0, $lat - $latDelta),
            'maxLat' => min(90.0, $lat + $latDelta),
            'minLng' => max(-180.0, $lng - $lngDelta),
            'maxLng' => min(180.0, $lng + $lngDelta),
        ];
    }
}
