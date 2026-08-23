<?php

declare(strict_types=1);

namespace App\Service\Personality;

use App\Entity\PersonalityProfile;

/**
 * Rolls the 8-spoke Personality Matrix.
 *
 * The formula is the one PlayerGenerationService has always used: each trait is
 * drawn uniformly from a 30-point-wide window ending at the anchor, expressed as
 * a percentage and scaled onto the 1–20 trait scale. Players anchor on
 * `potential`; staff anchor on `coachingAbility` and scouts on `experience`, so
 * a stronger entity skews to higher traits exactly the way a high-potential
 * player does.
 */
final class PersonalityGeneratorService
{
    /** Canonical trait order — matches PersonalityProfile::toArray(). */
    public const TRAITS = [
        'determination', 'professionalism', 'ambition', 'loyalty',
        'adaptability', 'pressure', 'temperament', 'consistency',
    ];

    /** Width of the roll window below the anchor, in anchor points. */
    private const WINDOW = 30;

    /**
     * @return array<string, int> The eight traits, each 1–20.
     */
    public function rollTraits(int $anchor): array
    {
        $anchor = max(0, min(100, $anchor));
        $maxPct = $anchor / 100.0;
        $minPct = max(0.0, ($anchor - self::WINDOW) / 100.0);

        $traits = [];
        foreach (self::TRAITS as $trait) {
            $traits[$trait] = $this->randTrait($minPct, $maxPct);
        }

        return $traits;
    }

    /** Rolls a fresh matrix straight onto an embedded profile. */
    public function apply(PersonalityProfile $profile, int $anchor): void
    {
        $traits = $this->rollTraits($anchor);

        $profile->setDetermination($traits['determination']);
        $profile->setProfessionalism($traits['professionalism']);
        $profile->setAmbition($traits['ambition']);
        $profile->setLoyalty($traits['loyalty']);
        $profile->setAdaptability($traits['adaptability']);
        $profile->setPressure($traits['pressure']);
        $profile->setTemperament($traits['temperament']);
        $profile->setConsistency($traits['consistency']);
    }

    private function randTrait(float $minPct, float $maxPct): int
    {
        $pct = $minPct + (mt_rand() / mt_getrandmax()) * ($maxPct - $minPct);

        return max(1, min(20, (int) ceil(20.0 * $pct)));
    }
}
