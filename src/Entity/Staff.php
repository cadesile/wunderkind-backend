<?php

namespace App\Entity;

use App\Enum\StaffRole;
use App\Repository\StaffRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: StaffRepository::class)]
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
     * Structured coaching specialisms.
     * Coaches: attribute keys (pace, technical, vision, power, stamina, heart) → int 50–90.
     * Managers: {"playingStyle": "POSSESSION|DIRECT|COUNTER|HIGH_PRESS", "formation": "4-4-2|4-3-3|4-2-3-1|3-5-2|5-3-2|4-5-1|5-4-1"}.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $specialisms = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dob = null;

    #[ORM\Column]
    private \DateTimeImmutable $hiredAt;

    /** Avatar appearance (frontend Appearance shape). Null until generated/backfilled. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $appearance = null;

    public function __construct(
        string $firstName = '',
        string $lastName = '',
        StaffRole $role = StaffRole::COACH,
    ) {
        $this->id        = new UuidV7();
        $this->firstName = $firstName;
        $this->lastName  = $lastName;
        $this->role      = $role;
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

    public function getDob(): ?\DateTimeImmutable { return $this->dob; }
    public function setDob(?\DateTimeImmutable $dob): void { $this->dob = $dob; }

    public function getHiredAt(): \DateTimeImmutable { return $this->hiredAt; }

    public function getAppearance(): ?array { return $this->appearance; }
    public function setAppearance(?array $appearance): void { $this->appearance = $appearance; }
}
