<?php

namespace App\Enum\Location;

/** Same retire-never-delete reasoning as LocationStatus. */
enum LandPlotStatus: string
{
    case ACTIVE  = 'active';
    case RETIRED = 'retired';
}
