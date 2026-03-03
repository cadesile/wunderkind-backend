<?php

namespace App\Enum;

enum FacilityType: string
{
    case TRAINING_PITCH   = 'training_pitch';
    case MEDICAL_CENTRE   = 'medical_centre';
    case MEDICAL_NETWORK  = 'medical_network';
    case SCOUTING_NETWORK = 'scouting_network';
}
