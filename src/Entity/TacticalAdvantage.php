<?php

namespace App\Entity;

use App\Enum\PlayingStyle;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
class TacticalAdvantage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(enumType: PlayingStyle::class)]
    private PlayingStyle $style;

    #[ORM\Column(enumType: PlayingStyle::class)]
    private PlayingStyle $opponentStyle;

    /** Advantage multiplier (e.g. 1.15 for 15% boost) */
    #[ORM\Column(type: 'float')]
    private float $multiplier;

    public function __construct(
        PlayingStyle $style = PlayingStyle::POSSESSION,
        PlayingStyle $opponentStyle = PlayingStyle::DIRECT,
        float $multiplier = 1.0
    ) {
        $this->id            = new UuidV7();
        $this->style         = $style;
        $this->opponentStyle = $opponentStyle;
        $this->multiplier    = $multiplier;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getStyle(): PlayingStyle { return $this->style; }
    public function setStyle(PlayingStyle $style): void { $this->style = $style; }

    public function getOpponentStyle(): PlayingStyle { return $this->opponentStyle; }
    public function setOpponentStyle(PlayingStyle $opponentStyle): void { $this->opponentStyle = $opponentStyle; }

    public function getMultiplier(): float { return $this->multiplier; }
    public function setMultiplier(float $multiplier): void { $this->multiplier = $multiplier; }
}
