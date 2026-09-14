<?php

namespace App\Enum;

enum StatsPeriod: string
{
    case LAST_6_HOURS  = 'last_6_hours';
    case LAST_24_HOURS = 'last_24_hours';
    case WEEK          = 'week';
    case MONTH         = 'month';
    case SEASON        = 'season';
    case ALL           = 'all';
}
