<?php

namespace App\Entity;

use App\Enum\PlayerPosition;
use App\Enum\PlayerStatus;
use App\Enum\RecruitmentSource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Player
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateOfBirth;

    #[ORM\Column(length: 60)]
    private string $nationality;

    #[ORM\Column(enumType: PlayerPosition::class)]
    private PlayerPosition $position;

    #[ORM\Column(enumType: PlayerStatus::class)]
    private PlayerStatus $status = PlayerStatus::ACTIVE;

    #[ORM\Column(enumType: RecruitmentSource::class)]
    private RecruitmentSource $recruitmentSource;

    // Hidden server-side attributes (not exposed to client as raw numbers)
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $potential;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $currentAbility;

    /** Current contract value in pence/cents */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $contractValue = 0;

    #[ORM\Embedded(class: PersonalityProfile::class)]
    private PersonalityProfile $personality;

    #[ORM\ManyToOne(inversedBy: 'players')]
    #[ORM\JoinColumn(nullable: false)]
    private Academy $academy;

    #[ORM\OneToOne(mappedBy: 'player', cascade: ['persist', 'remove'])]
    private ?Guardian $guardian = null;

    #[ORM\ManyToOne(inversedBy: 'players')]
    private ?Agent $agent = null;

    /**
     * Siblings within the same academy — losing one sibling triggers
     * loyalty penalties for the remaining sibling(s).
     */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'player_siblings')]
    private Collection $siblings;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $firstName,
        string $lastName,
        \DateTimeImmutable $dateOfBirth,
        string $nationality,
        PlayerPosition $position,
        RecruitmentSource $recruitmentSource,
        int $potential,
        int $currentAbility,
        Academy $academy,
    ) {
        $this->id                = new UuidV7();
        $this->firstName         = $firstName;
        $this->lastName          = $lastName;
        $this->dateOfBirth       = $dateOfBirth;
        $this->nationality       = $nationality;
        $this->position          = $position;
        $this->recruitmentSource = $recruitmentSource;
        $this->potential         = $potential;
        $this->currentAbility    = $currentAbility;
        $this->academy           = $academy;
        $this->personality       = new PersonalityProfile();
        $this->siblings          = new ArrayCollection();
        $this->createdAt         = new \DateTimeImmutable();
        $this->updatedAt         = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }

    public function getFullName(): string { return "{$this->firstName} {$this->lastName}"; }

    public function getDateOfBirth(): \DateTimeImmutable { return $this->dateOfBirth; }

    public function getNationality(): string { return $this->nationality; }
    public function setNationality(string $nationality): void { $this->nationality = $nationality; }

    public function getPosition(): PlayerPosition { return $this->position; }
    public function setPosition(PlayerPosition $position): void { $this->position = $position; }
    public function getPositionValue(): string { return $this->position->value; }

    public function getStatus(): PlayerStatus { return $this->status; }
    public function setStatus(PlayerStatus $status): void { $this->status = $status; }
    public function getStatusValue(): string { return $this->status->value; }

    public function getRecruitmentSource(): RecruitmentSource { return $this->recruitmentSource; }

    public function getPotential(): int { return $this->potential; }
    public function setPotential(int $potential): void { $this->potential = $potential; }

    public function getCurrentAbility(): int { return $this->currentAbility; }
    public function setCurrentAbility(int $ability): void { $this->currentAbility = $ability; }

    public function getContractValue(): int { return $this->contractValue; }
    public function setContractValue(int $value): void { $this->contractValue = $value; }

    public function getPersonality(): PersonalityProfile { return $this->personality; }

    public function getAcademy(): Academy { return $this->academy; }
    public function setAcademy(Academy $academy): void { $this->academy = $academy; }

    public function getGuardian(): ?Guardian { return $this->guardian; }
    public function setGuardian(?Guardian $guardian): void { $this->guardian = $guardian; }

    public function getAgent(): ?Agent { return $this->agent; }
    public function setAgent(?Agent $agent): void { $this->agent = $agent; }

    public function getSiblings(): Collection { return $this->siblings; }

    public function addSibling(Player $sibling): void
    {
        if (!$this->siblings->contains($sibling)) {
            $this->siblings->add($sibling);
            $sibling->addSibling($this);
        }
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
