<?php

namespace App\Enum\WorldPack;

enum WorldPackGenerationClubRunStatus: string
{
    case PENDING             = 'pending';
    case GENERATING_PLAYERS  = 'generating_players';
    case GENERATING_STAFF    = 'generating_staff';
    case GENERATING_SCOUTS   = 'generating_scouts';
    case ASSIGNING           = 'assigning';
    case COMPLETED           = 'completed';
    case FAILED              = 'failed';
}
