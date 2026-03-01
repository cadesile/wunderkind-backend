<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
class Agent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $name;

    /** "Universal Agents" broker deals across all academies */
    #[ORM\Column]
    private bool $isUniversal = true;

    /** Reputation score influences deal quality and access to top players */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 50])]
    private int $reputation = 50;

    /** Commission rate as a percentage (e.g. 10 = 10%) */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $commissionRate = '10.00';

    #[ORM\OneToMany(mappedBy: 'agent', targetEntity: Player::class)]
    private Collection $players;

    public function __construct(string $name, bool $isUniversal = true)
    {
        $this->id          = new UuidV7();
        $this->name        = $name;
        $this->isUniversal = $isUniversal;
        $this->players     = new ArrayCollection();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function isUniversal(): bool { return $this->isUniversal; }
    public function setIsUniversal(bool $v): void { $this->isUniversal = $v; }

    public function getReputation(): int { return $this->reputation; }
    public function setReputation(int $reputation): void { $this->reputation = $reputation; }

    public function getCommissionRate(): string { return $this->commissionRate; }
    public function setCommissionRate(string $rate): void { $this->commissionRate = $rate; }

    public function getPlayers(): Collection { return $this->players; }
}
