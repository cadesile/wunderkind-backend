<?php

namespace App\Enum\Location;

/**
 * Display unit for rival distances. location_rival.distance_km always stores kilometres;
 * this only decides what the client is told to render, per the user's "store km and convert
 * to miles per locale" requirement.
 */
enum DistanceUnit: string
{
    case KM = 'km';
    case MI = 'mi';
}
