<?php

namespace App\Enum;

enum VerificationPurpose: string
{
    case REGISTRATION   = 'registration';
    case PASSWORD_RESET = 'password_reset';
}
