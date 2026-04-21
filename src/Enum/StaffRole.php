<?php

namespace App\Enum;

enum StaffRole: string
{
    case ASSISTANT_COACH       = 'assistant_coach';
    case COACH                 = 'coach';
    case MANAGER               = 'manager';
    case DIRECTOR_OF_FOOTBALL  = 'director_of_football';
    case FACILITY_MANAGER      = 'facility_manager';
    case CHAIRMAN              = 'chairman';
}

