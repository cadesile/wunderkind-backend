<?php

declare(strict_types=1);

namespace App\Service\Personality;

use App\Enum\StaffRole;

/**
 * Everything the generator needs to know about *what* it is generating: how wide
 * the base distribution should be, which traits skew off the baseline mean, and
 * which floors the entity's role demands.
 *
 * Note there is no ability/potential input. Personality is deliberately
 * independent of how good the entity is — a low-ceiling player can still be
 * fiercely determined, and a limited coach can still be unflappable.
 */
final class PersonalityContext
{
    /** Baseline mean of the 1–20 scale. */
    public const BASE_MU = 10.5;

    /**
     * @param array<string, float> $traitMeans Per-trait mean overrides.
     * @param array<string, int>   $floors     Per-trait minimums, applied last and never overridden.
     */
    private function __construct(
        public readonly float $sigma,
        public readonly array $traitMeans = [],
        public readonly array $floors = [],
    ) {}

    /**
     * Players tighten up as they mature: raw and volatile in the academy,
     * established by their twenties.
     *
     * Youth additionally skew Pressure and Consistency low — developmental
     * vulnerability, and the thing that gives scouting reports something to
     * disagree about.
     */
    public static function forPlayer(int $age): self
    {
        if ($age <= 16) {
            return new self(
                sigma: 4.2,
                traitMeans: ['pressure' => 8.0, 'consistency' => 8.0],
            );
        }

        // 17–20 is undefined by the design spec; treat it as the baseline band
        // between raw youth and an established professional.
        return new self(sigma: $age <= 20 ? 3.2 : 2.8);
    }

    /**
     * Staff are adults with settled personalities. Roles that carry a dressing
     * room have hard floors — someone who folds under pressure does not end up
     * running one.
     */
    public static function forStaff(StaffRole $role): self
    {
        $floors = match ($role) {
            StaffRole::MANAGER, StaffRole::COACH => [
                'determination' => 11,
                'temperament'   => 9,
                'pressure'      => 12,
            ],
            default => [],
        };

        return new self(sigma: 2.8, floors: $floors);
    }

    /** Scouts live out of a suitcase and file reports week after week. */
    public static function forScout(): self
    {
        return new self(
            sigma: 2.8,
            floors: ['adaptability' => 13, 'consistency' => 12],
        );
    }

    public function meanFor(string $trait): float
    {
        return $this->traitMeans[$trait] ?? self::BASE_MU;
    }
}
