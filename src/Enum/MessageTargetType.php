<?php

namespace App\Enum;

/**
 * How an AdminMessage selects its recipients.
 *
 * DIRECT_CLUB targets a single Club, not a User — every cohort axis the messaging system
 * supports (reputation, league tier, country, week) lives on Club, and one User may own
 * several clubs.
 *
 * DIRECT_USER targets one account whatever club it is playing now, or later. It exists for
 * operator notices sent from the admin user page (UserNotifyController), above all go-live
 * comms, when the account's old clubs have been sold and the new one does not exist yet:
 * the message waits and shows on whichever club the owner polls from next.
 */
enum MessageTargetType: string
{
    case BROADCAST       = 'broadcast';
    case GROUP_SEGMENTED = 'group_segmented';
    case DIRECT_CLUB     = 'direct_club';
    case DIRECT_USER     = 'direct_user';
}
