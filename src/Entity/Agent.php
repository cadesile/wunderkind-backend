<?php

namespace App\Entity;

use App\Repository\AgentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: AgentRepository::class)]
class Agent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $name;

    /** Reputation score influences deal quality and access to top players */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 50])]
    private int $reputation = 50;

    /** Commission rate as a percentage (e.g. 10 = 10%) */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $commissionRate = '10.00';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dob = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $nationality = null;

    #[ORM\Column(type: 'json')]
    private array $judgements = [];

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $experience = 0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $rating = 50;

    #[ORM\OneToMany(mappedBy: 'agent', targetEntity: Player::class)]
    private Collection $players;

    /** Avatar appearance (frontend Appearance shape). Null until generated/backfilled. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $appearance = null;

    public function __construct(string $name)
    {
        $this->id      = new UuidV7();
        $this->name    = $name;
        $this->players = new ArrayCollection();
    }

    public function __toString(): string { return $this->name; }

    public function getId(): UuidV7 { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    /**
     * Minimal agent shape nested under a player in snapshots — the same shape
     * ScoutSearchController::serializePlayer surfaces. Shared so the agent
     * serialization lives in one place.
     *
     * @return array{id: string, name: string, commissionRate: string}
     */
    public function toSnapshotArray(): array
    {
        return [
            'id'             => $this->id->toRfc4122(),
            'name'           => $this->name,
            'commissionRate' => $this->commissionRate,
        ];
    }

    public function getReputation(): int { return $this->reputation; }
    public function setReputation(int $reputation): void { $this->reputation = $reputation; }

    public function getCommissionRate(): string { return $this->commissionRate; }
    public function setCommissionRate(string $rate): void { $this->commissionRate = $rate; }

    public function getPlayers(): Collection { return $this->players; }

    public function getDob(): ?\DateTimeImmutable { return $this->dob; }
    public function setDob(?\DateTimeImmutable $dob): void { $this->dob = $dob; }

    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }

    public function getJudgements(): array { return $this->judgements; }
    public function setJudgements(array $judgements): void { $this->judgements = $judgements; }

    public function getExperience(): int { return $this->experience; }
    public function setExperience(int $experience): void { $this->experience = $experience; }

    public function getRating(): int { return $this->rating; }
    public function setRating(int $rating): void { $this->rating = $rating; }

    public function getAppearance(): ?array { return $this->appearance; }
    public function setAppearance(?array $appearance): void { $this->appearance = $appearance; }
}
