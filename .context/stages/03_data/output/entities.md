# symfony Entity Definitions

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
```

> **Field note:** **EasyAdmin custom form type on a `json`/array column** — a `Field::new('col')->setFormType(MyType::class)` where `col` is a Doctrine `json` type gets auto-configured by EasyAdmin as a collection, which injects `CollectionType` options (`allow_add`, `entry_type`, …) onto your form type and throws `The options ... do not exist`. Tolerate them in the type's `configureOptions()`: `$resolver->setDefined(['allow_add','allow_delete','delete_empty','entry_options','entry_type'])`. To render a fully custom widget for such a compound type, register a form theme via `$crud->addFormTheme(...)` (singular) and define a `{% block <blockPrefix>_widget %}` block (block prefix = the type class minus `Type`, snake_cased; `AppearanceType` → `appearance`). See `AppearanceType` + `templates/admin/form/appearance_theme.html.twig`.

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
    private array $gameplayEffects = [];
    private int $baseConstructionWeeks = 4;
    private int $sortOrder = 0;
    private bool $isActive = true;
    private \DateTimeImmutable $updatedAt;
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
    private array $impacts = [];
    private ?array $firingConditions = null;
    private ?string $severity = null;
    private bool $noInteract = false;
    private ?array $chainedEvents = null;
    private \DateTimeImmutable $createdAt;
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
```

#### `LeagueSponsor`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._

```php
private League $league;
    private Sponsor $sponsor;
    private int $rolledValue = 0;
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
    private array $traitMapping = [];
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
```

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
```

> **Field note:** **EasyAdmin custom form type on a `json`/array column** — a `Field::new('col')->setFormType(MyType::class)` where `col` is a Doctrine `json` type gets auto-configured by EasyAdmin as a collection, which injects `CollectionType` options (`allow_add`, `entry_type`, …) onto your form type and throws `The options ... do not exist`. Tolerate them in the type's `configureOptions()`: `$resolver->setDefined(['allow_add','allow_delete','delete_empty','entry_options','entry_type'])`. To render a fully custom widget for such a compound type, register a form theme via `$crud->addFormTheme(...)` (singular) and define a `{% block <blockPrefix>_widget %}` block (block prefix = the type class minus `Type`, snake_cased; `AppearanceType` → `appearance`). See `AppearanceType` + `templates/admin/form/appearance_theme.html.twig`.

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
    private array $leagueAbilityRanges = [];
    private array $npcSquadConfig = [];
    private array $fanBaseRanges = [];
    private float $fanBasePromotionIncrease = 0.20;
    private float $fanBaseRelegationDecrease = 0.10;
```

#### `SyncRecord`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._

```php
private UuidV7 $id;
    private Club $club;
    private int $clientWeekNumber;
    private \DateTimeImmutable $clientTimestamp;
    private \DateTimeImmutable $serverTimestamp;
    private array $payload = [];
    private ?array $debugLog = null;
    private bool $isValid = true;
    private ?string $invalidReason = null;
    private bool $isRollback = false;
```

#### `TacticalAdvantage`

> **Purpose:** matchup table row: `style` vs `opponentStyle` (both `PlayingStyle`) → `multiplier` (float); seeded via `NarrativeImportExportService`

```php
private UuidV7 $id;
    private PlayingStyle $style;
    private PlayingStyle $opponentStyle;
    private float $multiplier;
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
```
