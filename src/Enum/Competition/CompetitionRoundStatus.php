<?php

namespace App\Enum\Competition;

enum CompetitionRoundStatus: string
{
    case DRAW_PENDING      = 'draw_pending';
    case DRAWN             = 'drawn';
    case RESULTS_PUBLISHED = 'results_published';
    case CANCELLED         = 'cancelled';
}
