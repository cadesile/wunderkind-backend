<?php

namespace App\Enum;

/**
 * Int-backed, unlike every other enum in this namespace, because the poll query orders by
 * `priority DESC` — a string column would sort lexically and put 'standard' above 'urgent'.
 */
enum MessagePriority: int
{
    case LOW      = 1;
    case STANDARD = 2;
    case URGENT   = 3;
}
