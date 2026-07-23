# Doctrine Entity Definitions

#### `Admin`

> **Purpose:** separate admin user entity (`UserInterface`); `email`, `password`, `name`, `department`, `accessLevel`; always `ROLE_ADMIN`; created via `app:admin:create`
```php
private UuidV7 $id;
    private string $email;
    private string $password;
    private ?string $name = null;
    private ?string $department = null;
    private int $accessLevel = 1;
    private \DateTimeImmutable $createdAt;
    public function __construct(string $email)
    public function getId(): UuidV7 { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
    public function getUserIdentifier(): string { return $this->email; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }
    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): void { $this->name = $name; }
    public function getDepartment(): ?string { return $this->department; }
    public function setDepartment(?string $department): void { $this->department = $department; }
    public function getAccessLevel(): int { return $this->accessLevel; }
    public function setAccessLevel(int $accessLevel): void { $this->accessLevel = $accessLevel; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
```

#### `Agent`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private string $name;
    private int $reputation = 50;
    private string $commissionRate = '10.00';
    private ?\DateTimeImmutable $dob = null;
    private ?string $nationality = null;
    private array $judgements = [];
    private int $experience = 0;
    private int $rating = 50;
    private Collection $players;
    private ?array $appearance = null;
    public function __construct(string $name)
    public function __toString(): string { return $this->name; }
    public function getId(): UuidV7 { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getReputation(): int { return $this->reputation; }
    public function setReputation(int $reputation): void { $this->reputation = $reputation; }
    public function getCommissionRate(): string { return $this->commissionRate; }
    public function setCommissionRate(string $rate): void { $this->commissionRate = $rate; }
    public function getPlayers(): Collection { return $this->players; }
    public function getDob(): ?\DateTimeImmutable { return $this->dob; }
    public function setDob(?\DateTimeImmutable $dob): void { $this->dob = $dob; }
    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }
