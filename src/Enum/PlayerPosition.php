<?php

namespace App\Enum;

enum PlayerPosition: string
{
    case GOALKEEPER       = 'GK';
    case DEFENDER         = 'DEF';
    case MIDFIELDER       = 'MID';
    case ATTACKER         = 'ATT';
}
