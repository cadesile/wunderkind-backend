<?php

namespace App\Enum\WorldPack;

enum WorldPackGenerationRunStatus: string
{
    case PENDING               = 'pending';
    case IN_PROGRESS           = 'in_progress';
    case COMPLETED             = 'completed';
    case COMPLETED_WITH_ERRORS = 'completed_with_errors';
    case FAILED                = 'failed';
}
