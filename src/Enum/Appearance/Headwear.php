<?php
namespace App\Enum\Appearance;

/**
 * Staff/scout/agent only — the sprite ignores it on a player body.
 *
 * Part of AvatarExtras, not AvatarConfig: the sprite froze AvatarConfig at 19 keys in
 * V4 and takes everything new as the `extras` render option. The backend stores extras
 * FLAT alongside the config in the same `appearance` json, because that is exactly the
 * shape the upstream builder emits and `splitAvatarConfig()` consumes.
 *
 * Headwear replaces the hair above the brim and hides a headband, so a config with both
 * is not a conflict to resolve here — the sprite resolves it.
 */
enum Headwear: string
{
    case NONE   = 'none';
    case CAP    = 'cap';
    case BEANIE = 'beanie';
}
