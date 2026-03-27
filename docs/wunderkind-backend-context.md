# wunderkind-backend — Project Context

> Generated: 2026-03-26 23:32:38 | Stack: symfony 80 · PHP 8.4 · wunderkind | Dev: lando

---

## Overview

Wunderkind Backend is the server-side API for The Wunderkind Factory, a mobile-first youth football academy management game where players discover, develop, and trade young talent. The backend follows a client-authoritative hybrid sync model — all gameplay (weekly ticks, training, aging) runs offline on-device, while this API handles legacy metric syncing, anti-cheat validation, and global leaderboards. Built with Symfony 8.0, PHP 8.4, PostgreSQL 16, and JWT authentication, it exposes a RESTful API consumed by the React Native mobile client alongside an EasyAdmin v5 panel for operational oversight.

---

## Metrics

| Category | Count |
|---|---|
| PHP files         | 155 |
| Entities/Models   | 21 |
| Controllers       | 33 |
| Services          | 9 |
| Migrations        | 32 |

---

## Technology Stack

| | |
|---|---|
| **Language**      | php |
| **Framework**     | symfony 80 |
| **PHP**           | 8.4 |
| **Database**      | wunderkind |
| **Dev env**       | lando (symfony) |

### Dependencies

**require:**
- `php`: >=8.4
- `ext-ctype`: *
- `ext-iconv`: *
- `api-platform/core`: ^4.2
- `doctrine/doctrine-bundle`: ^3.2
- `doctrine/doctrine-migrations-bundle`: ^4.0
- `doctrine/orm`: ^3.6
- `easycorp/easyadmin-bundle`: ^5.0
- `lexik/jwt-authentication-bundle`: ^3.2
- `nelmio/cors-bundle`: ^2.6
- `symfony/console`: 8.0.*
- `symfony/dotenv`: 8.0.*
- `symfony/flex`: ^2
- `symfony/framework-bundle`: 8.0.*
- `symfony/runtime`: 8.0.*
- `symfony/security-bundle`: 8.0.*
- `symfony/uid`: 8.0.*
- `symfony/yaml`: 8.0.*

**require-dev:**
- `symfony/maker-bundle`: ^1.66

---

## Project Structure

