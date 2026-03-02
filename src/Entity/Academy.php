<?php

namespace App\Entity;

use App\Repository\AcademyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: AcademyRepository::class)]
class Academy
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $name;

    /** Academy Reputation score — drives Youth Requests recruitment pipeline */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $reputation = 0;

    /** Lifetime earnings in pence/cents (integer avoids float precision issues) */
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true, 'default' => 0])]
    private int $totalCareerEarnings = 0;

    /** Hall of Fame points accumulated across all careers */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $hallOfFamePoints = 0;

    /** Week number of the most recent valid sync (for anti-cheat) */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $lastSyncedWeek = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToOne(inversedBy: 'academy')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\OneToMany(mappedBy: 'academy', targetEntity: Player::class, cascade: ['persist', 'remove'])]
    private Collection $players;

    #[ORM\OneToMany(mappedBy: 'academy', targetEntity: Staff::class, cascade: ['persist', 'remove'])]
    private Collection $staff;

    #[ORM\OneToMany(mappedBy: 'academy', targetEntity: Transfer::class)]
    private Collection $transfers;

    #[ORM\OneToMany(mappedBy: 'academy', targetEntity: SyncRecord::class, cascade: ['persist', 'remove'])]
    private Collection $syncRecords;

    #[ORM\OneToMany(mappedBy: 'academy', targetEntity: LeaderboardEntry::class, cascade: ['persist', 'remove'])]
    private Collection $leaderboardEntries;

    public function __construct(string $name, User $user)
    {
        $this->id                 = new UuidV7();
        $this->name               = $name;
        $this->user               = $user;
        $this->createdAt          = new \DateTimeImmutable();
        $this->players            = new ArrayCollection();
        $this->staff              = new ArrayCollection();
        $this->transfers          = new ArrayCollection();
        $this->syncRecords        = new ArrayCollection();
        $this->leaderboardEntries = new ArrayCollection();
    }

    public function __toString(): string { return $this->name; }

    public function getId(): UuidV7 { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function getReputation(): int { return $this->reputation; }
    public function setReputation(int $reputation): void { $this->reputation = $reputation; }

    public function getTotalCareerEarnings(): int { return $this->totalCareerEarnings; }
    public function setTotalCareerEarnings(int $amount): void { $this->totalCareerEarnings = $amount; }

    public function getHallOfFamePoints(): int { return $this->hallOfFamePoints; }
    public function setHallOfFamePoints(int $points): void { $this->hallOfFamePoints = $points; }

    public function getLastSyncedWeek(): int { return $this->lastSyncedWeek; }
    public function setLastSyncedWeek(int $week): void { $this->lastSyncedWeek = $week; }

    public function getLastSyncedAt(): ?\DateTimeImmutable { return $this->lastSyncedAt; }
    public function setLastSyncedAt(?\DateTimeImmutable $at): void { $this->lastSyncedAt = $at; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUser(): User { return $this->user; }

    public function getPlayers(): Collection { return $this->players; }
    public function getStaff(): Collection { return $this->staff; }
    public function getTransfers(): Collection { return $this->transfers; }
    public function getSyncRecords(): Collection { return $this->syncRecords; }
    public function getLeaderboardEntries(): Collection { return $this->leaderboardEntries; }
}
