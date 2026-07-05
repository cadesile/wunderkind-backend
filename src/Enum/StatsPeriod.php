<?php

namespace App\Enum;

enum StatsPeriod: string
{
    case WEEK   = 'week';
    case MONTH  = 'month';
    case SEASON = 'season';
    case ALL    = 'all';
}
