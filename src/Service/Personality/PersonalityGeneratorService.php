<?php

declare(strict_types=1);

namespace App\Service\Personality;

use App\Entity\PersonalityProfile;
use App\Enum\PersonalityMould;

/**
 * Rolls the 8-spoke Personality Matrix.
 *
 * The goal is profiles that read as people rather than eight similar numbers, so
 * generation is mould-driven: a weighted shape is chosen first, then traits are
 * pushed to serve it. Every entity gets genuine trade-offs — a standout strength
 * usually paid for with a real flaw.
 *
 * Pipeline, in strict order:
 *   1. pick a PersonalityMould by weight
 *   2. base-roll all eight traits Gaussian(mu, sigma) from the context
 *   3. apply the mould — dominants 15–20, flaws 1–7, moderates 8–13 (or BALANCED's 7–14)
 *   4. apply correlation rules, but ONLY to traits the mould did not pin
 *   5. apply role floors — these outrank everything above
 *   6. clamp to 1–20
 *
 * On step 4: the design spec is explicit that an archetype overrides a
 * correlation ("cap default roll ... unless specific archetype overrides"), so a
 * mould-pinned trait is never walked back by a correlation.
 *
 * On the distribution baseline (mu 10.5, sigma 3.2, extremes ~5–8%): that
 * describes the *base roll* at step 2, not the finished matrix. Steps 3–5 push
 * traits to the ends on purpose, so the emitted population is intentionally more
 * extreme than the underlying Gaussian. gaussianInt() is what the baseline is
 * asserted against.
 */
final class PersonalityGeneratorService
{
    /** Canonical trait order — matches PersonalityProfile::toArray(). */
    public const TRAITS = [
        'determination', 'professionalism', 'ambition', 'loyalty',
        'adaptability', 'pressure', 'temperament', 'consistency',
    ];

    private const DOMINANT = [15, 20];
    private const FLAW     = [1, 7];
    private const MODERATE = [8, 13];

    private const MIN = 1;
    private const MAX = 20;

    /**
     * @return array<string, int> The eight traits, each 1–20.
     */
    public function rollTraits(PersonalityContext $ctx): array
    {
        $mould  = PersonalityMould::pick();
        $traits = $this->baseRoll($ctx);

        $traits = $this->applyMould($traits, $mould);
        $traits = $this->applyCorrelations($traits, $mould);
        $traits = $this->applyFloors($traits, $ctx);

        return array_map(fn(int $v) => $this->clamp($v), $traits);
    }

    /** Rolls a fresh matrix straight onto an embedded profile. */
    public function apply(PersonalityProfile $profile, PersonalityContext $ctx): void
    {
        $traits = $this->rollTraits($ctx);

        $profile->setDetermination($traits['determination']);
        $profile->setProfessionalism($traits['professionalism']);
        $profile->setAmbition($traits['ambition']);
        $profile->setLoyalty($traits['loyalty']);
        $profile->setAdaptability($traits['adaptability']);
        $profile->setPressure($traits['pressure']);
        $profile->setTemperament($traits['temperament']);
        $profile->setConsistency($traits['consistency']);
    }

    /**
     * Box-Muller normal draw, rounded and clamped to [$min, $max].
     * Shared with PlayerGenerationService so there is one Gaussian in the codebase.
     */
    public function gaussianInt(float $mu, float $sigma, int $min, int $max): int
    {
        $u1 = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
        $u2 = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
        $z  = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);

        return max($min, min($max, (int) round($mu + $z * $sigma)));
    }

    // ── Pipeline steps ───────────────────────────────────────────────────────

    /** @return array<string, int> */
    private function baseRoll(PersonalityContext $ctx): array
    {
        $traits = [];
        foreach (self::TRAITS as $trait) {
            $traits[$trait] = $this->gaussianInt($ctx->meanFor($trait), $ctx->sigma, self::MIN, self::MAX);
        }

        return $traits;
    }

    /**
     * @param  array<string, int> $traits
     * @return array<string, int>
     */
    private function applyMould(array $traits, PersonalityMould $mould): array
    {
        if (($range = $mould->globalRange()) !== null) {
            foreach ($traits as $trait => $value) {
                $traits[$trait] = max($range[0], min($range[1], $value));
            }

            return $traits;
        }

        foreach ($mould->dominants() as $trait) {
            $traits[$trait] = random_int(...self::DOMINANT);
        }
        foreach ($mould->flaws() as $trait) {
            $traits[$trait] = random_int(...self::FLAW);
        }
        foreach ($mould->moderates() as $trait) {
            $traits[$trait] = random_int(...self::MODERATE);
        }

        return $traits;
    }

    /**
     * Trait interdependencies. Each rule reads the current matrix and adjusts
     * only traits the mould left free.
     *
     * Public because the precedence between a mould and these rules is the
     * subtlest part of generation, and is worth asserting directly rather than
     * inferring from sampled output.
     *
     * @param  array<string, int> $traits
     * @return array<string, int>
     */
    public function applyCorrelations(array $traits, PersonalityMould $mould): array
    {
        // Every condition reads $trigger, the matrix as it stood before this pass.
        // Testing against the live matrix makes the rules order-dependent: capping
        // Loyalty for a high-ambition player would erase the Club Stalwart trigger
        // before it was ever evaluated, so whichever rule ran first would silently
        // suppress the other.
        $trigger = $traits;
        $pinned  = $mould->pinnedTraits();

        $cap = function (string $trait, int $ceiling) use (&$traits, $pinned): void {
            if (!in_array($trait, $pinned, true)) {
                $traits[$trait] = min($traits[$trait], $ceiling);
            }
        };
        $floor = function (string $trait, int $minimum) use (&$traits, $pinned): void {
            if (!in_array($trait, $pinned, true)) {
                $traits[$trait] = max($traits[$trait], $minimum);
            }
        };

        // The Mercenary Divergence — drive to move on erodes attachment.
        if ($trigger['ambition'] >= 15) {
            $cap('loyalty', 10);
        }

        // The Model Pro Core — genuine professionalism drags graft and reliability up with it.
        if ($trigger['professionalism'] >= 16) {
            $floor('determination', 12);
            $floor('consistency', 12);
        }

        // The Volatile Genius Trade-off — ambition without composure cracks under scrutiny.
        if ($trigger['ambition'] >= 15 && $trigger['temperament'] <= 6) {
            $cap('pressure', 8);
        }

        // The Club Stalwart Anchor — deep attachment blunts wanderlust and steadies the head.
        if ($trigger['loyalty'] >= 16) {
            $cap('ambition', 12);
            $floor('temperament', 12);
        }

        return $traits;
    }

    /**
     * Role eligibility floors. Applied last and allowed to override a mould flaw:
     * a prospective manager who folds under pressure would not be a manager.
     *
     * @param  array<string, int> $traits
     * @return array<string, int>
     */
    private function applyFloors(array $traits, PersonalityContext $ctx): array
    {
        foreach ($ctx->floors as $trait => $minimum) {
            $traits[$trait] = max($traits[$trait], $minimum);
        }

        return $traits;
    }

    private function clamp(int $value): int
    {
        return max(self::MIN, min(self::MAX, $value));
    }
}
