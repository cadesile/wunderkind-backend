<?php

namespace App\Enum;

enum RecruitmentSource: string
{
    case SCOUTING_NETWORK = 'scouting_network';
    case COACHING_FIND    = 'coaching_find';
    case AGENT_OFFER      = 'agent_offer';
    case YOUTH_REQUEST    = 'youth_request';
    case YOUTH_INTAKE     = 'youth_intake';
}