```
.
├── bin
│   └── console
├── config
│   ├── jwt
│   │   ├── private.pem
│   │   └── public.pem
│   ├── packages
│   │   ├── api_platform.yaml
│   │   ├── cache.yaml
│   │   ├── csrf.yaml
│   │   ├── doctrine_migrations.yaml
│   │   ├── doctrine.yaml
│   │   ├── framework.yaml
│   │   ├── lexik_jwt_authentication.yaml
│   │   ├── nelmio_cors.yaml
│   │   ├── property_info.yaml
│   │   ├── routing.yaml
│   │   ├── security.yaml
│   │   ├── translation.yaml
│   │   ├── twig_component.yaml
│   │   ├── twig.yaml
│   │   └── validator.yaml
│   ├── routes
│   │   ├── api_platform.yaml
│   │   ├── easyadmin.yaml
│   │   ├── framework.yaml
│   │   └── security.yaml
│   ├── bundles.php
│   ├── preload.php
│   ├── reference.php
│   ├── routes.yaml
│   └── services.yaml
├── docs
│   ├── event-guide.md
│   ├── frontend-integration.md
│   ├── wunderkind-backend-context.md
│   └── wunderkind-backend-context.md.tmp
├── migrations
│   ├── archive
│   │   ├── README.md
│   │   ├── Version20260301214628.php
│   │   ├── Version20260302000001.php
│   │   ├── Version20260302000002.php
│   │   ├── Version20260302000003.php
│   │   ├── Version20260303000001.php
│   │   ├── Version20260303000002.php
│   │   ├── Version20260303000003.php
│   │   ├── Version20260303000004.php
│   │   ├── Version20260303000005.php
│   │   ├── Version20260303000006.php
│   │   ├── Version20260303195108.php
│   │   ├── Version20260303200052.php
│   │   ├── Version20260303201455.php
│   │   ├── Version20260303210001.php
│   │   ├── Version20260303214629.php
│   │   ├── Version20260304000334.php
│   │   ├── Version20260305000906.php
│   │   ├── Version20260305130043.php
│   │   ├── Version20260305234642.php
│   │   ├── Version20260306090200.php
│   │   ├── Version20260319143231.php
│   │   ├── Version20260319163437.php
│   │   ├── Version20260322000001.php
│   │   ├── Version20260322184350.php
│   │   ├── Version20260323000001.php
│   │   ├── Version20260324092239.php
│   │   ├── Version20260324114203.php
│   │   ├── Version20260325234055.php
│   │   └── Version20260325234056.php
│   ├── Version20260326000000_baseline_postgres.php
│   ├── Version20260326222629.php
│   └── Version20260327000001.php
├── public
│   ├── bundles
│   │   ├── apiplatform
│   │   └── easyadmin
│   ├── images
│   │   └── logo.webp
│   ├── admin-login.css
│   └── index.php
├── scripts
│   ├── generate_project_context_push.sh
│   ├── generate_project_context.sh
│   └── reset_and_seed.sh
├── src
│   ├── ApiResource
│   ├── Command
│   │   ├── CleanupAssignedEntitiesCommand.php
│   │   ├── CreateAdminCommand.php
│   │   ├── GenerateMarketDataCommand.php
│   │   ├── GenerateMarketPoolCommand.php
│   │   ├── SeedArchetypesCommand.php
│   │   ├── SeedGameEventsCommand.php
│   │   ├── SeedProspectPoolCommand.php
│   │   └── SetExistingAcademyBalancesCommand.php
│   ├── Controller
│   │   ├── Admin
│   │   ├── Api
│   │   ├── AdminSecurityController.php
│   │   ├── LeaderboardController.php
│   │   └── SyncController.php
│   ├── Dto
│   │   ├── AcademyInitRequest.php
│   │   ├── LedgerEntrySyncDto.php
│   │   ├── MarketAssignRequest.php
│   │   ├── MarketDataResponse.php
│   │   ├── SyncRequest.php
│   │   └── TransferSyncDto.php
│   ├── Entity
│   │   ├── Academy.php
│   │   ├── Admin.php
│   │   ├── Agent.php
│   │   ├── Facility.php
│   │   ├── GameConfig.php
│   │   ├── GameEventTemplate.php
│   │   ├── Guardian.php
│   │   ├── InboxMessage.php
│   │   ├── Investor.php
│   │   ├── LeaderboardEntry.php
│   │   ├── PersonalityProfile.php
│   │   ├── Player.php
│   │   ├── PlayerArchetype.php
│   │   ├── PoolConfig.php
│   │   ├── Scout.php
│   │   ├── Sponsor.php
│   │   ├── Staff.php
│   │   ├── StarterConfig.php
│   │   ├── SyncRecord.php
│   │   ├── Transfer.php
│   │   └── User.php
│   ├── Enum
│   │   ├── CompanySize.php
│   │   ├── EventCategory.php
│   │   ├── FacilityType.php
│   │   ├── InvestorTier.php
│   │   ├── LeaderboardCategory.php
│   │   ├── MarketEntityType.php
│   │   ├── MessageSenderType.php
│   │   ├── MessageStatus.php
│   │   ├── PlayerPosition.php
│   │   ├── PlayerStatus.php
│   │   ├── RecruitmentSource.php
│   │   ├── SponsorStatus.php
│   │   ├── StaffRole.php
│   │   └── TransferType.php
│   ├── EventSubscriber
│   │   └── DomainSeparationSubscriber.php
│   ├── Repository
│   │   ├── AcademyRepository.php
│   │   ├── AdminRepository.php
│   │   ├── AgentRepository.php
│   │   ├── FacilityRepository.php
│   │   ├── GameConfigRepository.php
│   │   ├── GameEventTemplateRepository.php
│   │   ├── GuardianRepository.php
│   │   ├── InboxMessageRepository.php
│   │   ├── InvestorRepository.php
│   │   ├── LeaderboardEntryRepository.php
│   │   ├── PlayerArchetypeRepository.php
│   │   ├── PlayerRepository.php
│   │   ├── PoolConfigRepository.php
│   │   ├── ScoutRepository.php
│   │   ├── SponsorRepository.php
│   │   ├── StaffRepository.php
│   │   ├── StarterConfigRepository.php
│   │   └── TransferRepository.php
│   ├── Service
│   │   ├── AcademyInitializationService.php
│   │   ├── EconomicService.php
│   │   ├── FacilityService.php
│   │   ├── InboxService.php
│   │   ├── MarketDataService.php
│   │   ├── MarketPoolService.php
│   │   ├── NameGeneratorService.php
│   │   ├── SyncService.php
│   │   └── TransferLeaderboardService.php
│   └── Kernel.php
├── templates
│   ├── admin
│   │   ├── _macros.html.twig
│   │   ├── academy_profile.html.twig
│   │   ├── dashboard.html.twig
│   │   ├── game_config.html.twig
│   │   ├── login.html.twig
│   │   ├── pool_config.html.twig
│   │   ├── settings.html.twig
│   │   └── starter_config.html.twig
│   └── base.html.twig
├── tests
│   ├── Controller
│   │   └── Api
│   ├── Repository
│   │   └── GameEventTemplateRepositoryTest.php
│   └── Service
│       ├── AcademyInitializationServiceTest.php
│       ├── EconomicServiceTest.php
│       ├── InboxServiceTest.php
│       └── SyncServiceManagerShiftsTest.php
├── translations
├── CLAUDE.md
├── compose.override.yaml
├── compose.yaml
├── composer.json
├── composer.lock
├── project_plan.md
├── README.md
├── symfony.lock
└── wunderkind-backend-context.md

35 directories, 174 files
```

---

## Data Models

