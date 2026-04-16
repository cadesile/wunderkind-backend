<?php

namespace App\Enum;

enum PlayingStyle: string
{
    case POSSESSION = 'POSSESSION';
    case DIRECT     = 'DIRECT';
    case COUNTER    = 'COUNTER';
    case HIGH_PRESS = 'HIGH_PRESS';
}
