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

    private function clamp(int $v): int
    {
        return max(1, min(20, $v));
    }
}
