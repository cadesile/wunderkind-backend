<?php

namespace App\Entity;

use App\Enum\StaffRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
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

    #[ORM\ManyToOne(inversedBy: 'staff')]
    #[ORM\JoinColumn(nullable: false)]
    private Academy $academy;

    #[ORM\Column]
    private \DateTimeImmutable $hiredAt;

    public function __construct(
        string $firstName,
        string $lastName,
        StaffRole $role,
        Academy $academy,
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

    public function getRole(): StaffRole { return $this->role; }
    public function setRole(StaffRole $role): void { $this->role = $role; }

    public function getCoachingAbility(): int { return $this->coachingAbility; }
    public function setCoachingAbility(int $v): void { $this->coachingAbility = max(1, min(100, $v)); }

    public function getScoutingRange(): int { return $this->scoutingRange; }
    public function setScoutingRange(int $v): void { $this->scoutingRange = max(1, min(100, $v)); }

    public function getWeeklySalary(): int { return $this->weeklySalary; }
    public function setWeeklySalary(int $salary): void { $this->weeklySalary = $salary; }

    public function getAcademy(): Academy { return $this->academy; }

    public function getHiredAt(): \DateTimeImmutable { return $this->hiredAt; }
}
