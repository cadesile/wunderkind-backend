<?php

declare(strict_types=1);

namespace App\Enum;

enum NotificationLogStatus: string
{
    case SUCCESS = 'success';
    case FAILED  = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::SUCCESS => 'Success',
            self::FAILED  => 'Failed',
        };
    }
}
