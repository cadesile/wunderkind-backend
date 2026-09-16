<?php

namespace App\Enum\Competition;

enum CompetitionRoundStatus: string
{
    case PENDING   = 'pending';
    case SCHEDULED = 'scheduled';
    case RUNNING   = 'running';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
