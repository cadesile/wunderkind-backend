<?php

declare(strict_types=1);

namespace App\Enum\Friendly;

enum FriendlyChallengeStatus: string
{
    case OPEN      = 'open';
    case ACCEPTED  = 'accepted';
    case COMPLETE  = 'complete';
    case CANCELLED = 'cancelled';
    case EXPIRED   = 'expired';
}
