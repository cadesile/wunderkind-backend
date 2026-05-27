<?php

declare(strict_types=1);

namespace App\Enum;

enum TrophyColour: string
{
    case SILVER      = 'silver';
    case GOLD        = 'gold';
    case GOLD_SILVER = 'gold_silver';

    public function label(): string
    {
        return match ($this) {
            self::SILVER      => 'Silver',
            self::GOLD        => 'Gold',
            self::GOLD_SILVER => 'Gold & Silver',
        };
    }
}
