<?php

namespace App\Enum\WorldPack;

/**
 * Lifecycle of one WorldPackSet — a world pack plus its starter squad, generated
 * together and claimable by exactly one club.
 *
 * The order matters and the gap between BUILDING and READY is the point of the enum:
 * a set holds nothing usable until every tier has been assembled AND the starter squad
 * has been drawn, so only READY is claimable. Without the distinction a half-built set
 * would be served to a device that then cannot finish initialising, which is the exact
 * failure the two-ready-sets buffer exists to prevent.
 *
 * FAILED means the TIERS could not be built — a failed tier run, or a set deleted from under
 * one. It is no longer reachable from the squad half at all: that is generated from tier config
 * rather than drawn from a shared pool, so it cannot be short. (It used to be the squad's only
 * failure mode, via InsufficientStarterPoolException.)
 */
enum WorldPackSetStatus: string
{
    case BUILDING = 'building';
    case READY    = 'ready';
    case CLAIMED  = 'claimed';
    case FAILED   = 'failed';
}
