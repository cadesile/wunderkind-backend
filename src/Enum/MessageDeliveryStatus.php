<?php

namespace App\Enum;

/**
 * Delivery state of one AdminMessage for one Club.
 *
 * PENDING exists only as a placeholder for a row created before display; the poll query
 * excludes DISPLAYED and DISMISSED, so either of those terminal states retires the message.
 */
enum MessageDeliveryStatus: string
{
    case PENDING   = 'pending';
    case DISPLAYED = 'displayed';
    case DISMISSED = 'dismissed';
}
