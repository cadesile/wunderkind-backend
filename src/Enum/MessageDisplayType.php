<?php

namespace App\Enum;

/**
 * How the client should surface a message. Only MODAL_BLOCKING interrupts the player, and the
 * poll endpoint returns at most one of those per response.
 */
enum MessageDisplayType: string
{
    case MODAL_BLOCKING = 'modal_blocking';
    case INBOX_ITEM     = 'inbox_item';
    case BOTTOM_SHEET   = 'bottom_sheet';
}
