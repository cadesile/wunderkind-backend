<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Embeddable representing the 8-spoke Personality Matrix.
 * All traits are hidden from the player (0–100 scale).
 *
 * Mental: Confidence, Maturity, Teamwork, Leadership
 * Risk:   Ego, Bravery, Greed, Loyalty
 */
#[ORM\Embeddable]
class PersonalityProfile
{
    // Mental traits
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $confidence = 50;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $maturity = 50;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $teamwork = 50;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $leadership = 50;

    // Risk traits
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $ego = 50;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $bravery = 50;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $greed = 50;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $loyalty = 50;

    public function getConfidence(): int { return $this->confidence; }
    public function setConfidence(int $v): void { $this->confidence = $this->clamp($v); }

    public function getMaturity(): int { return $this->maturity; }
    public function setMaturity(int $v): void { $this->maturity = $this->clamp($v); }

    public function getTeamwork(): int { return $this->teamwork; }
    public function setTeamwork(int $v): void { $this->teamwork = $this->clamp($v); }

    public function getLeadership(): int { return $this->leadership; }
    public function setLeadership(int $v): void { $this->leadership = $this->clamp($v); }

    public function getEgo(): int { return $this->ego; }
    public function setEgo(int $v): void { $this->ego = $this->clamp($v); }

    public function getBravery(): int { return $this->bravery; }
    public function setBravery(int $v): void { $this->bravery = $this->clamp($v); }

    public function getGreed(): int { return $this->greed; }
    public function setGreed(int $v): void { $this->greed = $this->clamp($v); }

    public function getLoyalty(): int { return $this->loyalty; }
    public function setLoyalty(int $v): void { $this->loyalty = $this->clamp($v); }

    private function clamp(int $v): int
    {
        return max(0, min(100, $v));
    }
}
