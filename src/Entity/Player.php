<?php

namespace App\Entity;

use App\Enum\PlayerPosition;
use App\Enum\PlayerStatus;
use App\Enum\RecruitmentSource;
use App\Repository\PlayerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: PlayerRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['academy_id'], name: 'idx_player_academy')]
#[ORM\Index(columns: ['assigned_at'], name: 'idx_player_assigned_at')]
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
    #[ORM\JoinColumn(nullable: true)]
    private ?Academy $academy = null;

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

    // ── Granular attributes (0–100 each) ──────────────────────────────────────
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $pace = 0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $technical = 0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $vision = 0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $power = 0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $stamina = 0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $heart = 0;

    // ── Physical measurements ──────────────────────────────────────────────────
    /** Height in centimetres */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $height = 0;

    /** Weight in kilograms */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $weight = 0;

    /** Player morale (0–100) */
    #[ORM\Column(type: 'integer')]
    private int $morale = 50;

    #[ORM\Column(options: ['default' => false])]
    private bool $ageOutWarningIssued = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $forcedSaleExecuted = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $forcedSaleWeek = null;

    /** Set when the player is assigned from the market pool to an academy. Used for 52-week lifecycle cleanup. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $firstName = '',
        string $lastName = '',
        \DateTimeImmutable $dateOfBirth = new \DateTimeImmutable(),
        string $nationality = '',
        PlayerPosition $position = PlayerPosition::MIDFIELDER,
        RecruitmentSource $recruitmentSource = RecruitmentSource::SCOUTING_NETWORK,
        int $potential = 0,
        int $currentAbility = 0,
        ?Academy $academy = null,
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
    public function setDateOfBirth(\DateTimeImmutable $dob): void { $this->dateOfBirth = $dob; }

    public function getNationality(): string { return $this->nationality; }
    public function setNationality(string $nationality): void { $this->nationality = $nationality; }

    public function getPosition(): PlayerPosition { return $this->position; }
    public function setPosition(PlayerPosition $position): void { $this->position = $position; }
    public function getPositionValue(): string { return $this->position->value; }

    public function getStatus(): PlayerStatus { return $this->status; }
    public function setStatus(PlayerStatus $status): void { $this->status = $status; }
    public function getStatusValue(): string { return $this->status->value; }

    public function getRecruitmentSource(): RecruitmentSource { return $this->recruitmentSource; }
    public function setRecruitmentSource(RecruitmentSource $source): void { $this->recruitmentSource = $source; }

    public function getPotential(): int { return $this->potential; }
    public function setPotential(int $potential): void { $this->potential = $potential; }

    public function getCurrentAbility(): int { return $this->currentAbility; }
    public function setCurrentAbility(int $ability): void { $this->currentAbility = $ability; }

    public function getContractValue(): int { return $this->contractValue; }
    public function setContractValue(int $value): void { $this->contractValue = $value; }

    public function getMorale(): int { return $this->morale; }
    public function setMorale(int $morale): void { $this->morale = max(0, min(100, $morale)); }

    // ── Attribute getters/setters ──────────────────────────────────────────────
    public function getPace(): int { return $this->pace; }
    public function setPace(int $v): void { $this->pace = max(0, min(100, $v)); }

    public function getTechnical(): int { return $this->technical; }
    public function setTechnical(int $v): void { $this->technical = max(0, min(100, $v)); }

    public function getVision(): int { return $this->vision; }
    public function setVision(int $v): void { $this->vision = max(0, min(100, $v)); }

    public function getPower(): int { return $this->power; }
    public function setPower(int $v): void { $this->power = max(0, min(100, $v)); }

    public function getStamina(): int { return $this->stamina; }
    public function setStamina(int $v): void { $this->stamina = max(0, min(100, $v)); }

    public function getHeart(): int { return $this->heart; }
    public function setHeart(int $v): void { $this->heart = max(0, min(100, $v)); }

    /** Calculated overall: average of the 6 attributes. */
    public function getOverall(): int
    {
        return (int) round(($this->pace + $this->technical + $this->vision + $this->power + $this->stamina + $this->heart) / 6);
    }

    public function getHeight(): int { return $this->height; }
    public function setHeight(int $cm): void { $this->height = max(0, $cm); }

    public function getWeight(): int { return $this->weight; }
    public function setWeight(int $kg): void { $this->weight = max(0, $kg); }

    public function getPersonality(): PersonalityProfile { return $this->personality; }

    public function isInMarketPool(): bool { return $this->academy === null; }

    public function getAcademy(): ?Academy { return $this->academy; }
    public function setAcademy(?Academy $academy): void { $this->academy = $academy; }

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

    public function isAgeOutWarningIssued(): bool { return $this->ageOutWarningIssued; }
    public function setAgeOutWarningIssued(bool $issued): void { $this->ageOutWarningIssued = $issued; }

    public function isForcedSaleExecuted(): bool { return $this->forcedSaleExecuted; }
    public function setForcedSaleExecuted(bool $executed): void { $this->forcedSaleExecuted = $executed; }

    public function getForcedSaleWeek(): ?int { return $this->forcedSaleWeek; }
    public function setForcedSaleWeek(?int $week): void { $this->forcedSaleWeek = $week; }

    public function getWeeksUntil21(int $currentWeek): int
    {
        if ($this->forcedSaleWeek === null) {
            return PHP_INT_MAX;
        }
        return max(0, $this->forcedSaleWeek - $currentWeek);
    }

    public function getAssignedAt(): ?\DateTimeImmutable { return $this->assignedAt; }
    public function setAssignedAt(?\DateTimeImmutable $at): void { $this->assignedAt = $at; }
    public function isAssigned(): bool { return $this->assignedAt !== null; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
