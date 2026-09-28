<?php

namespace App\Enum\Competition;

enum MatchEngineIdentifier: string
{
    case DETERMINISTIC = 'deterministic';
    case AI_ASSISTED    = 'ai_assisted';
    case AI_NARRATIVE    = 'ai_narrative';
}
