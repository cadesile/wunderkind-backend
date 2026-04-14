<?php

namespace App\Entity;

use App\Repository\NpcClubRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: NpcClubRepository::class)]
#[ORM\Table(name: 'npc_club')]
class NpcClub
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(type: 'smallint')]
    private int $tier;

    #[ORM\Column(type: 'smallint')]
    private int $reputation;

    #[ORM\Column(length: 7)]
    private string $primaryColor;

    #[ORM\Column(length: 7)]
    private string $secondaryColor;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stadiumName = null;

    #[ORM\Column(type: 'integer')]
    private int $balance;

    #[ORM\Column(type: 'json')]
    private array $facilities;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?League $league = null;

    public function __construct(
        string $name,
        string $country,
        int $tier,
        int $reputation,
        string $primaryColor,
        string $secondaryColor,
        int $balance,
        array $facilities,
    ) {
        $this->id             = new UuidV7();
        $this->name           = $name;
        $this->country        = $country;
        $this->tier           = $tier;
        $this->reputation     = $reputation;
        $this->primaryColor   = $primaryColor;
        $this->secondaryColor = $secondaryColor;
        $this->balance        = $balance;
        $this->facilities     = $facilities;
        $this->createdAt      = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getCountry(): string { return $this->country; }
    public function setCountry(string $v): static { $this->country = $v; return $this; }

    public function getTier(): int { return $this->tier; }
    public function setTier(int $v): static { $this->tier = $v; return $this; }

    public function getReputation(): int { return $this->reputation; }
    public function setReputation(int $v): static { $this->reputation = $v; return $this; }

    public function getPrimaryColor(): string { return $this->primaryColor; }
    public function setPrimaryColor(string $v): static { $this->primaryColor = $v; return $this; }

    public function getSecondaryColor(): string { return $this->secondaryColor; }
    public function setSecondaryColor(string $v): static { $this->secondaryColor = $v; return $this; }

    public function getStadiumName(): ?string { return $this->stadiumName; }
    public function setStadiumName(?string $v): static { $this->stadiumName = $v; return $this; }

    public function getBalance(): int { return $this->balance; }
    public function setBalance(int $v): static { $this->balance = $v; return $this; }

    public function getFacilities(): array { return $this->facilities; }
    public function setFacilities(array $v): static { $this->facilities = $v; return $this; }

    public function getFacilitiesJson(): string
    {
        return json_encode($this->facilities, JSON_PRETTY_PRINT) ?: '{}';
    }

    public function setFacilitiesJson(string $v): static
    {
        $this->facilities = json_decode($v, true) ?? [];
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getLeague(): ?League { return $this->league; }
    public function setLeague(?League $v): static { $this->league = $v; return $this; }
}
