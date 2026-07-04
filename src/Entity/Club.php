<?php

namespace App\Entity;

use App\Enum\Formation;
use App\Repository\ClubRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: ClubRepository::class)]
class Club
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $name;

    /** Club Reputation score — drives Youth Requests recruitment pipeline */
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true, 'default' => 0])]
    private int $reputation = 0;

    /** Lifetime earnings in pence/cents (integer avoids float precision issues) */
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true, 'default' => 0])]
    private int $totalCareerEarnings = 0;

    /** Hall of Fame points accumulated across all careers */
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true, 'default' => 0])]
    private int $hallOfFamePoints = 0;

    /** Week number of the most recent valid sync (for anti-cheat) */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $lastSyncedWeek = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    /** Target market-pool size for dynamic replenishment (future use) */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 20])]
    private int $marketPoolSize = 20;

    /** Month number (1–12) at which the financial year starts. Default 4 = April (UK tax year). */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 4])]
    private int $financialYearStart = 4;

    /** ISO 3166-1 alpha-2 style country code for the club's home nation (e.g. 'EN', 'IT') */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $country = null;

    /** Short club abbreviation, up to 5 characters (e.g. 'MAN', 'BARCA') */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $abbreviation = null;

    /** Set once when POST /api/initialize succeeds. Guards re-initialization (409). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $worldInitializedAt = null;

    /** Set once when POST /api/initialize/starter succeeds. Guards re-initialization. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $starterInitializedAt = null;

    /** Set once when the client completes the first-launch tutorial. Survives reinstalls. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $tutorialCompletedAt = null;

    /** Player Agent / PA name assigned at club creation */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $paName = null;

    /** Manager personality trait: how hot or cold-tempered the manager is (0–100) */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $managerTemperament = 50;

    /** Manager personality trait: strictness in enforcing rules and standards (0–100) */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $managerDiscipline = 50;

    /** Manager personality trait: drive to win trophies and push players hard (0–100) */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $managerAmbition = 50;

    /** Club cash balance in pence/cents */
    #[ORM\Column(type: 'bigint')]
    private int $balance = 0;

    /**
     * Club manager profile: { name, dateOfBirth, gender, nationality }
     * Stored as JSON for schema flexibility.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $managerProfile = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(inversedBy: 'clubs')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\OneToMany(mappedBy: 'club', targetEntity: Transfer::class)]
    private Collection $transfers;

    #[ORM\OneToMany(mappedBy: 'club', targetEntity: SyncRecord::class, cascade: ['persist', 'remove'])]
    private Collection $syncRecords;

    #[ORM\OneToMany(mappedBy: 'club', targetEntity: LeaderboardEntry::class, cascade: ['persist', 'remove'])]
    private Collection $leaderboardEntries;

    #[ORM\OneToMany(mappedBy: 'club', targetEntity: Investor::class)]
    private Collection $investors;

    #[ORM\OneToMany(mappedBy: 'club', targetEntity: Sponsor::class)]
    private Collection $sponsors;

    #[ORM\OneToMany(mappedBy: 'club', targetEntity: InboxMessage::class, cascade: ['persist', 'remove'])]
    private Collection $inboxMessages;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?League $currentLeague = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $currentSeason = 1;

    #[ORM\Column(enumType: Formation::class, options: ['default' => '4-4-2'])]
    private Formation $formation = Formation::F_442;

    public function __construct(string $name, User $user)
    {
        $this->id                 = new UuidV7();
        $this->name               = $name;
        $this->user               = $user;
        $this->createdAt          = new \DateTimeImmutable();
        $this->transfers          = new ArrayCollection();
        $this->syncRecords        = new ArrayCollection();
        $this->leaderboardEntries = new ArrayCollection();
        $this->investors          = new ArrayCollection();
        $this->sponsors           = new ArrayCollection();
        $this->inboxMessages      = new ArrayCollection();
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

    public function getMarketPoolSize(): int { return $this->marketPoolSize; }
    public function setMarketPoolSize(int $size): void { $this->marketPoolSize = $size; }

    public function getFinancialYearStart(): int { return $this->financialYearStart; }
    public function setFinancialYearStart(int $month): void { $this->financialYearStart = $month; }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): void { $this->country = $country; }

    public function getAbbreviation(): ?string { return $this->abbreviation; }
    public function setAbbreviation(?string $abbreviation): void { $this->abbreviation = $abbreviation; }

    public function getWorldInitializedAt(): ?\DateTimeImmutable { return $this->worldInitializedAt; }
    public function setWorldInitializedAt(?\DateTimeImmutable $v): static { $this->worldInitializedAt = $v; return $this; }
    public function isWorldInitialized(): bool { return $this->worldInitializedAt !== null; }

    public function getStarterInitializedAt(): ?\DateTimeImmutable { return $this->starterInitializedAt; }
    public function setStarterInitializedAt(?\DateTimeImmutable $v): static { $this->starterInitializedAt = $v; return $this; }
    public function isStarterInitialized(): bool { return $this->starterInitializedAt !== null; }

    public function getTutorialCompletedAt(): ?\DateTimeImmutable { return $this->tutorialCompletedAt; }
    public function setTutorialCompletedAt(?\DateTimeImmutable $v): static { $this->tutorialCompletedAt = $v; return $this; }
    public function isTutorialCompleted(): bool { return $this->tutorialCompletedAt !== null; }

    public function getPaName(): ?string { return $this->paName; }
    public function setPaName(?string $paName): void { $this->paName = $paName; }

    public function getManagerTemperament(): int { return $this->managerTemperament; }
    public function setManagerTemperament(int $v): void { $this->managerTemperament = max(0, min(100, $v)); }

    public function getManagerDiscipline(): int { return $this->managerDiscipline; }
    public function setManagerDiscipline(int $v): void { $this->managerDiscipline = max(0, min(100, $v)); }

    public function getManagerAmbition(): int { return $this->managerAmbition; }
    public function setManagerAmbition(int $v): void { $this->managerAmbition = max(0, min(100, $v)); }

    public function getBalance(): int { return $this->balance; }
    public function setBalance(int $balance): void { $this->balance = $balance; }

    public function addFunds(int $amount): void { $this->balance += $amount; }

    public function deductFunds(int $amount): bool
    {
        if ($this->balance < $amount) {
            return false;
        }
        $this->balance -= $amount;
        return true;
    }

    public function hasDebt(): bool { return $this->balance < 0; }
    public function getDebtAmount(): int { return max(0, -$this->balance); }

    public function getManagerProfile(): ?array { return $this->managerProfile; }
    public function setManagerProfile(?array $profile): void { $this->managerProfile = $profile; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUser(): User { return $this->user; }

    public function getTransfers(): Collection { return $this->transfers; }
    public function getSyncRecords(): Collection { return $this->syncRecords; }
    public function getLeaderboardEntries(): Collection { return $this->leaderboardEntries; }
    public function getInvestors(): Collection { return $this->investors; }
    public function getSponsors(): Collection { return $this->sponsors; }
    public function getInboxMessages(): Collection { return $this->inboxMessages; }

    public function canAcceptInvestor(float $percentage): bool
    {
        $totalOwned = array_sum(
            $this->investors->map(fn (Investor $i) => $i->getPercentageOwned())->toArray()
        );
        return ($totalOwned + $percentage) < 50.0;
    }

    public function getActiveSponsors(): Collection
    {
        return $this->sponsors->filter(
            fn (Sponsor $s) => $s->getStatus() === \App\Enum\SponsorStatus::ACTIVE
        );
    }

    public function getMonthlyRevenue(): int
    {
        return array_sum(
            $this->getActiveSponsors()->map(fn (Sponsor $s) => $s->getMonthlyPayment())->toArray()
        );
    }

    public function calculateAnnualProfit(): int
    {
        // Real formula is client-side; server approximates from stored totals.
        return $this->totalCareerEarnings;
    }

    public function isFinancialYearEnd(int $currentWeek): bool
    {
        return $currentWeek % 52 === 0;
    }

    public function getCurrentLeague(): ?League { return $this->currentLeague; }
    public function setCurrentLeague(?League $v): static { $this->currentLeague = $v; return $this; }

    public function getCurrentSeason(): int { return $this->currentSeason; }
    public function setCurrentSeason(int $v): static { $this->currentSeason = $v; return $this; }

    public function getFormation(): Formation { return $this->formation; }
    public function setFormation(Formation $v): static { $this->formation = $v; return $this; }
}
