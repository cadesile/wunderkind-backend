<?php
namespace App\Enum\Appearance;

enum AppearanceRole: string
{
    case PLAYER = 'PLAYER';
    case COACH  = 'COACH';
    case SCOUT  = 'SCOUT';
    case AGENT  = 'AGENT';
}
