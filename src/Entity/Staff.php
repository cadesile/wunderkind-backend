<?php

namespace App\Entity;

use App\Enum\StaffRole;
use App\Repository\StaffRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: StaffRepository::class)]
#[ORM\Index(columns: ['academy_id'], name: 'idx_staff_academy')]
#[ORM\Index(columns: ['assigned_at'], name: 'idx_staff_assigned_at')]
class Staff
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(enumType: StaffRole::class)]
    private StaffRole $role;

    /**
     * General coaching ability (1–100).
     * Influences training effectiveness and Coaching Finds pipeline.
     */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $coachingAbility = 50;

    /**
     * How far the scout's network reaches (1–100).
     * Influences the Scouting Network recruitment pipeline.
     */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $scoutingRange = 50;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $weeklySalary = 0;

    /** Staff morale (0–100) */
    #[ORM\Column(type: 'integer')]
    private int $morale = 50;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $nationality = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $specialty = null;

    /**
     * Structured coaching specialisms, e.g. {"pace": 85, "technical": 70}.
     * Valid keys: pace, technical, vision, power, stamina, heart. Values 50–90.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $specialisms = null;

    #[ORM\ManyToOne(inversedBy: 'staff')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Academy $academy = null;

    /** Set when the staff member is assigned from the market pool to an academy. Used for 52-week lifecycle cleanup. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dob = null;

    #[ORM\Column]
    private \DateTimeImmutable $hiredAt;

    public function __construct(
        string $firstName = '',
        string $lastName = '',
        StaffRole $role = StaffRole::HEAD_COACH,
        ?Academy $academy = null,
    ) {
        $this->id        = new UuidV7();
        $this->firstName = $firstName;
        $this->lastName  = $lastName;
        $this->role      = $role;
        $this->academy   = $academy;
        $this->hiredAt   = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }

    public function getFullName(): string { return "{$this->firstName} {$this->lastName}"; }

    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }

    public function getRole(): StaffRole { return $this->role; }
    public function setRole(StaffRole $role): void { $this->role = $role; }
    public function getRoleValue(): string { return $this->role->value; }

    public function getCoachingAbility(): int { return $this->coachingAbility; }
    public function setCoachingAbility(int $v): void { $this->coachingAbility = max(1, min(100, $v)); }

    public function getScoutingRange(): int { return $this->scoutingRange; }
    public function setScoutingRange(int $v): void { $this->scoutingRange = max(1, min(100, $v)); }

    public function getWeeklySalary(): int { return $this->weeklySalary; }
    public function setWeeklySalary(int $salary): void { $this->weeklySalary = $salary; }

    public function getMorale(): int { return $this->morale; }
    public function setMorale(int $morale): void { $this->morale = max(0, min(100, $morale)); }

    public function getSpecialty(): ?string { return $this->specialty; }
    public function setSpecialty(?string $specialty): void { $this->specialty = $specialty; }

    public function getSpecialisms(): ?array { return $this->specialisms; }
    public function setSpecialisms(?array $specialisms): void { $this->specialisms = $specialisms; }

    /** Virtual property for admin form — serialises specialisms as a JSON string. */
    public function getSpecialismsJson(): string
    {
        return json_encode($this->specialisms ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function setSpecialismsJson(string $json): void
    {
        $decoded = json_decode($json, true);
        $this->specialisms = is_array($decoded) && !empty($decoded) ? $decoded : null;
    }

    public function isInMarketPool(): bool { return $this->academy === null; }

    public function getAcademy(): ?Academy { return $this->academy; }
    public function setAcademy(?Academy $academy): void { $this->academy = $academy; }

    public function getAssignedAt(): ?\DateTimeImmutable { return $this->assignedAt; }
    public function setAssignedAt(?\DateTimeImmutable $at): void { $this->assignedAt = $at; }
    public function isAssigned(): bool { return $this->assignedAt !== null; }

    public function getDob(): ?\DateTimeImmutable { return $this->dob; }
    public function setDob(?\DateTimeImmutable $dob): void { $this->dob = $dob; }

    public function getHiredAt(): \DateTimeImmutable { return $this->hiredAt; }
}
