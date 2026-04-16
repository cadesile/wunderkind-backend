<?php

namespace App\Enum;

enum FinancialApproach: string
{
    case SPECULATIVE  = 'SPECULATIVE';
    case BALANCED     = 'BALANCED';
    case CONSERVATIVE = 'CONSERVATIVE';
}
