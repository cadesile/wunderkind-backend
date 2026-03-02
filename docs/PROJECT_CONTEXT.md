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
│   └── PROJECT_CONTEXT.md
├── migrations
│   ├── Version20260301214628.php
│   ├── Version20260302000001.php
│   └── Version20260302000002.php
├── public
│   ├── bundles
│   │   ├── apiplatform
│   │   └── easyadmin
│   ├── admin-theme.css
│   └── index.php
├── scripts
│   └── generate_project_context.sh
├── src
│   ├── ApiResource
│   ├── Command
│   │   └── GenerateMarketDataCommand.php
│   ├── Controller
│   │   ├── Admin
│   │   ├── Api
│   │   ├── AdminSecurityController.php
│   │   ├── LeaderboardController.php
│   │   └── SyncController.php
│   ├── Dto
│   │   ├── MarketDataResponse.php
│   │   └── SyncRequest.php
│   ├── Entity
│   │   ├── Academy.php
│   │   ├── Admin.php
│   │   ├── Agent.php
│   │   ├── Guardian.php
│   │   ├── Investor.php
│   │   ├── LeaderboardEntry.php
│   │   ├── PersonalityProfile.php
│   │   ├── Player.php
│   │   ├── Scout.php
│   │   ├── Sponsor.php
│   │   ├── Staff.php
│   │   ├── SyncRecord.php
│   │   ├── Transfer.php
│   │   └── User.php
│   ├── Enum
│   │   ├── CompanySize.php
│   │   ├── LeaderboardCategory.php
│   │   ├── PlayerPosition.php
│   │   ├── PlayerStatus.php
│   │   ├── RecruitmentSource.php
│   │   ├── StaffRole.php
│   │   └── TransferType.php
│   ├── EventSubscriber
│   │   └── DomainSeparationSubscriber.php
│   ├── Repository
│   │   ├── AcademyRepository.php
│   │   ├── AdminRepository.php
│   │   ├── AgentRepository.php
│   │   ├── InvestorRepository.php
│   │   ├── LeaderboardEntryRepository.php
│   │   ├── ScoutRepository.php
│   │   └── SponsorRepository.php
│   ├── Service
│   │   └── SyncService.php
│   └── Kernel.php
├── templates
│   ├── admin
│   │   ├── dashboard.html.twig
│   │   └── login.html.twig
│   └── base.html.twig
├── translations
├── CLAUDE.md
├── compose.override.yaml
├── compose.yaml
├── composer.json
├── composer.lock
├── project_plan.md
├── README.md
└── symfony.lock

28 directories, 83 files
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
    private \DateTimeImmutable $createdAt;
    private User $user;
    private Collection $players;
    private Collection $staff;
    private Collection $transfers;
    private Collection $syncRecords;
    private Collection $leaderboardEntries;
    public function __construct(string $name, User $user)
    public function __toString(): string { return $this->name; }
    public function getId(): UuidV7 { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getReputation(): int { return $this->reputation; }
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
    private bool $isUniversal = true;
    private int $reputation = 50;
    private string $commissionRate = '10.00';
    private ?\DateTimeImmutable $dob = null;
    private ?string $nationality = null;
    private array $judgements = [];
    private int $experience = 0;
    private int $rating = 50;
    private Collection $players;
    public function __construct(string $name, bool $isUniversal = true)
    public function __toString(): string { return $this->name; }
    public function getId(): UuidV7 { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function isUniversal(): bool { return $this->isUniversal; }
    public function setIsUniversal(bool $v): void { $this->isUniversal = $v; }
    public function getReputation(): int { return $this->reputation; }
    public function setReputation(int $reputation): void { $this->reputation = $reputation; }
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

#### Investor
```php
class Investor
{
    private UuidV7 $id;
    private string $company;
    private ?string $nationality = null;
    private CompanySize $size = CompanySize::MEDIUM;
    private bool $isActive = true;
    private \DateTimeImmutable $createdAt;
    public function __construct(string $company)
    public function getId(): UuidV7 { return $this->id; }
    public function getCompany(): string { return $this->company; }
    public function setCompany(string $company): void { $this->company = $company; }
    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }
    public function getSize(): CompanySize { return $this->size; }
    public function setSize(CompanySize $size): void { $this->size = $size; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): void { $this->isActive = $isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpectedReturnPercentage(): int
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
    private Academy $academy;
    private ?Guardian $guardian = null;
    private ?Agent $agent = null;
    private Collection $siblings;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    public function __construct(
    public function onPreUpdate(): void
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
    public function __construct(string $name)
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
    private \DateTimeImmutable $createdAt;
    public function __construct(string $company)
    public function getId(): UuidV7 { return $this->id; }
    public function getCompany(): string { return $this->company; }
    public function setCompany(string $company): void { $this->company = $company; }
    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }
    public function getSize(): CompanySize { return $this->size; }
    public function setSize(CompanySize $size): void { $this->size = $size; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): void { $this->isActive = $isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpectedReturnPercentage(): int
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
    private Academy $academy;
    private \DateTimeImmutable $hiredAt;
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }
    public function getFullName(): string { return "{$this->firstName} {$this->lastName}"; }
    public function getRole(): StaffRole { return $this->role; }
    public function setRole(StaffRole $role): void { $this->role = $role; }
    public function getRoleValue(): string { return $this->role->value; }
    public function getCoachingAbility(): int { return $this->coachingAbility; }
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
    private Player $player;
    private Academy $academy;
    private string $destinationClubName;
    private TransferType $type;
    private int $fee = 0;
    private int $agentCommission = 0;
    private \DateTimeImmutable $occurredAt;
    private ?\DateTimeImmutable $syncedAt = null;
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getPlayer(): Player { return $this->player; }
    public function getAcademy(): Academy { return $this->academy; }
    public function getDestinationClubName(): string { return $this->destinationClubName; }
    public function setDestinationClubName(string $name): void { $this->destinationClubName = $name; }
    public function getType(): TransferType { return $this->type; }
    public function getTypeValue(): string { return $this->type->value; }
    public function getFee(): int { return $this->fee; }
    public function setFee(int $fee): void { $this->fee = $fee; }
    public function getAgentCommission(): int { return $this->agentCommission; }
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

### SyncService

```php
class SyncService
{
    public function __construct(
    public function process(User $user, SyncRequest $request): array
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
        - { path: ^/admin/login,  roles: PUBLIC_ACCESS }
        - { path: ^/admin,        roles: ROLE_ADMIN }
        - { path: ^/api/login,    roles: PUBLIC_ACCESS }
        - { path: ^/api/register, roles: PUBLIC_ACCESS }
        - { path: ^/api/admin/,   roles: ROLE_ADMIN }
        - { path: ^/api/sync,     roles: ROLE_ACADEMY }
        - { path: ^/api,          roles: IS_AUTHENTICATED_FULLY }

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
90b8207 fix: add Agent::__toString() so PlayerCrudController AssociationField renders
617d6c5 feat: extend seeder + writable admin CRUD for Player and Staff
dbd0810 feat: market entity expansion — Scout/Investor/Sponsor + seeder command
6dc85ab feat: introduce ROLE_ACADEMY / ROLE_ADMIN domain separation
7bb3df8 Merge pull request #2 from cadesile/fix/admin-enum-fields
c272265 Fix EasyAdmin enum-to-string crash on admin list views
a05761a Create project_plan.md
bf80f6a Add frontend integration prompt doc
c9bfd42 Fix EasyAdmin v5 MenuItem API in DashboardController
7357a78 Merge pull request #1 from cadesile/feat/admin-ui
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

