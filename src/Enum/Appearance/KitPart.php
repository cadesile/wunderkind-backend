<?php
namespace App\Enum\Appearance;

/**
 * A reference to whichever color currently fills that role, not a color
 * itself — used for `shorts`, `socks`, and `trousers`. Resolves to `primary`/
 * `secondary` (from the entity's own appearance) or the fixed KitColor::WHITE
 * / KitColor::BLACK.
 */
enum KitPart: string
{
    case PRIMARY   = 'primary';
    case SECONDARY = 'secondary';
    case WHITE     = 'white';
    case BLACK     = 'black';
}
