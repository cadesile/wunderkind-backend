<?php

namespace App\Enum\WorldPack;

enum WorldPackGenerationTierRunStatus: string
{
    case PENDING     = 'pending';
    case IN_PROGRESS = 'in_progress';
    case ASSEMBLING  = 'assembling';
    case COMPLETED   = 'completed';
    case FAILED      = 'failed';
}
