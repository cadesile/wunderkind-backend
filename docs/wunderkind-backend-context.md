# Wunderkind Backend - Project Context

> Last Updated: $(date +"%Y-%m-%d %H:%M:%S")

## Overview
Wunderkind Factory backend API built with Symfony for managing youth football academies and leaderboard systems.

---

## Technology Stack

### Core Framework
- **Symfony**: 6.4
- **PHP**: 8.x
- **Database**: MySQL/MariaDB
- **Local Dev**: Lando

### Key Packages

```json
{
  "php": ">=8.4",
  "ext-ctype": "*",
  "ext-iconv": "*",
  "api-platform/core": "^4.2",
  "doctrine/doctrine-bundle": "^3.2",
  "doctrine/doctrine-migrations-bundle": "^4.0",
  "doctrine/orm": "^3.6",
  "easycorp/easyadmin-bundle": "^5.0",
  "lexik/jwt-authentication-bundle": "^3.2",
  "nelmio/cors-bundle": "^2.6",
  "symfony/console": "8.0.*",
  "symfony/dotenv": "8.0.*",
  "symfony/flex": "^2",
  "symfony/framework-bundle": "8.0.*",
  "symfony/runtime": "8.0.*",
  "symfony/security-bundle": "8.0.*",
  "symfony/uid": "8.0.*",
  "symfony/yaml": "8.0.*"
}
```

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
│   ├── frontend-integration.md
│   └── wunderkind-backend-context.md
├── migrations
│   ├── Version20260301214628.php
│   ├── Version20260302000001.php
│   ├── Version20260302000002.php
│   ├── Version20260302000003.php
│   ├── Version20260303000001.php
│   ├── Version20260303000002.php
│   ├── Version20260303000003.php
│   ├── Version20260303000004.php
│   ├── Version20260303000005.php
│   ├── Version20260303000006.php
│   ├── Version20260303195108.php
│   ├── Version20260303200052.php
│   ├── Version20260303201455.php
│   ├── Version20260303210001.php
│   ├── Version20260303214629.php
│   ├── Version20260304000334.php
│   ├── Version20260305000906.php
│   ├── Version20260305130043.php
│   ├── Version20260305234642.php
│   └── Version20260306090200.php
├── public
│   ├── bundles
│   │   ├── apiplatform
│   │   └── easyadmin
│   ├── images
│   │   └── logo.webp
│   ├── admin-login.css
│   └── index.php
├── scripts
│   ├── generate_project_context.sh
│   └── reset_and_seed.sh
├── src
│   ├── ApiResource
│   ├── Command
│   │   ├── CleanupAssignedEntitiesCommand.php
│   │   ├── GenerateMarketDataCommand.php
│   │   ├── GenerateMarketPoolCommand.php
│   │   ├── SeedArchetypesCommand.php
│   │   ├── SeedGameEventsCommand.php
│   │   └── SetExistingAcademyBalancesCommand.php
│   ├── Controller
│   │   ├── Admin
│   │   ├── Api
│   │   ├── AdminSecurityController.php
│   │   ├── LeaderboardController.php
│   │   └── SyncController.php
│   ├── Dto
│   │   ├── AcademyInitRequest.php
│   │   ├── MarketAssignRequest.php
│   │   ├── MarketDataResponse.php
│   │   └── SyncRequest.php
│   ├── Entity
│   │   ├── Academy.php
│   │   ├── Admin.php
│   │   ├── Agent.php
│   │   ├── Facility.php
│   │   ├── GameEventTemplate.php
│   │   ├── Guardian.php
│   │   ├── InboxMessage.php
│   │   ├── Investor.php
│   │   ├── LeaderboardEntry.php
│   │   ├── PersonalityProfile.php
│   │   ├── Player.php
│   │   ├── PlayerArchetype.php
│   │   ├── Scout.php
│   │   ├── Sponsor.php
│   │   ├── Staff.php
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
│   │   ├── GameEventTemplateRepository.php
│   │   ├── InboxMessageRepository.php
│   │   ├── InvestorRepository.php
│   │   ├── LeaderboardEntryRepository.php
│   │   ├── PlayerArchetypeRepository.php
│   │   ├── PlayerRepository.php
│   │   ├── ScoutRepository.php
│   │   ├── SponsorRepository.php
│   │   ├── StaffRepository.php
│   │   └── TransferRepository.php
│   ├── Service
│   │   ├── AcademyInitializationService.php
│   │   ├── EconomicService.php
│   │   ├── FacilityService.php
│   │   ├── InboxService.php
│   │   ├── MarketDataService.php
│   │   ├── MarketPoolService.php
│   │   ├── SyncService.php
│   │   └── TransferLeaderboardService.php
│   └── Kernel.php
├── templates
│   ├── admin
│   │   ├── dashboard.html.twig
│   │   ├── login.html.twig
│   │   └── settings.html.twig
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