```

#### `BetaRequest`

> **Purpose:** beta-access waitlist entry; `email`, `code`, `valid`, `attempts`, `expiresAt`, `verifiedAt`; verified via `/api/beta-request/verify`
```php
private UuidV7 $id;
    private string $email;
    private string $code;
    private bool $valid = false;
    private int $attempts = 0;
    private \DateTimeImmutable $expiresAt;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $verifiedAt = null;
    public function __construct(string $email, string $code)
    public function getId(): UuidV7 { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getCode(): string { return $this->code; }
    public function isValid(): bool { return $this->valid; }
    public function getAttempts(): int { return $this->attempts; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function incrementAttempts(): void { $this->attempts++; }
    public function isExpired(): bool { return $this->expiresAt <= new \DateTimeImmutable(); }
    public function isLockedOut(): bool { return $this->attempts >= 3; }
    public function expire(): void { $this->expiresAt = new \DateTimeImmutable(); }
```

#### `Club`

> **Purpose:** `reputation`, `totalCareerEarnings`, `hallOfFamePoints`, `lastSyncedWeek`, manager traits (`temperament`/`discipline`/`ambition` 0–100 clamped setters), `paName`, `financialYearStart`, `balance`, `country`, `abbreviation`
```php
private UuidV7 $id;
    private string $name;
    private int $reputation = 0;
    private int $totalCareerEarnings = 0;
    private int $hallOfFamePoints = 0;
    private int $lastSyncedWeek = 0;
    private ?\DateTimeImmutable $lastSyncedAt = null;
    private int $marketPoolSize = 20;
    private int $financialYearStart = 4;
    private ?string $country = null;
    private ?string $abbreviation = null;
    private ?\DateTimeImmutable $worldInitializedAt = null;
    private ?\DateTimeImmutable $starterInitializedAt = null;
    private ?\DateTimeImmutable $tutorialCompletedAt = null;
    private ?string $paName = null;
    private int $managerTemperament = 50;
    private int $managerDiscipline = 50;
    private int $managerAmbition = 50;
    private int $balance = 0;
    private ?array $managerProfile = null;
    private \DateTimeImmutable $createdAt;
    private User $user;
    private Collection $transfers;
    private Collection $syncRecords;
    private Collection $leaderboardEntries;
```

> **Field note:** **`hallOfFamePoints`** is `max(current, incoming)` — never decreases. **`reputation`** floors at 0. **`totalCareerEarnings`** adds deltas.

#### `CountryWorldPackCache`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private string $country;
    private int $tier;
    private array $payload;
    private \DateTimeImmutable $generatedAt;
    public function __construct(string $country, int $tier, array $payload)
    public function getId(): UuidV7 { return $this->id; }
    public function getCountry(): string { return $this->country; }
    public function getTier(): int { return $this->tier; }
    public function getPayload(): array { return $this->payload; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
```

#### `EmailVerification`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private User $user;
    private string $code;
    private \DateTimeImmutable $expiresAt;
    private VerificationPurpose $purpose;
    private int $attempts = 0;
    private ?\DateTimeImmutable $verifiedAt = null;
    private \DateTimeImmutable $createdAt;
    public function __construct(User $user, string $code, VerificationPurpose $purpose = VerificationPurpose::REGISTRATION)
    public function getId(): UuidV7 { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getCode(): string { return $this->code; }
    public function getPurpose(): VerificationPurpose { return $this->purpose; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): void { $this->expiresAt = $expiresAt; }
    public function getAttempts(): int { return $this->attempts; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
```

#### `FacilityTemplate`

> **Purpose:** canonical slug shared with frontend; `category` (TRAINING/MEDICAL/SCOUTING), `baseCost`, `weeklyUpkeepBase`, `matchdayIncome`, `matchdayIncomeMultiplier`; seeded via admin
```php
private Uuid $id;
    private string $slug;
    private string $label;
    private string $description;
    private string $category;
    private int $baseCost;
    private int $weeklyUpkeepBase = 0;
    private ?int $matchdayIncome = null;
    private ?float $matchdayIncomeMultiplier = null;
    private float $reputationBonus = 0.0;
    private int $maxLevel = 5;
    private float $decayBase = 2.0;
     * @var array<string, float>
    private array $gameplayEffects = [];
    private int $baseConstructionWeeks = 4;
    private int $sortOrder = 0;
    private bool $isActive = true;
    private \DateTimeImmutable $updatedAt;
    public function getId(): Uuid { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): void { $this->label = $label; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): void { $this->description = $description; }
```

#### `GameConfig`

> **Purpose:** singleton row; all global gameplay constants (XP rates, injury chances, wage multipliers, attendance formulas, etc.)
```php
private ?int $id = null;
    private int $cliqueRelationshipThreshold = 20;
    private int $cliqueSquadCapPercent = 30;
    private int $cliqueMinTenureWeeks = 3;
    private int $baseXP = 10;
    private float $baseInjuryProbability = 0.05;
    private int $regressionUpperThreshold = 14;
    private int $regressionLowerThreshold = 7;
    private float $reputationDeltaBase = 0.15;
    private float $reputationDeltaFacilityMultiplier = 0.15;
    private int $injuryMinorWeight = 60;
    private int $injuryModerateWeight = 30;
    private int $injurySeriousWeight = 10;
    private float $potentialOvershootMax = 0.05;
    private float $potentialDecayRate = 0.5;
    private float $coachDevelopmentMaxMultiplier = 2.0;
    private int $coachDevelopmentMinSpecialism = 20;
    private float $coachDevelopmentStackingFactor = 0.3;
    private float $coachMoraleInfluence = 0.5;
    private int $attributeHardCap = 98;
    private int $physicalDegradationAgeThreshold = 30;
    private float $physicalDegradationRateMild = 0.1;
    private float $physicalDegradationRateModerate = 0.2;
    private float $physicalDegradationRateSevere = 0.4;
    private float $physicalDegradationPersonalityScale = 0.2;
```

#### `GameEventTemplate`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private string $slug;
    private EventCategory $category;
    private int $weight = 1;
    private string $title;
    private string $bodyTemplate;
     * @var array<int, array<string, mixed>>
    private array $impacts = [];
    private ?array $firingConditions = null;
    private ?string $severity = null;
    private bool $noInteract = false;
     * @var array<int, array<string, mixed>>|null
    private ?array $chainedEvents = null;
    private \DateTimeImmutable $createdAt;
    public function getId(): UuidV7 { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }
    public function getCategory(): EventCategory { return $this->category; }
    public function setCategory(EventCategory|string $category): void
    public function getWeight(): int { return $this->weight; }
    public function setWeight(int $weight): void { $this->weight = max(0, $weight); }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function getBodyTemplate(): string { return $this->bodyTemplate; }
    public function setBodyTemplate(string $bodyTemplate): void { $this->bodyTemplate = $bodyTemplate; }
```

#### `Guardian`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private string $firstName;
    private string $lastName;
    private string $gender = 'male';
    private ?\DateTimeImmutable $dateOfBirth = null;
    private ?string $contactEmail = null;
    private int $demandLevel = 5;
    private int $loyaltyToClub = 50;
    private Player $player;
    public function __construct(string $firstName, string $lastName, Player $player, string $gender = 'male')
    public function getId(): UuidV7 { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }
    public function getFullName(): string { return "{$this->firstName} {$this->lastName}"; }
    public function __toString(): string { return $this->getFullName(); }
    public function getGender(): string { return $this->gender; }
    public function setGender(string $gender): void { $this->gender = $gender; }
    public function getDateOfBirth(): ?\DateTimeImmutable { return $this->dateOfBirth; }
    public function setDateOfBirth(?\DateTimeImmutable $dob): void { $this->dateOfBirth = $dob; }
    public function getContactEmail(): ?string { return $this->contactEmail; }
    public function setContactEmail(?string $email): void { $this->contactEmail = $email; }
    public function getDemandLevel(): int { return $this->demandLevel; }
    public function setDemandLevel(int $level): void { $this->demandLevel = max(1, min(10, $level)); }
```

#### `InboxMessage`

> **Purpose:** `senderType` (MessageSenderType), `offerData` (json), `status` (MessageStatus)
```php
private UuidV7 $id;
    private Club $club;
    private MessageSenderType $senderType;
    private string $senderName;
    private string $subject;
    private string $body;
    private ?array $offerData = null;
    private MessageStatus $status = MessageStatus::UNREAD;
    private ?string $relatedEntityType = null;
    private ?string $relatedEntityId = null;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $respondedAt = null;
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getSenderType(): MessageSenderType { return $this->senderType; }
    public function getSenderName(): string { return $this->senderName; }
    public function getSubject(): string { return $this->subject; }
    public function getBody(): string { return $this->body; }
    public function getOfferData(): ?array { return $this->offerData; }
    public function setOfferData(?array $offerData): void { $this->offerData = $offerData; }
    public function getStatus(): MessageStatus { return $this->status; }
    public function getRelatedEntityType(): ?string { return $this->relatedEntityType; }
    public function setRelatedEntityType(?string $type): void { $this->relatedEntityType = $type; }
    public function getRelatedEntityId(): ?string { return $this->relatedEntityId; }
    public function setRelatedEntityId(?string $id): void { $this->relatedEntityId = $id; }
```

#### `Investor`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private string $company;
    private ?string $nationality = null;
    private CompanySize $size = CompanySize::MEDIUM;
    private bool $isActive = true;
    private ?Club $club = null;
    private \DateTimeImmutable $createdAt;
    private InvestorTier $tier = InvestorTier::ANGEL;
    private int $investmentAmount = 0;
    private string $percentageOwned = '5.00';
    private ?\DateTimeImmutable $assignedAt = null;
    private ?\DateTimeImmutable $investedAt = null;
    private ?\DateTimeImmutable $lastPayoutAt = null;
    public function __construct(string $company = '')
    public function getId(): UuidV7 { return $this->id; }
    public function getCompany(): string { return $this->company; }
    public function setCompany(string $company): void { $this->company = $company; }
    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }
    public function getSize(): CompanySize { return $this->size; }
    public function setSize(CompanySize $size): void { $this->size = $size; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): void { $this->isActive = $isActive; }
    public function isInMarketPool(): bool { return $this->club === null; }
    public function getClub(): ?Club { return $this->club; }
```

#### `LeaderboardEntry`

> **Purpose:** UNIQUE(club, category, period); `rank_position` column (not `rank`)
```php
private UuidV7 $id;
    private Club $club;
    private LeaderboardCategory $category;
    private int $score = 0;
    private string $period;
    private ?int $rank = null;
    private \DateTimeImmutable $updatedAt;
    public function __construct(Club $club, LeaderboardCategory $category, string $period)
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getCategory(): LeaderboardCategory { return $this->category; }
    public function getCategoryValue(): string { return $this->category->value; }
    public function getPeriod(): string { return $this->period; }
    public function getScore(): int { return $this->score; }
    public function setScore(int $score): void
    public function getRank(): ?int { return $this->rank; }
    public function setRank(?int $rank): void { $this->rank = $rank; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
```

> **Field note:** **`rank`** is a reserved SQL word — `LeaderboardEntry` uses column name `rank_position`.

#### `League`

> **Purpose:** `country`, `tier` (1–8), `promotionSpots`, `tvDeal`, `prizeMoney`, `leaguePositionPot`, `sponsorCount`; has `LeagueSponsor` collection
```php
private UuidV7 $id;
    private string $country;
    private int $tier;
    private string $name;
    private ?int $promotionSpots = null;
    private ?int $tvDeal = null;
    private ?ReputationTier $leagueReputationTier = null;
    private ?int $prizeMoney = null;
    private ?int $leaguePositionPot = null;
    private int $sponsorCount = 0;
    private ?string $trophyImage = null;
    private ?TrophyColour $trophyColour = null;
    private Collection $leagueSponsors;
    private Collection $sponsors;
    private \DateTimeImmutable $createdAt;
    public function __construct(string $country, int $tier, string $name)
    public function getId(): UuidV7 { return $this->id; }
    public function getCountry(): string { return $this->country; }
    public function setCountry(string $v): static { $this->country = $v; return $this; }
    public function getTier(): int { return $this->tier; }
    public function setTier(int $v): static { $this->tier = $v; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getPromotionSpots(): ?int { return $this->promotionSpots; }
    public function setPromotionSpots(?int $v): static { $this->promotionSpots = $v; return $this; }
```

#### `LeagueSponsor`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private League $league;
    private Sponsor $sponsor;
    private int $rolledValue = 0;
    public function __construct(League $league, Sponsor $sponsor, int $rolledValue = 0)
    public function getLeague(): League { return $this->league; }
    public function getSponsor(): Sponsor { return $this->sponsor; }
    public function getRolledValue(): int { return $this->rolledValue; }
    public function setRolledValue(int $v): static { $this->rolledValue = $v; return $this; }
```

#### `MatchResult`

> **Purpose:** per-club match record; `goalsFor`, `goalsAgainst`, `week`, `season`, `fixtureId` (unique), `opponentClubName`, `isHome`, `homeGoals`, `awayGoals`, `round`, `playedAt`, `yellowCards`; FK to `Club`
```php
private UuidV7 $id;
    private Club $club;
    private int $goalsFor;
    private int $goalsAgainst;
    private int $week;
    private int $season;
    private ?string $fixtureId = null;
    private ?string $opponentClubName = null;
    private ?bool $isHome = null;
    private ?int $homeGoals = null;
    private ?int $awayGoals = null;
    private ?int $round = null;
    private ?\DateTimeImmutable $playedAt = null;
    private int $yellowCards = 0;
    private int $redCards = 0;
    private \DateTimeImmutable $createdAt;
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getGoalsFor(): int { return $this->goalsFor; }
    public function getGoalsAgainst(): int { return $this->goalsAgainst; }
    public function getWeek(): int { return $this->week; }
    public function getSeason(): int { return $this->season; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getFixtureId(): ?string { return $this->fixtureId; }
    public function setFixtureId(?string $v): static { $this->fixtureId = $v; return $this; }
```

#### `NpcClub`

> **Purpose:** `country`, `tier`, `reputation`, `balance`, `stadiumName`, `primaryColor`/`secondaryColor`, `playingStyle`, `financialApproach`; grouped into leagues for the world pack
```php
private UuidV7 $id;
    private string $name;
    private string $country;
    private int $tier;
    private int $reputation;
    private string $primaryColor;
    private string $secondaryColor;
    private ?string $abbreviation = null;
    private ?string $stadiumName = null;
    private int $balance;
    private string $playingStyle = 'DIRECT';
    private string $financialApproach = 'BALANCED';
    private int $managerTemperament = 50;
    private array $facilities;
    private \DateTimeImmutable $createdAt;
    private ?League $league = null;
    private Formation $formation = Formation::F_442;
    public function getId(): UuidV7 { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getCountry(): string { return $this->country; }
    public function setCountry(string $v): static { $this->country = $v; return $this; }
    public function getTier(): int { return $this->tier; }
    public function setTier(int $v): static { $this->tier = $v; return $this; }
    public function getReputation(): int { return $this->reputation; }
```

#### `PersonalityProfile`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private int $determination = 10;
    private int $professionalism = 10;
    private int $ambition = 10;
    private int $loyalty = 10;
    private int $adaptability = 10;
    private int $pressure = 10;
    private int $temperament = 10;
    private int $consistency = 10;
    public function getDetermination(): int { return $this->determination; }
    public function setDetermination(int $v): void { $this->determination = $this->clamp($v); }
    public function getProfessionalism(): int { return $this->professionalism; }
    public function setProfessionalism(int $v): void { $this->professionalism = $this->clamp($v); }
    public function getAmbition(): int { return $this->ambition; }
    public function setAmbition(int $v): void { $this->ambition = $this->clamp($v); }
    public function getLoyalty(): int { return $this->loyalty; }
    public function setLoyalty(int $v): void { $this->loyalty = $this->clamp($v); }
    public function getAdaptability(): int { return $this->adaptability; }
    public function setAdaptability(int $v): void { $this->adaptability = $this->clamp($v); }
    public function getPressure(): int { return $this->pressure; }
    public function setPressure(int $v): void { $this->pressure = $this->clamp($v); }
    public function getTemperament(): int { return $this->temperament; }
    public function setTemperament(int $v): void { $this->temperament = $this->clamp($v); }
    public function getConsistency(): int { return $this->consistency; }
    public function setConsistency(int $v): void { $this->consistency = $this->clamp($v); }
    private function clamp(int $v): int
```

#### `Player`

> **Purpose:** `position` (PlayerPosition), `status` (PlayerStatus), `recruitmentSource`, `currentAbility`, `potential` (hard-capped, `currentAbility ≤ potential`); embeds `PersonalityProfile` (8 traits 0–100); ManyToMany self-ref siblings; nullable `?Agent $agent` FK (many players → one agent; assigned in `MarketPoolService` and reassigned at world-pack generation; surfaced in every player snapshot — see Player↔Agent Association); `appearance` json (see Avatar Appearance). **No club FK** — pool entity, deleted on consume.
```php
private UuidV7 $id;
    private string $firstName;
    private string $lastName;
    private \DateTimeImmutable $dateOfBirth;
    private string $nationality;
    private PlayerPosition $position;
    private PlayerStatus $status = PlayerStatus::ACTIVE;
    private RecruitmentSource $recruitmentSource;
    private int $potential;
    private int $currentAbility;
    private int $contractValue = 0;
    private PersonalityProfile $personality;
    private Collection $guardians;
    private ?Agent $agent = null;
    private Collection $siblings;
    private int $pace = 0;
    private int $technical = 0;
    private int $vision = 0;
    private int $power = 0;
    private int $stamina = 0;
    private int $heart = 0;
    private int $height = 0;
    private int $weight = 0;
    private int $morale = 50;
    private \DateTimeImmutable $createdAt;
```

#### `PlayerArchetype`

> **Purpose:** defines trait mapping distributions used by `PlayerGenerationService`; `traitMapping` (json); seeded via `app:seed-archetypes`
```php
private ?int $id = null;
    private string $name;
    private string $description;
     * @var array<string, mixed>
    private array $traitMapping = [];
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): void { $this->description = $description; }
    public function getTraitMapping(): array { return $this->traitMapping; }
    public function setTraitMapping(array $traitMapping): void { $this->traitMapping = $traitMapping; }
    public function setTraitMappingJson(string $json): void
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
```

> **Field note:** **Doctrine JSON dirty-check** — when a `json` column stores mixed PHP string/int types, Doctrine silently skips the UPDATE. Bypass with:

#### `PoolConfig`

> **Purpose:** per-country/tier configuration for how many entities to pre-warm in the pool
```php
private ?int $id = null;
    private int $playerAgeMin = 12;
    private int $playerAgeMax = 13;
    private int $playerPotentialMin = 40;
    private int $playerPotentialMax = 80;
    private int $playerPotentialMean = 60;
    private int $playerAbilityMin = 3;
    private int $playerAbilityMax = 10;
    private int $playerAttributeBudgetMin = 6;
    private int $playerAttributeBudgetMax = 20;
    private int $playerAgentChancePercent = 40;
    private int $playerHeightMin = 145;
    private int $playerHeightMax = 160;
    private int $playerWeightMin = 38;
    private int $playerWeightMax = 55;
    private int $personalityTraitMin = 30;
    private int $personalityTraitMax = 70;
    private int $positionWeightGk = 8;
    private int $positionWeightDef = 30;
    private int $positionWeightMid = 38;
    private int $positionWeightAtt = 24;
    private int $coachAgeMin = 28;
    private int $coachAgeMax = 60;
    private int $coachAbilityMin = 40;
    private int $coachAbilityMax = 75;
```

#### `Scout`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private string $name;
    private ?\DateTimeImmutable $dob = null;
    private ?string $nationality = null;
    private array $judgements = [];
    private int $experience = 0;
    private \DateTimeImmutable $createdAt;
    private ?array $appearance = null;
    public function __construct(string $name = '')
    public function getId(): UuidV7 { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getDob(): ?\DateTimeImmutable { return $this->dob; }
    public function setDob(?\DateTimeImmutable $dob): void { $this->dob = $dob; }
    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }
    public function getJudgements(): array { return $this->judgements; }
    public function setJudgements(array $judgements): void { $this->judgements = $judgements; }
    public function setJudgementsJson(?string $json): void
    public function getExperience(): int { return $this->experience; }
    public function setExperience(int $experience): void { $this->experience = $experience; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getAppearance(): ?array { return $this->appearance; }
    public function setAppearance(?array $appearance): void { $this->appearance = $appearance; }
```

> **Field note:** **EasyAdmin custom form type on a `json`/array column** — a `Field::new('col')->setFormType(MyType::class)` where `col` is a Doctrine `json` type gets auto-configured by EasyAdmin as a collection, which injects `CollectionType` options (`allow_add`, `entry_type`, …) onto your form type and throws `The options ... do not exist`. Tolerate them in the type's `configureOptions()`: `$resolver->setDefined(['allow_add','allow_delete','delete_empty','entry_options','entry_type'])`. To render a fully custom widget for such a compound type, register a form theme via `$crud->addFormTheme(...)` (singular) and define a `{% block <blockPrefix>_widget %}` block (block prefix = the type class minus `Type`, snake_cased; `AppearanceType` → `appearance`). See `AppearanceType` + `templates/admin/form/appearance_theme.html.twig`.

#### `SeasonRatingsSnapshot`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private int $season;
    private int $weekNum;
    private int $tier;
    private string $clubId;
    private string $clubName;
    private int $overallRating;
    private int $expectedPosition;
    private \DateTimeImmutable $createdAt;
    public function getId(): UuidV7 { return $this->id; }
    public function getSeason(): int { return $this->season; }
    public function getWeekNum(): int { return $this->weekNum; }
    public function getTier(): int { return $this->tier; }
    public function getClubId(): string { return $this->clubId; }
    public function getClubName(): string { return $this->clubName; }
    public function getOverallRating(): int { return $this->overallRating; }
    public function getExpectedPosition(): int { return $this->expectedPosition; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
```

#### `SeasonRecord`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private Club $club;
    private League $league;
    private int $season;
    private int $finalPosition;
    private int $gamesPlayed;
    private int $wins;
    private int $draws;
    private int $losses;
    private int $goalsFor;
    private int $goalsAgainst;
    private int $points;
    private bool $promoted;
    private bool $relegated;
    private \DateTimeImmutable $createdAt;
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getLeague(): League { return $this->league; }
    public function getSeason(): int { return $this->season; }
    public function getFinalPosition(): int { return $this->finalPosition; }
    public function getGamesPlayed(): int { return $this->gamesPlayed; }
    public function getWins(): int { return $this->wins; }
    public function getDraws(): int { return $this->draws; }
    public function getLosses(): int { return $this->losses; }
    public function getGoalsFor(): int { return $this->goalsFor; }
```

#### `SeasonSnapshot`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private Club $club;
    private int $season;
    private string $country;
    private array $snapshotData;
    private \DateTimeImmutable $createdAt;
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getSeason(): int { return $this->season; }
    public function getCountry(): string { return $this->country; }
    public function getSnapshotData(): array { return $this->snapshotData; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
```

#### `SocialAccountConnection`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private SocialPlatform $platform;
    private string $displayName;
    private string $externalAccountId;
    private string $accessToken;
    private ?string $refreshToken = null;
    private ?\DateTimeImmutable $tokenExpiresAt = null;
    private bool $isActive = true;
    private \DateTimeImmutable $connectedAt;
    private ?\DateTimeImmutable $lastRefreshedAt = null;
    public function getId(): UuidV7 { return $this->id; }
    public function getPlatform(): SocialPlatform { return $this->platform; }
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $v): static { $this->displayName = $v; return $this; }
    public function getExternalAccountId(): string { return $this->externalAccountId; }
    public function getAccessToken(): string { return $this->accessToken; }
    public function setAccessToken(string $v): static { $this->accessToken = $v; return $this; }
    public function getRefreshToken(): ?string { return $this->refreshToken; }
    public function setRefreshToken(?string $v): static { $this->refreshToken = $v; return $this; }
    public function getTokenExpiresAt(): ?\DateTimeImmutable { return $this->tokenExpiresAt; }
    public function setTokenExpiresAt(?\DateTimeImmutable $v): static { $this->tokenExpiresAt = $v; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
    public function getConnectedAt(): \DateTimeImmutable { return $this->connectedAt; }
    public function getLastRefreshedAt(): ?\DateTimeImmutable { return $this->lastRefreshedAt; }
```

#### `SocialPostTemplate`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private StatCategory $category;
    private SocialPlatform $platform;
    private StatsPeriod $period;
    private string $bodyTemplate;
    private bool $isActive = true;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    public function getId(): UuidV7 { return $this->id; }
    public function getCategory(): StatCategory { return $this->category; }
    public function setCategory(StatCategory $v): static { $this->category = $v; $this->touch(); return $this; }
    public function getPlatform(): SocialPlatform { return $this->platform; }
    public function setPlatform(SocialPlatform $v): static { $this->platform = $v; $this->touch(); return $this; }
    public function getPeriod(): StatsPeriod { return $this->period; }
    public function setPeriod(StatsPeriod $v): static { $this->period = $v; $this->touch(); return $this; }
    public function getBodyTemplate(): string { return $this->bodyTemplate; }
    public function setBodyTemplate(string $v): static { $this->bodyTemplate = $v; $this->touch(); return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; $this->touch(); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
    public function validateTwitterLength(ExecutionContextInterface $context): void
```

#### `Sponsor`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private string $company;
    private ?string $nationality = null;
    private CompanySize $size = CompanySize::MEDIUM;
    private bool $isActive = true;
    private ?Club $club = null;
    private \DateTimeImmutable $createdAt;
    private int $monthlyPayment = 0;
    private ?\DateTimeImmutable $contractStartDate = null;
    private ?\DateTimeImmutable $contractEndDate = null;
    private int $reputationMinThreshold = 0;
    private ?int $reputationBonusThreshold = null;
    private string $bonusMultiplier = '1.00';
    private SponsorStatus $status = SponsorStatus::ACTIVE;
    private ?int $earlyTerminationFee = null;
    private ?\DateTimeImmutable $assignedAt = null;
    private ?\DateTimeImmutable $lastPaymentAt = null;
    public function __construct(string $company = '')
    public function __toString(): string { return $this->company; }
    public function getId(): UuidV7 { return $this->id; }
    public function getCompany(): string { return $this->company; }
    public function setCompany(string $company): void { $this->company = $company; }
    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }
    public function getSize(): CompanySize { return $this->size; }
```

#### `Staff`

> **Purpose:** `role` (StaffRole), `coachingAbility`; `appearance` json. **No club FK** — pool entity, deleted on consume.
```php
private UuidV7 $id;
    private string $firstName;
    private string $lastName;
    private StaffRole $role;
    private int $coachingAbility = 50;
    private int $scoutingRange = 50;
    private int $weeklySalary = 0;
    private int $morale = 50;
    private ?string $nationality = null;
    private ?string $specialty = null;
    private ?array $specialisms = null;
    private ?\DateTimeImmutable $dob = null;
    private \DateTimeImmutable $hiredAt;
    private ?array $appearance = null;
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
```

#### `StarterConfig`

> **Purpose:** singleton row; league player ability ranges + fan base growth curves; JSON dirty-check workaround applies here
```php
private int $id = 1;
    private int $startingBalance = 5_000_000;
    private int $starterPlayerCount = 5;
    private int $worldPackPlayersPerAgent = 12;
    private int $starterCoachCount = 1;
    private int $starterScoutCount = 1;
    private int $starterManagerCount = 1;
    private int $starterDirectorOfFootballCount = 0;
    private int $starterFacilityManagerCount = 0;
    private int $starterChairmanCount = 1;
    private string $starterSponsorTier = 'SMALL';
    private string $starterClubTier = 'local';
    private array $defaultFacilities = [];
    private ReputationTier $starterReputationTier = ReputationTier::LOCAL;
    private array $enabledCountries = ['EN'];
    private array $leagueAbilityRanges = [
    private array $npcSquadConfig = [
     * @var array<string, array{min: int, max: int}>
    private array $fanBaseRanges = [
    private float $fanBasePromotionIncrease = 0.20;
    private float $fanBaseRelegationDecrease = 0.10;
    public function getId(): int { return $this->id; }
    public function getStartingBalance(): int { return $this->startingBalance; }
    public function setStartingBalance(int $v): static { $this->startingBalance = $v; return $this; }
    public function getStartingBalancePounds(): int { return (int) round($this->startingBalance / 100); }
```

#### `SyncRecord`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private Club $club;
    private int $clientWeekNumber;
    private \DateTimeImmutable $clientTimestamp;
    private \DateTimeImmutable $serverTimestamp;
     * @var array<string, mixed>
    private array $payload = [];
     * @var array<string, mixed>|null
    private ?array $debugLog = null;
    private bool $isValid = true;
    private ?string $invalidReason = null;
    private bool $isRollback = false;
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getClientWeekNumber(): int { return $this->clientWeekNumber; }
    public function getClientTimestamp(): \DateTimeImmutable { return $this->clientTimestamp; }
    public function getServerTimestamp(): \DateTimeImmutable { return $this->serverTimestamp; }
    public function getPayload(): array { return $this->payload; }
    public function getDebugLog(): ?array { return $this->debugLog; }
    public function setDebugLog(?array $log): void { $this->debugLog = $log; }
    public function isValid(): bool { return $this->isValid; }
    public function markInvalid(string $reason): void
    public function getInvalidReason(): ?string { return $this->invalidReason; }
    public function isRollback(): bool { return $this->isRollback; }
    public function markRollback(): void { $this->isRollback = true; }
```

#### `TacticalAdvantage`

> **Purpose:** matchup table row: `style` vs `opponentStyle` (both `PlayingStyle`) → `multiplier` (float); seeded via `NarrativeImportExportService`
```php
private UuidV7 $id;
    private PlayingStyle $style;
    private PlayingStyle $opponentStyle;
    private float $multiplier;
    public function getId(): UuidV7 { return $this->id; }
    public function getStyle(): PlayingStyle { return $this->style; }
    public function setStyle(PlayingStyle $style): void { $this->style = $style; }
    public function getOpponentStyle(): PlayingStyle { return $this->opponentStyle; }
    public function setOpponentStyle(PlayingStyle $opponentStyle): void { $this->opponentStyle = $opponentStyle; }
    public function getMultiplier(): float { return $this->multiplier; }
    public function setMultiplier(float $multiplier): void { $this->multiplier = $multiplier; }
```

#### `Transfer`

> **Purpose:** fee + agentCommission in pence/cents; `getNetProceeds()` helper; `occurredAt` (client) + `syncedAt` (server); `player_id` is `ON DELETE SET NULL`
```php
private UuidV7 $id;
    private ?Player $player = null;
    private ?Club $club = null;
    private ?string $playerName = null;
    private ?string $playerPosition = null;
    private ?string $clubLeaving = null;
    private string $destinationClubName;
    private TransferType $type;
    private int $fee = 0;
    private int $agentCommission = 0;
    private int $netProceeds = 0;
    private int $developmentPoints = 0;
    private int $reputationGained = 0;
    private ?string $buyingClub = null;
    private \DateTimeImmutable $occurredAt;
    private ?\DateTimeImmutable $syncedAt = null;
    public function getId(): UuidV7 { return $this->id; }
    public function getPlayer(): ?Player { return $this->player; }
    public function getClub(): ?Club { return $this->club; }
    public function getPlayerName(): ?string { return $this->playerName; }
    public function setPlayerName(?string $v): void { $this->playerName = $v; }
    public function getPlayerPosition(): ?string { return $this->playerPosition; }
    public function setPlayerPosition(?string $v): void { $this->playerPosition = $v; }
    public function getClubLeaving(): ?string { return $this->clubLeaving; }
    public function setClubLeaving(?string $v): void { $this->clubLeaving = $v; }
```

#### `User`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._
```php
private UuidV7 $id;
    private string $email;
    private string $password;
    private array $roles = [];
    private Collection $clubs;
    private ?array $managerProfile = null;
    private bool $isVerified = false;
    private ?\DateTimeImmutable $verifiedAt = null;
    private ?\DateTimeImmutable $lastLoginAt = null;
    private \DateTimeImmutable $createdAt;
    public function __construct(string $email)
    public function getId(): UuidV7 { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
    public function getUserIdentifier(): string { return $this->email; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }
    public function getRoles(): array { return array_unique($this->roles); }
    public function setRoles(array $roles): void { $this->roles = $roles; }
    public function getClubs(): Collection { return $this->clubs; }
    public function getManagerProfile(): ?array { return $this->managerProfile; }
    public function setManagerProfile(?array $profile): void { $this->managerProfile = $profile; }
    public function isVerified(): bool { return $this->isVerified; }
    public function setIsVerified(bool $isVerified): void { $this->isVerified = $isVerified; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
```
