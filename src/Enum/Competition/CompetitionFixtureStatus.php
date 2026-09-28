<?php

namespace App\Enum\Competition;

enum CompetitionFixtureStatus: string
{
    case PENDING  = 'pending';
    case COMPLETE = 'complete';
    case BYE      = 'bye';
}
