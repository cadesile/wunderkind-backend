<?php

namespace App\Enum\Location;

/**
 * A location is retired, never deleted — clubs already sitting on its plots must keep
 * working, and plot_claim's FK to it is ON DELETE RESTRICT for exactly that reason.
 */
enum LocationStatus: string
{
    case ACTIVE  = 'active';
    case RETIRED = 'retired';
}
