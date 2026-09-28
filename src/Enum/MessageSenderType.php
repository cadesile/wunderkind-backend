<?php

namespace App\Enum;

enum MessageSenderType: string
{
    case AGENT       = 'agent';
    case SPONSOR     = 'sponsor';
    case INVESTOR    = 'investor';
    case SYSTEM      = 'system';
    case COMPETITION = 'competition';
}
