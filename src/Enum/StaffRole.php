<?php

namespace App\Enum;

enum StaffRole: string
{
    case HEAD_COACH       = 'head_coach';
    case ASSISTANT_COACH  = 'assistant_coach';
    case SCOUT            = 'scout';
    case FITNESS_COACH    = 'fitness_coach';
    case ANALYST          = 'analyst';
}
