<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Generation-time distribution shapes for the Personality Matrix.
 *
 * Deliberately NOT called an archetype: `PlayerArchetype` is a separate, shipped
 * concept — a curated catalogue the *client* classifies players against after the
 * fact. A mould only shapes the numbers at generation time; it is never persisted
 * and never leaves the backend, so the client's classification stays the single
 * source of archetype truth.
 *
 * Dominant traits roll 15–20, flaws roll 1–7, moderates roll 8–13. BALANCED pins
 * the whole matrix into 7–14 instead, producing an unformed profile with no
 * standout strength or flaw.
 */
enum PersonalityMould
{
    case MODEL_PROFESSIONAL;
    case AMBITIOUS_MERCENARY;
    case HOMETOWN_HERO;
    case VOLATILE_PRODIGY;
    case UNSUNG_WORKHORSE;
    case BALANCED;

    /** Selection weight as a percentage; the six cases sum to 100. */
    public function weight(): int
    {
        return match ($this) {
            self::MODEL_PROFESSIONAL  => 15,
            self::AMBITIOUS_MERCENARY => 20,
            self::HOMETOWN_HERO       => 15,
            self::VOLATILE_PRODIGY    => 15,
            self::UNSUNG_WORKHORSE    => 20,
            self::BALANCED            => 15,
        };
    }

    /** @return string[] Traits forced into 15–20. */
    public function dominants(): array
    {
        return match ($this) {
            self::MODEL_PROFESSIONAL  => ['professionalism', 'determination', 'consistency'],
            self::AMBITIOUS_MERCENARY => ['ambition', 'determination'],
            self::HOMETOWN_HERO       => ['loyalty', 'temperament', 'consistency'],
            self::VOLATILE_PRODIGY    => ['ambition', 'adaptability'],
            self::UNSUNG_WORKHORSE    => ['loyalty', 'professionalism', 'pressure'],
            self::BALANCED            => [],
        };
    }

    /** @return string[] Traits forced into 1–7. */
    public function flaws(): array
    {
        return match ($this) {
            self::AMBITIOUS_MERCENARY => ['loyalty', 'temperament'],
            self::HOMETOWN_HERO       => ['ambition', 'adaptability'],
            self::VOLATILE_PRODIGY    => ['temperament', 'pressure'],
            self::UNSUNG_WORKHORSE    => ['ambition', 'adaptability'],
            default                   => [],
        };
    }

    /** @return string[] Traits held deliberately mid-range, 8–13. */
    public function moderates(): array
    {
        return match ($this) {
            self::MODEL_PROFESSIONAL => ['ambition'],
            default                  => [],
        };
    }

    /**
     * Range every trait is confined to, for moulds that shape the whole matrix
     * rather than picking out individual traits.
     *
     * @return array{0: int, 1: int}|null
     */
    public function globalRange(): ?array
    {
        return $this === self::BALANCED ? [7, 14] : null;
    }

    /** Traits this mould pins, and which correlation rules must therefore not overwrite. */
    public function pinnedTraits(): array
    {
        return [...$this->dominants(), ...$this->flaws(), ...$this->moderates()];
    }

    /** Weighted random selection across the 100-point table. */
    public static function pick(): self
    {
        $roll = random_int(1, 100);

        $cursor = 0;
        foreach (self::cases() as $mould) {
            $cursor += $mould->weight();
            if ($roll <= $cursor) {
                return $mould;
            }
        }

        return self::BALANCED;
    }
}
