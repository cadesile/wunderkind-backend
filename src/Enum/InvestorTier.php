<?php

namespace App\Enum;

enum InvestorTier: string
{
    case ANGEL          = 'angel';
    case VC             = 'vc';
    case PRIVATE_EQUITY = 'private_equity';
}
