<?php

namespace App\Enum;

enum Tier: string
{
    case LOCAL    = 'local';
    case REGIONAL = 'regional';
    case NATIONAL = 'national';
    case ELITE    = 'elite';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score <= 14 => self::LOCAL,
            $score <= 39 => self::REGIONAL,
            $score <= 74 => self::NATIONAL,
            default      => self::ELITE,
        };
    }

    /** @return array{int, int} [min, max] inclusive score range for this tier */
    public function scoreRange(): array
    {
        return match ($this) {
            self::LOCAL    => [0,  14],
            self::REGIONAL => [15, 39],
            self::NATIONAL => [40, 74],
            self::ELITE    => [75, 100],
        };
    }
}
