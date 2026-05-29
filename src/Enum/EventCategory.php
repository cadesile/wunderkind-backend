<?php

namespace App\Enum;

enum EventCategory: string
{
    case PLAYER          = 'player';
    case FACILITY        = 'facility';
    case STAFF           = 'staff';
    case FINANCE         = 'finance';
    case NPC_INTERACTION = 'NPC_INTERACTION';
    case GUARDIAN        = 'GUARDIAN';
    case MATCH           = 'MATCH';
    case PRESS              = 'press';
    case PLAYER_REPUTATION  = 'player_reputation';
    case PLAYER_MILESTONE   = 'player_milestone';
    case PLAYER_MORALE      = 'player_morale';
    case PLAYER_FORM        = 'player_form';
}
