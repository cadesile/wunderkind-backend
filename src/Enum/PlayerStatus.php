<?php

namespace App\Enum;

enum PlayerStatus: string
{
    case ACTIVE       = 'active';
    case LOANED_OUT   = 'loaned_out';
    case TRANSFERRED  = 'transferred';
    case RETIRED      = 'retired';
}
