<?php

namespace App\Entity;

use App\Enum\FacilityType;
use App\Repository\FacilityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: FacilityRepository::class)]
#[ORM\UniqueConstraint(columns: ['academy_id', 'type'])]
class Facility
{
    /** Upgrade cost in pence per level (index = target level, 1–5). */
    private const UPGRADE_COSTS = [0, 50_000, 150_000, 300_000, 500_000, 1_000_000];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'string', enumType: FacilityType::class)]
    private FacilityType $type;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $level = 0;

    #[ORM\ManyToOne(inversedBy: 'facilities')]
    #[ORM\JoinColumn(nullable: false)]
    private Academy $academy;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUpgradedAt = null;

    public function __construct(FacilityType $type, Academy $academy)
    {
        $this->id      = new UuidV7();
        $this->type    = $type;
        $this->academy = $academy;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getType(): FacilityType { return $this->type; }
    public function getTypeValue(): string { return $this->type->value; }

    public function getLevel(): int { return $this->level; }
    public function setLevel(int $level): void { $this->level = max(0, min(5, $level)); }

    public function getAcademy(): Academy { return $this->academy; }

    public function getLastUpgradedAt(): ?\DateTimeImmutable { return $this->lastUpgradedAt; }
    public function setLastUpgradedAt(?\DateTimeImmutable $at): void { $this->lastUpgradedAt = $at; }

    public function canUpgrade(): bool
    {
        return $this->level < 5;
    }

    public function getUpgradeCost(): int
    {
        return $this->canUpgrade() ? self::UPGRADE_COSTS[$this->level + 1] : 0;
    }

    public function getCurrentEffect(): string
    {
        if ($this->level === 0) {
            return 'No bonus';
        }

        $l = $this->level;

        return match ($this->type) {
            FacilityType::TRAINING_PITCH   => '+' . ($l * 5)  . '% coaching effectiveness',
            FacilityType::MEDICAL_CENTRE   => '+' . ($l * 10) . '% injury recovery speed',
            FacilityType::MEDICAL_NETWORK  => '+' . ($l * 5)  . '% injury prevention',
            FacilityType::SCOUTING_NETWORK => '+' . ($l * 10) . ' scouting range',
        };
    }
}
