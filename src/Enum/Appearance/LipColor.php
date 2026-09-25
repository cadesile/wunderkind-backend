<?php
namespace App\Enum\Appearance;

enum LipColor: string
{
    case ROSE       = '#c9575e';
    case PEACH      = '#d98a7e';
    case RED        = '#b8302f';
    case BERRY      = '#a8424a';
    case BROWN      = '#8a4a3a';
    case PLUM       = '#6e2f3f';

    /** Reserved for dark skin tones (SkinId::S5/S6) — see AppearanceGeneratorService. */
    case DEEP_BROWN = '#5c3822';
}
