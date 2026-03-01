<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
class Guardian
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $contactEmail = null;

    /**
     * How demanding the guardian is in negotiations and decisions.
     * Scale 1–10. Higher = harder to manage.
     */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 5])]
    private int $demandLevel = 5;

    /**
     * Guardian's loyalty to the academy (0–100).
     * Drops when you ignore their concerns; rises with praise and progress.
     */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $loyaltyToAcademy = 50;

    #[ORM\OneToOne(inversedBy: 'guardian')]
    #[ORM\JoinColumn(nullable: false)]
    private Player $player;

    public function __construct(string $firstName, string $lastName, Player $player)
    {
        $this->id        = new UuidV7();
        $this->firstName = $firstName;
        $this->lastName  = $lastName;
        $this->player    = $player;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }

    public function getFullName(): string { return "{$this->firstName} {$this->lastName}"; }

    public function getContactEmail(): ?string { return $this->contactEmail; }
    public function setContactEmail(?string $email): void { $this->contactEmail = $email; }

    public function getDemandLevel(): int { return $this->demandLevel; }
    public function setDemandLevel(int $level): void { $this->demandLevel = max(1, min(10, $level)); }

    public function getLoyaltyToAcademy(): int { return $this->loyaltyToAcademy; }
    public function setLoyaltyToAcademy(int $loyalty): void { $this->loyaltyToAcademy = max(0, min(100, $loyalty)); }

    public function getPlayer(): Player { return $this->player; }
}
