<?php

namespace App\Enum\Location;

enum EnrichmentRunStatus: string
{
    case RUNNING   = 'running';
    case COMPLETE  = 'complete';
    case CANCELLED = 'cancelled';
}
