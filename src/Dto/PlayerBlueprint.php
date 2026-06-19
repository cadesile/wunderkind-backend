<?php
// src/Dto/PlayerBlueprint.php
namespace App\Dto;

use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;

readonly class PlayerBlueprint
{
    public function __construct(
        // ── Anchors ───────────────────────────────────────────────────────────
        public string             $firstName,
        public string             $lastName,
        public string             $nationality,
        public int                $age,
        public \DateTimeImmutable $dateOfBirth,
        public int                $height,
        public int                $weight,
        public PlayerPosition     $position,
        public int                $potential,
        public RecruitmentSource  $source,

        // ── Step 2: ability target ────────────────────────────────────────────
        public float $abilityTarget  = 0.0,
        public bool  $isProdigy      = false,

        // ── Step 3: personality (1–20) ────────────────────────────────────────
        public int $determination    = 0,
        public int $professionalism  = 0,
        public int $ambition         = 0,
        public int $loyalty          = 0,
        public int $adaptability     = 0,
        public int $pressure         = 0,
        public int $temperament      = 0,
        public int $consistency      = 0,

        // ── Step 4: attributes (1–100) ────────────────────────────────────────
        public int $pace             = 0,
        public int $technical        = 0,
        public int $vision           = 0,
        public int $power            = 0,
        public int $stamina          = 0,
        public int $heart            = 0,
        public int $currentAbility   = 0,
    ) {}
}
