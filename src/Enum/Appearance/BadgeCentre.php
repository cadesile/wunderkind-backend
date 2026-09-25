<?php
namespace App\Enum\Appearance;

/**
 * `NONE` is a valid, admin-settable choice — only the generator/randomiser
 * avoids it (a blank badge centre isn't a useful default to roll).
 */
enum BadgeCentre: string
{
    case NONE     = 'none';
    case INITIALS = 'initials';
    case STAR     = 'star';
    case BALL     = 'ball';
    case CROWN    = 'crown';
}
