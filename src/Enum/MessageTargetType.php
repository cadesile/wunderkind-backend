<?php

namespace App\Enum;

/**
 * How an AdminMessage selects its recipients.
 *
 * DIRECT_CLUB targets a single Club, not a User — every cohort axis the messaging system
 * supports (reputation, league tier, country, week) lives on Club, and one User may own
 * several clubs.
 */
enum MessageTargetType: string
{
    case BROADCAST       = 'broadcast';
    case GROUP_SEGMENTED = 'group_segmented';
    case DIRECT_CLUB     = 'direct_club';
}
