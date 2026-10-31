<?php

declare(strict_types=1);

namespace App\Service\Facility;

/**
 * Which game facility slug draws as which sprite id — GENERATED, DO NOT EDIT BY HAND.
 *
 * Regenerate:  bash scripts/build-facility-compositor.sh
 * Source sha:  0d5b65c38fefcd22
 *
 * The sprite drop authors a `slug` on every facility (and STADIUM_SLUGS for the
 * stadium), so the mapping is stated once, upstream, in the same file that draws
 * the thing. This class is that statement compiled into PHP so the backend can
 * build sprite configs from ClubFacility rows without a hand-maintained second
 * copy free to drift the moment the drop renames an id.
 *
 * Generated from the built bundle, not by re-parsing the TypeScript, so what is
 * here is exactly what window.FACILITY_SITES gives the browser.
 */
final class FacilitySpriteMap
{
    /** @var array<string, array<string, string>> site => (game slug => sprite id) */
    public const SITES = [
        'training' => [
            'training_pitch' => 'pitches',
            'strength_suite' => 'gym',
            'technical_zone' => 'techzone',
            'tactical_room' => 'tactics',
        ],
        'medical' => [
            'physio_clinic' => 'physio',
            'biometric_hub' => 'science',
            'imaging_suite' => 'imaging',
            'hydro_pool' => 'rehab',
            'nutrition_centre' => 'nutrition',
            'player_wellbeing' => 'wellbeing',
        ],
        'scouting' => [
            'scouting_center' => 'hq',
            'advanced_scouting' => 'data',
            'trial_pitch' => 'trial',
            'scout_fleet' => 'fleet',
            'youth_academy' => 'academy',
        ],
        'stadium' => [
            'north_stand' => 'north',
            'east_stand' => 'east',
            'south_stand' => 'south',
            'west_stand' => 'west',
            'club_shop' => 'shop',
            'museum' => 'museum',
            'car_park' => 'carpark',
        ],
    ];

    /** Every game facility slug the sprites can draw, across all four sites. */
    public static function knownSlugs(): array
    {
        return array_merge(...array_map(array_keys(...), array_values(self::SITES)));
    }
}
