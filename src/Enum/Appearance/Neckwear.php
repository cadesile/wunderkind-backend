<?php
namespace App\Enum\Appearance;

/** Staff/scout/agent only — see Headwear for why these live outside AvatarConfig. */
enum Neckwear: string
{
    case NONE    = 'none';
    case LANYARD = 'lanyard';
    case WHISTLE = 'whistle';
    case SCARF   = 'scarf';
}