#### Academy
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
    private ?string $paName = null;
    private int $managerTemperament = 50;
    private int $managerDiscipline = 50;
    private int $managerAmbition = 50;
    private int $balance = 0;
```

#### Admin
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
    public function getRoles(): array { return ['ROLE_ADMIN']; }
```

#### Agent
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
    public function __construct(string $name)
    public function __toString(): string { return $this->name; }
    public function getId(): UuidV7 { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
```

#### Facility
```php
    private const UPGRADE_COSTS = [0, 50_000, 150_000, 300_000, 500_000, 1_000_000];
    private UuidV7 $id;
    private FacilityType $type;
    private int $level = 0;
    private Academy $academy;
    private ?\DateTimeImmutable $lastUpgradedAt = null;
    public function __construct(FacilityType $type, Academy $academy)
    public function getId(): UuidV7 { return $this->id; }
    public function getType(): FacilityType { return $this->type; }
    public function getTypeValue(): string { return $this->type->value; }
    public function getLevel(): int { return $this->level; }
    public function setLevel(int $level): void { $this->level = max(0, min(5, $level)); }
    public function getAcademy(): Academy { return $this->academy; }
    public function getLastUpgradedAt(): ?\DateTimeImmutable { return $this->lastUpgradedAt; }
    public function setLastUpgradedAt(?\DateTimeImmutable $at): void { $this->lastUpgradedAt = $at; }
```

#### GameConfig
```php
    private ?int $id = null;
    private int $cliqueRelationshipThreshold = 20;
    private int $cliqueSquadCapPercent = 30;
    private int $cliqueMinTenureWeeks = 3;
    private int $baseXP = 10;
    private float $baseInjuryProbability = 0.05;
    private int $regressionUpperThreshold = 14;
    private int $regressionLowerThreshold = 7;
    private float $reputationDeltaBase = 0.5;
    private float $reputationDeltaFacilityMultiplier = 1.2;
    private int $injuryMinorWeight = 60;
    private int $injuryModerateWeight = 30;
    private int $injurySeriousWeight = 10;
    private int $scoutMoraleThreshold = 40;
    private int $scoutRevealWeeks = 2;
```

#### GameEventTemplate
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
    private \DateTimeImmutable $createdAt;
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }
    public function getCategory(): EventCategory { return $this->category; }
```

#### Guardian
```php
    private UuidV7 $id;
    private string $firstName;
    private string $lastName;
    private string $gender = 'male';
    private ?\DateTimeImmutable $dateOfBirth = null;
    private ?string $contactEmail = null;
    private int $demandLevel = 5;
    private int $loyaltyToAcademy = 50;
    private Player $player;
    public function __construct(string $firstName, string $lastName, Player $player, string $gender = 'male')
    public function getId(): UuidV7 { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }
```

#### InboxMessage
```php
    private UuidV7 $id;
    private Academy $academy;
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
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getAcademy(): Academy { return $this->academy; }
```

#### Investor
```php
    private UuidV7 $id;
    private string $company;
    private ?string $nationality = null;
    private CompanySize $size = CompanySize::MEDIUM;
    private bool $isActive = true;
    private ?Academy $academy = null;
    private \DateTimeImmutable $createdAt;
    private InvestorTier $tier = InvestorTier::ANGEL;
    private int $investmentAmount = 0;
    private string $percentageOwned = '5.00';
    private ?\DateTimeImmutable $assignedAt = null;
    private ?\DateTimeImmutable $investedAt = null;
    private ?\DateTimeImmutable $lastPayoutAt = null;
    public function __construct(string $company = '')
    public function getId(): UuidV7 { return $this->id; }
```

#### LeaderboardEntry
```php
    private UuidV7 $id;
    private Academy $academy;
    private LeaderboardCategory $category;
    private int $score = 0;
    private string $period;
    private ?int $rank = null;
    private \DateTimeImmutable $updatedAt;
    public function __construct(Academy $academy, LeaderboardCategory $category, string $period)
    public function getId(): UuidV7 { return $this->id; }
    public function getAcademy(): Academy { return $this->academy; }
    public function getCategory(): LeaderboardCategory { return $this->category; }
    public function getCategoryValue(): string { return $this->category->value; }
    public function getPeriod(): string { return $this->period; }
    public function getScore(): int { return $this->score; }
    public function setScore(int $score): void
```

#### PersonalityProfile
```php
    private int $confidence = 50;
    private int $maturity = 50;
    private int $teamwork = 50;
    private int $leadership = 50;
    private int $ego = 50;
    private int $bravery = 50;
    private int $greed = 50;
    private int $loyalty = 50;
    public function getConfidence(): int { return $this->confidence; }
    public function setConfidence(int $v): void { $this->confidence = $this->clamp($v); }
    public function getMaturity(): int { return $this->maturity; }
    public function setMaturity(int $v): void { $this->maturity = $this->clamp($v); }
    public function getTeamwork(): int { return $this->teamwork; }
    public function setTeamwork(int $v): void { $this->teamwork = $this->clamp($v); }
    public function getLeadership(): int { return $this->leadership; }
```

#### Player
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
    private ?Academy $academy = null;
    private Collection $guardians;
    private ?Agent $agent = null;
```

#### PlayerArchetype
```php
    private ?int $id = null;
    private string $name;
    private string $description;
    private array $traitMapping = [];
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    public function __construct(
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): void { $this->description = $description; }
    public function getTraitMapping(): array { return $this->traitMapping; }
    public function setTraitMapping(array $traitMapping): void { $this->traitMapping = $traitMapping; }
    public function getTraitMappingJson(): string
```

#### PoolConfig
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
```

#### Scout
```php
    private UuidV7 $id;
    private string $name;
    private ?\DateTimeImmutable $dob = null;
    private ?string $nationality = null;
    private array $judgements = [];
    private int $experience = 0;
    private \DateTimeImmutable $createdAt;
    public function __construct(string $name = '')
    public function getId(): UuidV7 { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getDob(): ?\DateTimeImmutable { return $this->dob; }
    public function setDob(?\DateTimeImmutable $dob): void { $this->dob = $dob; }
    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }
```

#### Sponsor
```php
    private UuidV7 $id;
    private string $company;
    private ?string $nationality = null;
    private CompanySize $size = CompanySize::MEDIUM;
    private bool $isActive = true;
    private ?Academy $academy = null;
    private \DateTimeImmutable $createdAt;
    private int $monthlyPayment = 0;
    private ?\DateTimeImmutable $contractStartDate = null;
    private ?\DateTimeImmutable $contractEndDate = null;
    private int $reputationMinThreshold = 0;
    private ?int $reputationBonusThreshold = null;
    private string $bonusMultiplier = '1.00';
    private SponsorStatus $status = SponsorStatus::ACTIVE;
    private ?int $earlyTerminationFee = null;
```

#### Staff
```php
    private UuidV7 $id;
    private string $firstName;
    private string $lastName;
    private StaffRole $role;
    private int $coachingAbility = 50;
    private int $scoutingRange = 50;
    private int $weeklySalary = 0;
    private int $morale = 50;
    private ?string $specialty = null;
    private ?array $specialisms = null;
    private ?Academy $academy = null;
    private ?\DateTimeImmutable $assignedAt = null;
    private ?\DateTimeImmutable $dob = null;
    private \DateTimeImmutable $hiredAt;
    public function __construct(
```

#### StarterConfig
```php
    private int $id = 1;
    private int $startingBalance = 5_000_000;
    private int $starterPlayerCount = 5;
    private int $starterCoachCount = 1;
    private int $starterScoutCount = 1;
    private string $starterSponsorTier = 'SMALL';
    public static function defaults(): self
    public function getId(): int { return $this->id; }
    public function getStartingBalance(): int { return $this->startingBalance; }
    public function setStartingBalance(int $v): static { $this->startingBalance = $v; return $this; }
    public function getStartingBalancePounds(): int { return (int) round($this->startingBalance / 100); }
    public function setStartingBalancePounds(int $pounds): static { $this->startingBalance = $pounds * 100; return $this; }
    public function getStarterPlayerCount(): int { return $this->starterPlayerCount; }
    public function setStarterPlayerCount(int $v): static { $this->starterPlayerCount = $v; return $this; }
    public function getStarterCoachCount(): int { return $this->starterCoachCount; }
```

#### SyncRecord
```php
    private UuidV7 $id;
    private Academy $academy;
    private int $clientWeekNumber;
    private \DateTimeImmutable $clientTimestamp;
    private \DateTimeImmutable $serverTimestamp;
    private array $payload = [];
    private bool $isValid = true;
    private ?string $invalidReason = null;
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getAcademy(): Academy { return $this->academy; }
    public function getClientWeekNumber(): int { return $this->clientWeekNumber; }
    public function getClientTimestamp(): \DateTimeImmutable { return $this->clientTimestamp; }
    public function getServerTimestamp(): \DateTimeImmutable { return $this->serverTimestamp; }
    public function getPayload(): array { return $this->payload; }
```

#### Transfer
```php
    private UuidV7 $id;
    private ?Player $player = null;
    private Academy $academy;
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
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
```

#### User
```php
    public const ROLE_ACADEMY = 'ROLE_ACADEMY';
    private UuidV7 $id;
    private string $email;
    private string $password;
    private array $roles = [];
    private ?Academy $academy = null;
    private ?array $managerProfile = null;
    private \DateTimeImmutable $createdAt;
    public function __construct(string $email)
    public function getId(): UuidV7 { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
    public function getUserIdentifier(): string { return $this->email; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }
```


---

## API Routes

```
 ------------------------------------------ ---------------- ---------------------------------------------- 
 [32m Name                                     [39m [32m Method         [39m [32m Path                                         [39m 
 ------------------------------------------ ---------------- ---------------------------------------------- 
  api_doc                                    [34mGET[39m|[35mHEAD[39m         /api/docs.{_format}                           
  api_genid                                  [34mGET[39m|[35mHEAD[39m         /api/.well-known/genid/{id}                   
  api_validation_errors                      [34mGET[39m|[35mHEAD[39m         /api/validation_errors/{id}                   
  api_entrypoint                             [34mGET[39m|[35mHEAD[39m         /api/{index}.{_format}                        
  api_jsonld_context                         [34mGET[39m|[35mHEAD[39m         /api/contexts/{shortName}.{_format}           
  _api_errors                                [34mGET[39m              /api/errors/{status}.{_format}                
  _api_validation_errors_problem             [34mGET[39m              /api/validation_errors/{id}                   
  _api_validation_errors_hydra               [34mGET[39m              /api/validation_errors/{id}                   
  _api_validation_errors_jsonapi             [34mGET[39m              /api/validation_errors/{id}                   
  _api_validation_errors_xml                 [34mGET[39m              /api/validation_errors/{id}                   
  admin                                      [39mANY[39m              /admin                                        
  admin_academy_index                        [34mGET[39m              /admin/academy                                
  admin_academy_new                          [34mGET[39m|[32mPOST[39m         /admin/academy/new                            
  admin_academy_batch_delete                 [32mPOST[39m             /admin/academy/batch-delete                   
  admin_academy_autocomplete                 [34mGET[39m              /admin/academy/autocomplete                   
  admin_academy_render_filters               [34mGET[39m              /admin/academy/render-filters                 
  admin_academy_edit                         [34mGET[39m|[32mPOST[39m|[33mPATCH[39m   /admin/academy/{entityId}/edit                
  admin_academy_delete                       [32mPOST[39m             /admin/academy/{entityId}/delete              
  admin_academy_detail                       [34mGET[39m              /admin/academy/{entityId}                     
  admin_admin_index                          [34mGET[39m              /admin/admin                                  
  admin_admin_new                            [34mGET[39m|[32mPOST[39m         /admin/admin/new                              
  admin_admin_batch_delete                   [32mPOST[39m             /admin/admin/batch-delete                     
  admin_admin_autocomplete                   [34mGET[39m              /admin/admin/autocomplete                     
  admin_admin_render_filters                 [34mGET[39m              /admin/admin/render-filters                   
  admin_admin_edit                           [34mGET[39m|[32mPOST[39m|[33mPATCH[39m   /admin/admin/{entityId}/edit                  
  admin_admin_delete                         [32mPOST[39m             /admin/admin/{entityId}/delete                
  admin_admin_detail                         [34mGET[39m              /admin/admin/{entityId}                       
  admin_agent_index                          [34mGET[39m              /admin/agent                                  
  admin_agent_new                            [34mGET[39m|[32mPOST[39m         /admin/agent/new                              
  admin_agent_batch_delete                   [32mPOST[39m             /admin/agent/batch-delete                     
  admin_agent_autocomplete                   [34mGET[39m              /admin/agent/autocomplete                     
  admin_agent_render_filters                 [34mGET[39m              /admin/agent/render-filters                   
  admin_agent_edit                           [34mGET[39m|[32mPOST[39m|[33mPATCH[39m   /admin/agent/{entityId}/edit                  
  admin_agent_delete                         [32mPOST[39m             /admin/agent/{entityId}/delete                
  admin_agent_detail                         [34mGET[39m              /admin/agent/{entityId}                       
  admin_game_event_template_index            [34mGET[39m              /admin/game-event-template                    
  admin_game_event_template_new              [34mGET[39m|[32mPOST[39m         /admin/game-event-template/new                
  admin_game_event_template_batch_delete     [32mPOST[39m             /admin/game-event-template/batch-delete       
  admin_game_event_template_autocomplete     [34mGET[39m              /admin/game-event-template/autocomplete       
  admin_game_event_template_render_filters   [34mGET[39m              /admin/game-event-template/render-filters     
  admin_game_event_template_edit             [34mGET[39m|[32mPOST[39m|[33mPATCH[39m   /admin/game-event-template/{entityId}/edit    
  admin_game_event_template_delete           [32mPOST[39m             /admin/game-event-template/{entityId}/delete  
  admin_game_event_template_detail           [34mGET[39m              /admin/game-event-template/{entityId}         
  admin_guardian_index                       [34mGET[39m              /admin/guardian                               
  admin_guardian_new                         [34mGET[39m|[32mPOST[39m         /admin/guardian/new                           
  admin_guardian_batch_delete                [32mPOST[39m             /admin/guardian/batch-delete                  
  admin_guardian_autocomplete                [34mGET[39m              /admin/guardian/autocomplete                  
  admin_guardian_render_filters              [34mGET[39m              /admin/guardian/render-filters                
  admin_guardian_edit                        [34mGET[39m|[32mPOST[39m|[33mPATCH[39m   /admin/guardian/{entityId}/edit               
  admin_guardian_delete                      [32mPOST[39m             /admin/guardian/{entityId}/delete             
  admin_guardian_detail                      [34mGET[39m              /admin/guardian/{entityId}                    
  admin_investor_index                       [34mGET[39m              /admin/investor                               
  admin_investor_new                         [34mGET[39m|[32mPOST[39m         /admin/investor/new                           
  admin_investor_batch_delete                [32mPOST[39m             /admin/investor/batch-delete                  
  admin_investor_autocomplete                [34mGET[39m              /admin/investor/autocomplete                  
  admin_investor_render_filters              [34mGET[39m              /admin/investor/render-filters                
  admin_investor_edit                        [34mGET[39m|[32mPOST[39m|[33mPATCH[39m   /admin/investor/{entityId}/edit               
```

---

## Controllers

#### AcademyCrudController
```php
    public function __construct(private EntityManagerInterface $em) {}
    public function configureActions(Actions $actions): Actions
    public function detail(AdminContext $context): Response
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### AdminCrudController
```php
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### AgentCrudController
```php
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### DashboardController
```php
    public function __construct(
    public function index(): Response
    #[Route('/admin/settings', name: 'admin_settings')]
    public function settings(): Response
    #[Route('/admin/game-config', name: 'admin_game_config')]
    public function gameConfig(): Response
    #[Route('/admin/game-config/save', name: 'admin_game_config_save', methods: ['POST'])]
    public function saveGameConfig(Request $request): Response
    #[Route('/admin/starter-config', name: 'admin_starter_config')]
    public function starterConfig(): Response
    #[Route('/admin/starter-config/save', name: 'admin_starter_config_save', methods: ['POST'])]
    public function saveStarterConfig(Request $request): Response
    #[Route('/admin/pool-config', name: 'admin_pool_config')]
    public function poolConfig(): Response
    #[Route('/admin/pool-config/save', name: 'admin_pool_config_save', methods: ['POST'])]
    public function savePoolConfig(Request $request): Response
    #[Route('/admin/pool-config/generate', name: 'admin_pool_generate', methods: ['POST'])]
    public function generatePool(Request $request): Response
    #[Route('/admin/developer-tools/trigger-age21', name: 'admin_trigger_age21', methods: ['POST'])]
    public function triggerAge21Deletion(Request $request, EconomicService $economicService): Response
```

#### GameEventTemplateCrudController
```php
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### GuardianCrudController
```php
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### InvestorCrudController
```php
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### LeaderboardEntryCrudController
```php
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### PlayerArchetypeCrudController
```php
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### PlayerCrudController
```php
    public function __construct(private readonly AcademyRepository $academyRepository) {}
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function createEntity(string $entityFqcn): Player
    public function configureFields(string $pageName): iterable
```

#### ScoutCrudController
```php
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### SponsorCrudController
```php
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### StaffCrudController
```php
    public function __construct(private readonly AcademyRepository $academyRepository) {}
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function createEntity(string $entityFqcn): Staff
    public function configureFields(string $pageName): iterable
```

#### SyncRecordCrudController
```php
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### TransferCrudController
```php
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### UserCrudController
```php
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### AdminSecurityController
```php
    #[Route('/admin/login', name: 'admin_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    #[Route('/admin/logout', name: 'admin_logout')]
    public function logout(): never
```

#### AcademyController
```php
#[Route('/api/academy')]
    #[Route('/initialize', name: 'api_academy_initialize', methods: ['POST'])]
    public function initialize(
    #[Route('/check', name: 'api_academy_check', methods: ['GET'])]
    public function check(): JsonResponse
    #[Route('/status', name: 'api_academy_status', methods: ['GET'])]
    public function status(): JsonResponse
```

#### AdminController
```php
#[Route('/api/admin')]
    #[Route('/stats', name: 'api_admin_stats', methods: ['GET'])]
    public function stats(): JsonResponse
```

#### ArchetypeController
```php
#[Route('/api/archetypes', name: 'api_archetypes', methods: ['GET'])]
    public function __construct(
    public function __invoke(): JsonResponse
```

#### EventController
```php
#[Route('/api/events')]
    public function __construct(
    #[Route('/templates', name: 'api_events_templates', methods: ['GET'])]
    public function templates(): JsonResponse
```

#### FacilityController
```php
#[Route('/api/facilities')]
    #[Route('', name: 'api_facilities_index', methods: ['GET'])]
    public function index(FacilityService $facilityService): JsonResponse
    #[Route('/{type}/upgrade', name: 'api_facilities_upgrade', methods: ['POST'])]
    public function upgrade(
```

#### FinanceController
```php
#[Route('/api/finance')]
    public function __construct(
    #[Route('/overview', methods: ['GET'])]
    public function overview(): JsonResponse
    #[Route('/investors', methods: ['GET'])]
    public function investors(): JsonResponse
    #[Route('/sponsors', methods: ['GET'])]
    public function sponsors(): JsonResponse
    #[Route('/sponsors/{id}/terminate', methods: ['POST'])]
    public function terminateSponsor(string $id): JsonResponse
```

#### GameConfigController
```php
#[Route('/api')]
    public function __construct(
    #[Route('/game-config', name: 'api_game_config', methods: ['GET'])]
    public function index(): JsonResponse
```

#### InboxController
```php
#[Route('/api/inbox')]
    public function __construct(
    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    #[Route('/{id}/accept', methods: ['POST'])]
    public function accept(string $id): JsonResponse
    #[Route('/{id}/reject', methods: ['POST'])]
    public function reject(string $id): JsonResponse
    #[Route('/{id}/read', methods: ['POST'])]
    public function markRead(string $id): JsonResponse
```

#### MarketController
```php
#[Route('/api/market')]
    #[Route('/data', name: 'api_market_pool_data', methods: ['GET'])]
    public function data(Request $request, MarketDataService $service): JsonResponse
    #[Route('/prospects', name: 'api_market_prospects', methods: ['GET'])]
    public function prospects(MarketDataService $service): JsonResponse
    #[Route('/assign', name: 'api_market_assign', methods: ['POST'])]
    public function assign(
    #[Route('/legacy', name: 'api_market_data_legacy', methods: ['GET'])]
    public function legacyData(
```

#### PoolController
```php
#[Route('/api/pool')]
    #[Route('/ensure', name: 'api_pool_ensure', methods: ['POST'])]
    public function ensure(
```

#### SquadController
```php
#[Route('/api/squad')]
    public function __construct(private readonly PlayerRepository $playerRepository) {}
    #[Route('', name: 'api_squad_index', methods: ['GET'])]
    public function index(): JsonResponse
```

#### StaffController
```php
#[Route('/api/staff')]
    #[Route('', name: 'api_staff_index', methods: ['GET'])]
    public function index(): JsonResponse
```

#### StarterConfigController
```php
#[Route('/api')]
    public function __construct(
    #[Route('/starter-config', name: 'api_starter_config', methods: ['GET'])]
    public function index(): JsonResponse
```

#### TransferLeaderboardController
```php
#[Route('/api/leaderboard/transfers')]
    public function __construct(
    #[Route('/top-sellers', name: 'api_transfer_leaderboard_top_sellers', methods: ['GET'])]
    public function topSellers(Request $request): JsonResponse
    #[Route('/most-valuable', name: 'api_transfer_leaderboard_most_valuable', methods: ['GET'])]
    public function mostValuable(Request $request): JsonResponse
```

#### LeaderboardController
```php
#[Route('/api')]
    #[Route('/leaderboard/{category}', name: 'api_leaderboard', methods: ['GET'])]
    public function index(
```

#### SyncController
```php
#[Route('/api')]
    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(
    #[Route('/sync', name: 'api_sync', methods: ['POST'])]
    public function sync(
```


---

## Services

#### AcademyInitializationService
```php
    public function __construct(
    public function initializeAcademy(User $user, string $academyName, ?string $country = null, ?array $managerProfile = null): Academy
    public function getStarterBundle(): array
```

#### EconomicService
```php
    public function __construct(
    public function generateSponsorOffer(Academy $academy): array
    public function generateInvestorOffer(Academy $academy): array
    public function calculatePlayerMarketValue(Player $player): int
    public function processFinancialYearEnd(Academy $academy): void
    public function checkSponsorContracts(Academy $academy, int $currentReputation): void
    public function checkAgeOutPlayers(Academy $academy, int $currentWeek, \DateTimeImmutable $clientTimestamp): void
```

#### FacilityService
```php
    public function __construct(
    public function getAcademyFacilitiesData(Academy $academy): array
    public function upgradeFacility(Facility $facility): void
    public function initializeFacilities(Academy $academy): void
```

#### InboxService
```php
    public function __construct(
    public function sendSponsorOffer(Academy $academy, array $offerData): InboxMessage
    public function sendInvestorOffer(Academy $academy, array $offerData): InboxMessage
    public function sendAgentSaleOffer(Player $player, array $offerData): InboxMessage
    public function sendAgeOutWarning(Player $player, int $weeksRemaining): InboxMessage
    public function sendForcedSaleNotification(Player $player, int $salePrice): InboxMessage
    public function sendSystemNotification(Academy $academy, string $subject, string $body, array $details = []): InboxMessage
    public function acceptMessage(InboxMessage $message, User $user): void
    public function rejectMessage(InboxMessage $message): void
```

#### MarketDataService
```php
    public function __construct(private readonly MarketPoolService $pool) {}
    public function getMarketSnapshot(?string $nationality = null): MarketDataResponse
    public function getProspectSnapshot(): array
```

#### MarketPoolService
```php
    public function __construct(
    public function generatePlayers(int $count, ?int $academyReputation = null, RecruitmentSource $source = RecruitmentSource::YOUTH_INTAKE, ?string $nationality = null): array
    public function generateCoaches(int $count, ?int $academyReputation = null): array
    public function generateScouts(int $count): array
    public function generateAgents(int $count): array
    public function generateSponsors(int $count): array
    public function generateInvestors(int $count): array
    public function getAvailablePlayers(int $limit = 100, ?string $nationality = null): array
    public function getAvailableProspects(int $limit = 150): array
    public function getAvailableCoaches(int $limit = 20): array
    public function getAvailableScouts(int $limit = 10): array
    public function getAgents(): array
```

#### NameGeneratorService
```php
    public function generateName(string $nationality): string
    public function generatePlayerName(string $nationality): array
    public function generateFirstName(string $nationality): string
    public function generateLastName(string $nationality): string
    public function getRandomNationality(): string
```

#### SyncService
```php
    public function __construct(
    public function process(User $user, SyncRequest $request): array
```

#### TransferLeaderboardService
```php
    public function __construct(
    public function getTopSellers(string $period = 'week', int $limit = 10): array
    public function getMostValuableSale(string $period = 'week'): ?array
```


---

## Migrations

| Migration | Date |
|---|---|
| `Version20260322000001` | 20260322 |
| `Version20260322184350` | 20260322 |
| `Version20260323000001` | 20260323 |
| `Version20260324092239` | 20260324 |
| `Version20260324114203` | 20260324 |
| `Version20260325234055` | 20260325 |
| `Version20260325234056` | 20260325 |
| `Version20260326000000_baseline_postgres` | 20260326 |
| `Version20260326222629` | 20260326 |
| `Version20260327000001` | 20260327 |
_Showing latest 10 of 32 total._

---

## Environment Variables

```bash
APP_ENV=***
APP_SECRET=***
APP_SHARE_DIR=***
DEFAULT_URI=***
DATABASE_URL=***
CORS_ALLOW_ORIGIN=***
JWT_SECRET_KEY=***
JWT_PUBLIC_KEY=***
JWT_PASSPHRASE=***
ACADEMY_STARTING_BALANCE=***
```

---

## Development Setup

```bash
lando start
lando composer install
lando php bin/console doctrine:migrations:migrate
lando php bin/console cache:clear
```

---

## Recent Git Activity

```
466b273 latest admin
8a5ff03 latest
2715bcd latest code
fa4ac61 updated context
1ce30f0 latest
b16643f update context
cbe4328 feat: Phase 3 & 4 — NPC interaction system + GameConfig API
9e730a5 feat: configurable starting balance + SyncRecord payload viewer in admin
4f9e314 docs: add event guide
f01a3ba docs: update frontend integration guide — sync managerShifts, archetypes, events, transfer leaderboards, corrected starter bundle
fb3092c feat: editable specialisms field on staff edit form via virtual JSON string property
c0388cc fix: hide specialisms JSON field on staff index to avoid TextareaField type error
39fd43b feat: editable impacts field on event template admin form via virtual JSON string property
4db8b9c fix: editable traitMapping in archetype admin form via virtual JSON string property
584cad3 fix: hide traitMapping JSON field on archetype index to avoid TextareaField type error
```

---

## Architecture Notes

- **Repository Pattern** — dedicated `*Repository` classes per entity encapsulate all query logic, keeping persistence concerns out of services and controllers
- **Service Layer** — domain logic is centralized in `*Service` classes (e.g. `SyncService`, `EconomicService`, `MarketPoolService`), with controllers acting as thin HTTP adapters that delegate immediately to services
- **DTO (Data Transfer Object)** — `src/Dto/` separates validated input contracts from entities, used for deserializing and validating API payloads before they reach the service layer
- **CQRS-lite via API Platform resources** — `src/ApiResource/` suggests read-side resource definitions are decoupled from write-side controller/service flows, approximating command/query separation without a full CQRS bus
- **Domain Event / Observer** — `src/EventSubscriber/` (e.g. `DomainSeparationSubscriber`) wires cross-cutting concerns (auth, domain routing) to Symfony's event dispatcher rather than embedding them in controllers or services

---

## Current Development Focus

- **GameConfig / StarterConfig / PoolConfig system** — new `PoolConfig` entity, deleted old CRUD controllers, and active changes to `GameConfig` suggest a configuration layer being rebuilt; AI can help design a clean, extensible config schema and admin UI for it.
- **Admin panel consolidation** — multiple CRUD controllers deleted/replaced, new Twig templates added (`game_config`, `pool_config`, `starter_config`, `_macros`), and `DashboardController` actively modified; AI can accelerate template generation and enforce consistent EasyAdmin patterns.
- **NPC interaction system (Phase 3 & 4)** — recent commit explicitly adds NPC interactions alongside `GameConfigController` changes; this domain (state machines, offer/response flows, event triggers) is complex enough to benefit from AI-generated scaffolding and edge-case reasoning.
- **Migration hygiene** — 30+ migrations deleted and archived with a new Postgres baseline; as new entities (`PoolConfig`) are added, AI can help generate correct Doctrine Schema API migrations and flag reserved-word or type-compatibility issues early.
- **MarketPoolService / SyncService business logic** — both files actively modified alongside economic and narrative systems; AI can help audit side-effect ordering, identify missing rollback paths, and surface invariant violations in the sync pipeline.
