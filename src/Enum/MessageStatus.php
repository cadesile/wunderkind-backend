<?php

namespace App\Enum;

enum MessageStatus: string
{
    case UNREAD   = 'unread';
    case READ     = 'read';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
}
