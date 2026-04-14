<?php

namespace App\Enum;

enum ReputationTier: string
{
    case LOCAL    = 'local';
    case REGIONAL = 'regional';
    case NATIONAL = 'national';
    case ELITE    = 'elite';
}
