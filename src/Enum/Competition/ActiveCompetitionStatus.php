<?php

namespace App\Enum\Competition;

enum ActiveCompetitionStatus: string
{
    case REGISTERING = 'registering';
    case SCHEDULED    = 'scheduled';
    case RUNNING       = 'running';
    case COMPLETED     = 'completed';
    case CANCELLED     = 'cancelled';
}
