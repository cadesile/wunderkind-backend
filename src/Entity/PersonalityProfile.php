<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Embeddable representing the 8-spoke Personality Matrix.
 * Unified to 1-20 scale matching the frontend.
 *
 * Traits: Determination, Professionalism, Ambition, Loyalty,
 *         Adaptability, Pressure, Temperament, Consistency
 */
#[ORM\Embeddable]
class PersonalityProfile
{
    /** Value every trait is constructed at, and the marker for "not yet generated". */
    public const DEFAULT_TRAIT = 10;

    #[ORM\Column(type: 'smallint', options: ['default' => 10])]
    private int $determination = 10;

    #[ORM\Column(type: 'smallint', options: ['default' => 10])]
    private int $professionalism = 10;

    #[ORM\Column(type: 'smallint', options: ['default' => 10])]
    private int $ambition = 10;

    #[ORM\Column(type: 'smallint', options: ['default' => 10])]
    private int $loyalty = 10;

    #[ORM\Column(type: 'smallint', options: ['default' => 10])]
    private int $adaptability = 10;

    #[ORM\Column(type: 'smallint', options: ['default' => 10])]
    private int $pressure = 10;

    #[ORM\Column(type: 'smallint', options: ['default' => 10])]
    private int $temperament = 10;

    #[ORM\Column(type: 'smallint', options: ['default' => 10])]
    private int $consistency = 10;

    public function getDetermination(): int { return $this->determination; }
    public function setDetermination(int $v): void { $this->determination = $this->clamp($v); }

    public function getProfessionalism(): int { return $this->professionalism; }
    public function setProfessionalism(int $v): void { $this->professionalism = $this->clamp($v); }

    public function getAmbition(): int { return $this->ambition; }
    public function setAmbition(int $v): void { $this->ambition = $this->clamp($v); }

    public function getLoyalty(): int { return $this->loyalty; }
    public function setLoyalty(int $v): void { $this->loyalty = $this->clamp($v); }

    public function getAdaptability(): int { return $this->adaptability; }
    public function setAdaptability(int $v): void { $this->adaptability = $this->clamp($v); }

    public function getPressure(): int { return $this->pressure; }
    public function setPressure(int $v): void { $this->pressure = $this->clamp($v); }

    public function getTemperament(): int { return $this->temperament; }
    public function setTemperament(int $v): void { $this->temperament = $this->clamp($v); }

    public function getConsistency(): int { return $this->consistency; }
    public function setConsistency(int $v): void { $this->consistency = $this->clamp($v); }

    /**
     * The eight traits keyed in the canonical order every client payload uses.
     * Single source of truth for the serialized `personality` block — player,
     * staff and scout snapshots all emit this.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'determination'   => $this->determination,
            'professionalism' => $this->professionalism,
            'ambition'        => $this->ambition,
            'loyalty'         => $this->loyalty,
            'adaptability'    => $this->adaptability,
            'pressure'        => $this->pressure,
            'temperament'     => $this->temperament,
            'consistency'     => $this->consistency,
        ];
    }

    /**
     * True while every trait still sits at the constructed default. This is how
     * PersonalityLifecycleSubscriber tells an ungenerated profile from one that
     * has already been populated — an embedded value object is never null, so
     * there is no other "unset" signal to read.
     */
    public function isDefault(): bool
    {
        foreach ($this->toArray() as $value) {
            if ($value !== self::DEFAULT_TRAIT) {
                return false;
            }
        }
        return true;
    }

    private function clamp(int $v): int
    {
        return max(1, min(20, $v));
    }
}
