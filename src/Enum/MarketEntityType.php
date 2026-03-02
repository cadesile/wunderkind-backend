<?php

namespace App\Enum;

enum MarketEntityType: string
{
    case PLAYER   = 'player';
    case COACH    = 'coach';
    case SCOUT    = 'scout';
    case SPONSOR  = 'sponsor';
    case INVESTOR = 'investor';
}
