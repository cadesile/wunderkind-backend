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
}