34 directories, 141 files
```

---

## Database Entities

### Available Entities

#### Academy
```php
class Academy
{
    private UuidV7 $id;
    private string $name;
    private int $reputation = 0;
    private int $totalCareerEarnings = 0;
    private int $hallOfFamePoints = 0;
    private int $lastSyncedWeek = 0;
    private ?\DateTimeImmutable $lastSyncedAt = null;
    private int $marketPoolSize = 20;
    private int $financialYearStart = 4;
    private ?string $paName = null;
    private int $managerTemperament = 50;
    private int $managerDiscipline = 50;
    private int $managerAmbition = 50;
    private int $balance = 0;
    private \DateTimeImmutable $createdAt;
    private User $user;
    private Collection $players;
    private Collection $staff;
    private Collection $transfers;
    private Collection $syncRecords;
```

#### Admin
```php
class Admin
{
    private UuidV7 $id;
    private User $user;
    private ?string $department = null;
    private int $accessLevel = 1;
    private \DateTimeImmutable $createdAt;
    public function __construct(User $user)
    public function getId(): UuidV7 { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getDepartment(): ?string { return $this->department; }
    public function setDepartment(?string $department): void { $this->department = $department; }
    public function getAccessLevel(): int { return $this->accessLevel; }
    public function setAccessLevel(int $accessLevel): void { $this->accessLevel = $accessLevel; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
```

#### Agent
```php
class Agent
{
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
    public function getReputation(): int { return $this->reputation; }
    public function setReputation(int $reputation): void { $this->reputation = $reputation; }
    public function getCommissionRate(): string { return $this->commissionRate; }
    public function setCommissionRate(string $rate): void { $this->commissionRate = $rate; }
    public function getPlayers(): Collection { return $this->players; }
```

#### Facility
```php
class Facility
{
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
    public function canUpgrade(): bool
    public function getUpgradeCost(): int
    public function getCurrentEffect(): string
```

#### GameEventTemplate
```php
class GameEventTemplate
{
    private UuidV7 $id;
    private string $slug;
    private EventCategory $category;
    private int $weight = 1;
    private string $title;
    private string $bodyTemplate;
    private array $impacts = [];
    private \DateTimeImmutable $createdAt;
    public function __construct(
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

#### Guardian
```php
class Guardian
{
    private UuidV7 $id;
    private string $firstName;
    private string $lastName;
    private ?string $contactEmail = null;
    private int $demandLevel = 5;
    private int $loyaltyToAcademy = 50;
    private Player $player;
    public function __construct(string $firstName, string $lastName, Player $player)
    public function getId(): UuidV7 { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }
    public function getFullName(): string { return "{$this->firstName} {$this->lastName}"; }
    public function getContactEmail(): ?string { return $this->contactEmail; }
    public function setContactEmail(?string $email): void { $this->contactEmail = $email; }
    public function getDemandLevel(): int { return $this->demandLevel; }
    public function setDemandLevel(int $level): void { $this->demandLevel = max(1, min(10, $level)); }
    public function getLoyaltyToAcademy(): int { return $this->loyaltyToAcademy; }
    public function setLoyaltyToAcademy(int $loyalty): void { $this->loyaltyToAcademy = max(0, min(100, $loyalty)); }
```

#### InboxMessage
```php
class InboxMessage
{
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
    public function getSenderType(): MessageSenderType { return $this->senderType; }
    public function getSenderName(): string { return $this->senderName; }
    public function getSubject(): string { return $this->subject; }
    public function getBody(): string { return $this->body; }
    public function getOfferData(): ?array { return $this->offerData; }
```

#### Investor
```php
class Investor
{
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
    public function getCompany(): string { return $this->company; }
    public function setCompany(string $company): void { $this->company = $company; }
    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }
    public function getSize(): CompanySize { return $this->size; }
```

#### LeaderboardEntry
```php
class LeaderboardEntry
{
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
    public function getRank(): ?int { return $this->rank; }
    public function setRank(?int $rank): void { $this->rank = $rank; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
```

#### PersonalityProfile
```php
class PersonalityProfile
{
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
    public function setLeadership(int $v): void { $this->leadership = $this->clamp($v); }
    public function getEgo(): int { return $this->ego; }
    public function setEgo(int $v): void { $this->ego = $this->clamp($v); }
    public function getBravery(): int { return $this->bravery; }
    public function setBravery(int $v): void { $this->bravery = $this->clamp($v); }
```

#### Player
```php
class Player
{
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
    private ?Guardian $guardian = null;
    private ?Agent $agent = null;
    private Collection $siblings;
    private int $pace = 0;
    private int $technical = 0;
    private int $vision = 0;
    private int $power = 0;
```

#### PlayerArchetype
```php
class PlayerArchetype
{
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
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
```

#### Scout
```php
class Scout
{
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
    public function getJudgements(): array { return $this->judgements; }
    public function setJudgements(array $judgements): void { $this->judgements = $judgements; }
    public function getExperience(): int { return $this->experience; }
    public function setExperience(int $experience): void { $this->experience = $experience; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
```

#### Sponsor
```php
class Sponsor
{
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
    private ?\DateTimeImmutable $assignedAt = null;
    private ?\DateTimeImmutable $lastPaymentAt = null;
    public function __construct(string $company = '')
    public function getId(): UuidV7 { return $this->id; }
    public function getCompany(): string { return $this->company; }
```

#### Staff
```php
class Staff
{
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
    private \DateTimeImmutable $hiredAt;
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }
    public function getFullName(): string { return "{$this->firstName} {$this->lastName}"; }
```

#### SyncRecord
```php
class SyncRecord
{
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
    public function isValid(): bool { return $this->isValid; }
    public function markInvalid(string $reason): void
    public function getInvalidReason(): ?string { return $this->invalidReason; }
```

#### Transfer
```php
class Transfer
{
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
    public function getPlayer(): ?Player { return $this->player; }
    public function getAcademy(): Academy { return $this->academy; }
    public function getDestinationClubName(): string { return $this->destinationClubName; }
    public function setDestinationClubName(string $name): void { $this->destinationClubName = $name; }
    public function getType(): TransferType { return $this->type; }
```

#### User
```php
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ACADEMY = 'ROLE_ACADEMY';
    public const ROLE_ADMIN   = 'ROLE_ADMIN';
    private UuidV7 $id;
    private string $email;
    private string $password;
    private array $roles = [];
    private ?Academy $academy = null;
    private ?Admin $admin = null;
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
    public function eraseCredentials(): void {}
    public function getAcademy(): ?Academy { return $this->academy; }
```


---

## API Routes

```
```

---

## Controllers

### AdminSecurityController

```php
    #[Route('/admin/login', name: 'admin_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    #[Route('/admin/logout', name: 'admin_logout')]
    public function logout(): never
```

### LeaderboardController

```php
#[Route('/api')]
    #[Route('/leaderboard/{category}', name: 'api_leaderboard', methods: ['GET'])]
    public function index(
```

### SyncController

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

### AcademyInitializationService

```php
class AcademyInitializationService
{
    public function __construct(
    public function initializeAcademy(User $user, string $academyName): Academy
    public function getStarterBundle(): array
```

### EconomicService

```php
class EconomicService
{
    public function __construct(
    public function generateSponsorOffer(Academy $academy): array
    public function generateInvestorOffer(Academy $academy): array
    public function calculatePlayerMarketValue(Player $player): int
    public function processFinancialYearEnd(Academy $academy): void
    public function checkSponsorContracts(Academy $academy, int $currentReputation): void
    public function checkAgeOutPlayers(Academy $academy, int $currentWeek, \DateTimeImmutable $clientTimestamp): void
```

### FacilityService

```php
class FacilityService
{
    public function __construct(
    public function getAcademyFacilitiesData(Academy $academy): array
    public function upgradeFacility(Facility $facility): void
    public function initializeFacilities(Academy $academy): void
```

### InboxService

```php
class InboxService
{
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

### MarketDataService

```php
class MarketDataService
{
    public function __construct(private readonly MarketPoolService $pool) {}
    public function getMarketSnapshot(): MarketDataResponse
```

### MarketPoolService

```php
class MarketPoolService
{
    public function __construct(
    public function generatePlayers(int $count, ?int $academyReputation = null): array
    public function generateCoaches(int $count, ?int $academyReputation = null): array
    public function generateScouts(int $count): array
    public function generateAgents(int $count): array
    public function generateSponsors(int $count, CompanySize $preferredSize = CompanySize::SMALL): array
    public function generateInvestors(int $count, CompanySize $preferredSize = CompanySize::SMALL): array
    public function getAvailablePlayers(int $limit = 100): array
    public function getAvailableCoaches(int $limit = 20): array
    public function getAvailableScouts(int $limit = 10): array
```

### SyncService

```php
class SyncService
{
    public function __construct(
    public function process(User $user, SyncRequest $request): array
```

### TransferLeaderboardService

```php
class TransferLeaderboardService
{
    public function __construct(
    public function getTopSellers(string $period = 'week', int $limit = 10): array
    public function getMostValuableSale(string $period = 'week'): ?array
```


---

## Security Configuration

```yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'

    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: email

    firewalls:
        dev:
            pattern: ^/(_profiler|_wdt|assets|build)/
            security: false

        admin:
            pattern: ^/admin
            lazy: true
            provider: app_user_provider
            form_login:
                login_path: admin_login
                check_path: admin_login
                default_target_path: /admin
            logout:
                path: admin_logout
                target: admin_login

        login:
            pattern: ^/api/login
            stateless: true
            json_login:
                check_path: /api/login
                success_handler: lexik_jwt_authentication.handler.authentication_success
                failure_handler: lexik_jwt_authentication.handler.authentication_failure

        api:
            pattern: ^/api
            stateless: true
            jwt: ~

    access_control:
        # Allow CORS preflight requests through without JWT authentication.
        # NelmioCorsBundle handles OPTIONS and returns the correct headers.
        - { path: ^/api, roles: PUBLIC_ACCESS, methods: [OPTIONS] }
        - { path: ^/admin/login,  roles: PUBLIC_ACCESS }
        - { path: ^/admin,        roles: ROLE_ADMIN }
        - { path: ^/api/login,    roles: PUBLIC_ACCESS }
        - { path: ^/api/register, roles: PUBLIC_ACCESS }
        - { path: ^/api/leaderboard/transfers, roles: PUBLIC_ACCESS }
        - { path: ^/api/admin/,   roles: ROLE_ADMIN }
        - { path: ^/api/sync,             roles: ROLE_ACADEMY }
        - { path: ^/api/market/data,     roles: ROLE_ACADEMY }
        - { path: ^/api/market/assign,   roles: ROLE_ACADEMY }
        - { path: ^/api/academy/,        roles: ROLE_ACADEMY }
        - { path: ^/api/inbox,           roles: ROLE_ACADEMY }
        - { path: ^/api/finance/,        roles: ROLE_ACADEMY }
        - { path: ^/api/events/,         roles: ROLE_ACADEMY }
        - { path: ^/api/archetypes,      roles: ROLE_ACADEMY }
        - { path: ^/api/squad,           roles: ROLE_ACADEMY }
        - { path: ^/api/staff,           roles: ROLE_ACADEMY }
        - { path: ^/api/facilities,      roles: ROLE_ACADEMY }
        - { path: ^/api,                 roles: IS_AUTHENTICATED_FULLY }

when@test:
    security:
        password_hashers:
            Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface:
                algorithm: auto
                cost: 4
                time_cost: 3
                memory_cost: 10
```

---

## Environment Configuration

### Required Environment Variables

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
```

---

## Development Setup

### Local Development with Lando

```bash
# Start the environment
lando start

# Install dependencies
lando composer install

# Database setup
lando php bin/console doctrine:database:create
lando php bin/console doctrine:migrations:migrate

# Clear cache
lando php bin/console cache:clear
```

### Useful Commands

```bash
# View logs
lando logs -s appserver

# Run tests
lando php bin/phpunit

# Debug routes
lando php bin/console debug:router

# Debug firewall
lando php bin/console debug:firewall
```

---

## Recent Development Activity

```
2ec0448 feat: expand PlayerArchetype to 30 archetypes with formula/threshold schema
194625c feat: PlayerArchetype entity, API endpoint, admin CRUD, and seed data
e7a466e fix: truncate user table fully before restoring admins in reset script
3de4241 feat: player development system — granular attributes + staff specialisms
06d49c8 fix: strip carriage returns from MySQL output in reset_and_seed.sh
88a4485 chore: add numbered review/edit loop before seed execution
1e35c94 chore: add interactive seed configuration to reset_and_seed.sh
5a34b4e feat: agent-assisted transfer support
419629e fix: correct training_argument category from STAFF to PLAYER
95f316a feat: transfer leaderboard system + admin entity fixes
```

---

## Notes for AI Context

### Current Focus Areas
- JWT Authentication implementation
- Leaderboard sync endpoints
- Admin UI development
- Academy management system

### Key Design Patterns
- Repository pattern for data access
- Service layer for business logic
- DTO pattern for API requests/responses
- Event-driven architecture where applicable

### Testing Strategy
- Unit tests for services
- Integration tests for repositories
- API tests for controllers

---

## Additional Resources

- [Symfony Documentation](https://symfony.com/doc/current/index.html)
- [Doctrine ORM](https://www.doctrine-project.org/projects/doctrine-orm/en/latest/)
- [JWT Authentication Bundle](https://github.com/lexik/LexikJWTAuthenticationBundle)

