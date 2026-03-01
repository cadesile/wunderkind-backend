<?php

namespace App\Enum;

enum TransferType: string
{
    case SALE         = 'sale';
    case LOAN         = 'loan';
    case FREE_RELEASE = 'free_release';
}
