<?php

namespace App\Enum;

enum SponsorStatus: string
{
    case ACTIVE            = 'active';
    case COMPLETED         = 'completed';
    case VOIDED            = 'voided';
    case EARLY_TERMINATED  = 'early_terminated';
}
