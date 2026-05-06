# wunderkind-backend — Project Context

> Generated: 2026-05-06 22:18:45 | Duration: 38s | Stack: symfony 80 · PHP 8.4 · postgres:16 | Dev: lando

---

## Overview

The Wunderkind Factory backend is a Symfony 8.0 REST API powering a mobile-first youth football academy management game, where gameplay runs client-side and this server handles sync validation, global leaderboards, and market data. It follows a client-authoritative hybrid model: the device owns all game state (training, aging, weekly ticks), while the API enforces anti-cheat rules, persists aggregate metrics, and serves shared data like player markets, sponsors, and investors. Built on PHP 8.4 with PostgreSQL 16 and JWT authentication, it exposes a lean set of endpoints consumed by the React Native mobile client.

---

## Document Context

### [CLAUDE.md](CLAUDE.md)
> AI Summary: CLAUDE.md is a developer guide for the Wunderkind backend repository, covering how to run PHP/Symfony commands inside the Lando container, manage the PostgreSQL database, follow git branching conventions, and understand the client-authoritative sync architecture.

### [docs/event-guide.md](docs/event-guide.md)
> AI Summary: The `event-guide.md` documents the configuration format for game events in Wunderkind Factory's fat-client architecture, where event impacts are defined as JSON arrays of mutation objects with a `target` path and numerical `delta`. It specifies all valid mutation targets across two categories: availability/health fields (`injuredWeeks`, `morale`, `isActive`) and the eight-trait `PersonalityMatrix` (`determination`, `professionalism`, `ambition`, `loyalty`, `consistency`, `adaptability`, `pressure`, `temperament`). A key architectural decision documented here is that personality traits are clamped to a 1–20 scale client-side by the `squadStore`, keeping validation logic on-device consistent with the fat-client model. The guide serves as the contract between the server-side `GameEventTemplate.impacts` JSON column and the client's weekly tick simulation engine.

### [docs/frontend-integration.md](docs/frontend-integration.md)
> AI Summary: The `docs/frontend-integration.md` file is an integration guide for connecting a React Native (MMKV-based) client to the Wunderkind backend API, covering environment configuration, authentication flow, and endpoint contracts. It documents the auth lifecycle — checking MMKV for a stored JWT on launch, registering/logging in if absent, and retrying once on `401` — with `403` treated as a hard stop. A core architectural decision is that the academy's liquid `balance` (stored server-side in pence/cents) is the canonical financial state, updated on every sync via a defined set of credit/debit events including earnings deltas, staff/player wages, sponsor payments, and investor payouts, with debt (negative balance) being an explicitly supported state surfaced via `hasDebt`. The guide appears to be a living reference for typed API client setup, with the endpoint section cut off mid-content (`### POST /a`), suggesting it was still being written.

### [docs/superpowers/plans/2026-04-14-npc-club-generation.md](docs/superpowers/plans/2026-04-14-npc-club-generation.md)
> AI Summary: This plan documents the implementation of **NPC Club Generation** for the Wunderkind backend's "Club Sim" expansion (Spec A). Its core purpose is to add persistent NPC clubs as pure metadata (no player foreign keys), a shared senior/youth player pool, and a `POST /api/market/consume` endpoint for clients to claim and hard-delete market entities. A key architectural decision is that the **backend remains a producer, not a tracker** — NPC clubs store only descriptive data, and the frontend owns the lifecycle of claimed entities. The plan spans ~25 discrete file-level tasks covering new entities (`NpcClub`), enum extensions (`StaffRole`, `RecruitmentSource`), service additions (`NpcClubGenerationService`, senior player generation in `MarketPoolService`), a Doctrine migration, EasyAdmin CRUD wiring, and a new REST endpoint — all implemented inside the Lando/PHP 8.4/PostgreSQL 16 stack.

### [docs/superpowers/plans/2026-04-18-admin-starter-config-league-ability-ranges.md](docs/superpowers/plans/2026-04-18-admin-starter-config-league-ability-ranges.md)
> AI Summary: This plan implements a dynamic configuration matrix in the `StarterConfig` entity to manage player ability ranges per country/league tier, stored as a JSON column in the database. The backend uses EasyAdmin 5 to dynamically generate form inputs based on countries/tiers present in the database, while the frontend TypeScript interface is updated with a `leagueAbilityRanges` field typed as `Record<string, Record<number, { min: number; max: number }>>`. The architecture deliberately chooses a JSON column over a normalized relational table for flexibility, allowing the matrix structure to evolve without schema migrations when new countries or tiers are added. The plan spans both repositories (backend PHP/Symfony and frontend React Native), with task-by-task tracking using checkbox syntax intended for agentic execution via the `superpowers:subagent-driven-development` skill.

### [docs/superpowers/specs/2026-04-14-npc-club-generation-design.md](docs/superpowers/specs/2026-04-14-npc-club-generation-design.md)
> AI Summary: This spec defines the backend design for NPC Club Generation (Spec A of the Club Sim expansion). Its core purpose is to add persistent `NpcClub` entities to the backend and extend the market pool with senior players (ages 17–35), without modeling any live club–player relationships — clubs are pure metadata snapshots with no FK ties to `Player` or `Staff`, with squads assembled client-side at game-start.

A key architectural decision is that the backend remains a **producer, not a tracker**: all claimed entities are hard-deleted via a new `POST /api/market/consume` endpoint (idempotent by design), while `NpcClub` is the sole exception that persists — storing only identity data (name, country, tier, colors, facilities as a flat JSON map). Facility levels, reputation, and starting balance are all tier-banded at generation time by `NpcClubGenerationService`, with club names assembled from hardcoded per-country place name arrays combined with universal suffixes.

The spec also adds three new `StaffRole` enum values (MANAGER, DIRECTOR_OF_FOOTBALL, FACILITY_MANAGER), a `Stadium` facility category, new `PoolConfig` senior player fields, and an admin UI section ("Clubs & Leagues") mirroring the existing EasyAdmin patterns. Country entity, League/Competition structure, and all frontend changes are explicitly deferred to Spec B.

### [docs/wunderkind-backend-context.md](docs/wunderkind-backend-context.md)
> AI Summary: The `wunderkind-backend-context.md` is an auto-generated project overview that serves as a high-level entry point into the codebase, summarizing the stack (Symfony 8, PHP 8.4, PostgreSQL 16, JWT, EasyAdmin v5) and the client-authoritative hybrid sync architecture where all gameplay runs on-device and the API handles only sync, leaderboards, and anti-cheat. It aggregates AI-generated summaries of key documentation files (`CLAUDE.md` and `docs/event-guide.md`), acting as a navigational index rather than a source of new information. A notable architectural decision it surfaces is the event impact contract: server-authored `GameEventTemplate.impacts` are JSON mutation arrays the client applies locally, reinforcing that the server defines *what* can happen while the device executes it. The file is intended to orient developers (and AI assistants) quickly without requiring deep reading of individual source files.

### [migrations/archive/README.md](migrations/archive/README.md)
> AI Summary: The archived MySQL migrations folder contains 29 old MySQL 8.0-specific migrations that were replaced by a single PostgreSQL baseline migration during the 2026-03-26 database migration.

### [project_plan.md](project_plan.md)
> AI Summary: Wunderkind Factory is a mobile youth football academy strategy game where players scout and develop talent, navigate personality-driven relationships with agents and guardians, and compete on global leaderboards — built on a client-authoritative offline-first architecture with a React Native frontend and Symfony/API Platform backend.

### [README.md](README.md)
> AI Summary: The Wunderkind Factory backend is a Symfony 8.0/PHP 8.4 API powering a mobile youth football academy management game, handling legacy metric syncing, anti-cheat validation, and global leaderboards via a client-authoritative hybrid sync model.

### [wunderkind-backend-context.md](wunderkind-backend-context.md)
> AI Summary: This file is a snapshot of the Wunderkind backend codebase — summarizing file counts, PHP line counts, controller count, and the full technology stack (Symfony 8, API Platform 4, Doctrine ORM 3, EasyAdmin 5, JWT auth, PostgreSQL).

---

## Metrics

| Category | Count |
|---|---|
| PHP files         | 280 |
| Entities/Models   | 29 |
| Controllers       | 40 |
| Services          | 15 |
| Migrations        | 81 |

---

## Technology Stack

| | |
|---|---|
| **Language**      | php |
| **Framework**     | symfony 80 |
| **PHP**           | 8.4 |
| **Database**      | postgres:16 |
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
- `gesdinet/jwt-refresh-token-bundle`: ^2.0
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
- `phpunit/phpunit`: ^13.1
- `symfony/browser-kit`: 8.0.*
- `symfony/css-selector`: 8.0.*
- `symfony/maker-bundle`: ^1.66

---

## Project Structure

```
.
├── bin
│   ├── console
│   └── phpunit
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
│   │   ├── gesdinet_jwt_refresh_token.yaml
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
├── docker
│   ├── jwt-entrypoint.sh
│   ├── nginx-http-only.conf
│   ├── nginx.conf
│   └── supervisord.conf
├── docs
│   ├── superpowers
│   │   ├── plans
│   │   └── specs
│   ├── event-guide.md
│   ├── frontend-integration.md
│   ├── wunderkind-backend-context.md
│   ├── wunderkind-backend-context.md.tmp
│   └── wunderkind-narrative-2026-04-21.json
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
│   ├── Version20260326234223.php
│   ├── Version20260327000001.php
│   ├── Version20260327000002.php
│   ├── Version20260329000001.php
│   ├── Version20260329173338.php
│   ├── Version20260329193805.php
│   ├── Version20260329214559.php
│   ├── Version20260330174208.php
│   ├── Version20260331081012.php
│   ├── Version20260331191834.php
│   ├── Version20260401085917.php
│   ├── Version20260412000001.php
│   ├── Version20260412212252.php
│   ├── Version20260414081725.php
│   ├── Version20260414081949.php
│   ├── Version20260414120911.php
│   ├── Version20260414120935.php
│   ├── Version20260414200155.php
│   ├── Version20260414213004.php
│   ├── Version20260414225753.php
│   ├── Version20260415224451.php
│   ├── Version20260416205447.php
│   ├── Version20260417175315.php
│   ├── Version20260417185449.php
│   ├── Version20260418121622.php
│   ├── Version20260418124854.php
│   ├── Version20260419105604.php
│   ├── Version20260419160039.php
│   ├── Version20260421184302.php
│   ├── Version20260422221445.php
│   ├── Version20260423204907.php
│   ├── Version20260423220216.php
│   ├── Version20260426000001.php
│   ├── Version20260426000002.php
│   ├── Version20260429090303.php
│   ├── Version20260429095810.php
│   ├── Version20260502191637.php
│   ├── Version20260502230625.php
│   ├── Version20260503074622.php
│   ├── Version20260503100000.php
│   ├── Version20260503110000.php
│   ├── Version20260503120000.php
│   ├── Version20260504000000.php
│   ├── Version20260504010000.php
│   ├── Version20260504020000.php
│   ├── Version20260504030000.php
│   ├── Version20260504040000.php
│   ├── Version20260504050000.php
│   ├── Version20260504165645.php
│   └── Version20260504200100.php
├── public
│   ├── bundles
│   │   ├── apiplatform
│   │   └── easyadmin
│   ├── images
│   │   └── logo.webp
│   ├── screenshots
│   │   ├── dashboard.png
│   │   ├── league.png
│   │   ├── manager-creation.png
│   │   ├── office.png
│   │   ├── Screenshot_20260503-133022.png
│   │   ├── Screenshot_20260503-133029.png
│   │   ├── Screenshot_20260503-133051.png
│   │   ├── Screenshot_20260503-133105.png
│   │   ├── Screenshot_20260503-133111.png
│   │   ├── Screenshot_20260503-133254.png
│   │   ├── Screenshot_20260503-133326.png
│   │   ├── Screenshot_20260503-133337.png
│   │   ├── Screenshot_20260503-133447.png
│   │   ├── Screenshot_20260503-133454.png
│   │   ├── Screenshot_20260503-133457.png
│   │   ├── Screenshot_20260503-133504.png
│   │   ├── Screenshot_20260503-133511.png
│   │   ├── Screenshot_20260503-141436.png
│   │   ├── Screenshot_20260503-141443.png
│   │   ├── Screenshot_20260503-141455.png
│   │   ├── squad.png
│   │   └── welcome.png
│   ├── admin-login.css
│   ├── index.html
│   └── index.php
├── scripts
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
│   │   └── SetExistingClubBalancesCommand.php
│   ├── Controller
│   │   ├── Admin
│   │   ├── Api
│   │   ├── AdminSecurityController.php
│   │   ├── HomeController.php
│   │   ├── InitializeController.php
│   │   ├── LeaderboardController.php
│   │   └── SyncController.php
│   ├── Doctrine
│   │   └── Function
│   ├── Dto
│   │   ├── ClubInitRequest.php
│   │   ├── ConcludeSeasonRequest.php
│   │   ├── ConsumeRequest.php
│   │   ├── LedgerEntrySyncDto.php
│   │   ├── MarketAssignRequest.php
│   │   ├── MarketDataResponse.php
│   │   ├── MatchResultDto.php
│   │   ├── SyncRequest.php
│   │   └── TransferSyncDto.php
│   ├── Entity
│   │   ├── Admin.php
│   │   ├── Agent.php
│   │   ├── Club.php
│   │   ├── FacilityTemplate.php
│   │   ├── GameConfig.php
│   │   ├── GameEventTemplate.php
│   │   ├── Guardian.php
│   │   ├── InboxMessage.php
│   │   ├── Investor.php
│   │   ├── LeaderboardEntry.php
│   │   ├── League.php
│   │   ├── LeagueSponsor.php
│   │   ├── MatchResult.php
│   │   ├── NpcClub.php
│   │   ├── PersonalityProfile.php
│   │   ├── Player.php
│   │   ├── PlayerArchetype.php
│   │   ├── PoolConfig.php
│   │   ├── RefreshToken.php
│   │   ├── Scout.php
│   │   ├── SeasonRecord.php
│   │   ├── SeasonSnapshot.php
│   │   ├── Sponsor.php
│   │   ├── Staff.php
│   │   ├── StarterConfig.php
│   │   ├── SyncRecord.php
│   │   ├── TacticalAdvantage.php
│   │   ├── Transfer.php
│   │   └── User.php
│   ├── Enum
│   │   ├── CompanySize.php
│   │   ├── EventCategory.php
│   │   ├── FinancialApproach.php
│   │   ├── Formation.php
│   │   ├── InvestorTier.php
│   │   ├── LeaderboardCategory.php
│   │   ├── MarketEntityType.php
│   │   ├── MessageSenderType.php
│   │   ├── MessageStatus.php
│   │   ├── PlayerPosition.php
│   │   ├── PlayerStatus.php
│   │   ├── PlayingStyle.php
│   │   ├── RecruitmentSource.php
│   │   ├── ReputationTier.php
│   │   ├── SponsorStatus.php
│   │   ├── StaffRole.php
│   │   ├── Tier.php
│   │   └── TransferType.php
│   ├── EventSubscriber
│   │   └── DomainSeparationSubscriber.php
│   ├── Form
│   │   └── Type
│   ├── Repository
│   │   ├── AdminRepository.php
│   │   ├── AgentRepository.php
│   │   ├── ClubRepository.php
│   │   ├── FacilityTemplateRepository.php
│   │   ├── GameConfigRepository.php
│   │   ├── GameEventTemplateRepository.php
│   │   ├── GuardianRepository.php
│   │   ├── InboxMessageRepository.php
│   │   ├── InvestorRepository.php
│   │   ├── LeaderboardEntryRepository.php
│   │   ├── LeagueRepository.php
│   │   ├── MatchResultRepository.php
│   │   ├── NpcClubRepository.php
│   │   ├── PlayerArchetypeRepository.php
│   │   ├── PlayerRepository.php
│   │   ├── PoolConfigRepository.php
│   │   ├── ScoutRepository.php
│   │   ├── SeasonRecordRepository.php
│   │   ├── SeasonSnapshotRepository.php
│   │   ├── SponsorRepository.php
│   │   ├── StaffRepository.php
│   │   ├── StarterConfigRepository.php
│   │   ├── TacticalAdvantageRepository.php
│   │   └── TransferRepository.php
│   ├── Service
│   │   ├── ClubInitializationService.php
│   │   ├── ConfigImportExportService.php
│   │   ├── EconomicService.php
│   │   ├── FixtureGenerationService.php
│   │   ├── InboxService.php
│   │   ├── LeagueImportExportService.php
│   │   ├── LeagueService.php
│   │   ├── MarketDataService.php
│   │   ├── MarketPoolService.php
│   │   ├── NameGeneratorService.php
│   │   ├── NarrativeImportExportService.php
│   │   ├── NpcClubGenerationService.php
│   │   ├── SyncService.php
│   │   ├── TransferLeaderboardService.php
│   │   └── WorldInitializationService.php
│   └── Kernel.php
├── templates
│   ├── admin
│   │   ├── _macros.html.twig
│   │   ├── app_links.html.twig
│   │   ├── club_profile.html.twig
│   │   ├── config_content.html.twig
│   │   ├── dashboard.html.twig
│   │   ├── game_config.html.twig
│   │   ├── login.html.twig
│   │   ├── narrative_content.html.twig
│   │   ├── npc_clubs_content.html.twig
│   │   ├── player_index.html.twig
│   │   ├── pool_config.html.twig
│   │   ├── settings.html.twig
│   │   ├── starter_config.html.twig
│   │   └── world_content.html.twig
│   └── base.html.twig
├── tests
│   ├── Controller
│   │   ├── Admin
│   │   └── Api
│   ├── Dto
│   │   └── MatchResultDtoTest.php
│   ├── Entity
│   │   ├── ClubLeagueFieldsTest.php
│   │   ├── ClubTest.php
│   │   ├── GameConfigLeagueFieldsTest.php
│   │   ├── GameEventTemplateTest.php
│   │   ├── LeagueConfigFieldsTest.php
│   │   ├── LeagueTest.php
│   │   ├── MatchResultTest.php
│   │   ├── NpcClubLeagueFieldTest.php
│   │   ├── NpcClubTest.php
│   │   ├── PoolConfigStaffTargetsTest.php
│   │   ├── SeasonRecordTest.php
│   │   ├── SeasonSnapshotTest.php
│   │   └── StarterConfigLeagueFieldsTest.php
│   ├── Enum
│   │   ├── FormationTest.php
│   │   ├── ReputationTierTest.php
│   │   └── StaffRoleTest.php
│   ├── Repository
│   │   └── LeagueRepositoryTest.php
│   ├── Service
│   │   ├── ClubInitializationServiceTest.php
│   │   ├── EconomicServiceTest.php
│   │   ├── FixtureGenerationServiceTest.php
│   │   ├── InboxServiceTest.php
│   │   ├── LeagueServiceSponsorRollTest.php
│   │   ├── LeagueServiceTest.php
│   │   ├── MarketPoolServiceGenerateStaffTest.php
│   │   ├── NpcClubGenerationServiceLeagueTest.php
│   │   ├── NpcClubGenerationServiceTest.php
│   │   ├── SyncServiceLeagueTest.php
│   │   └── SyncServiceManagerShiftsTest.php
│   └── bootstrap.php
├── translations
├── CLAUDE.md
├── compose.override.yaml
├── compose.yaml
├── composer.json
├── composer.lock
├── docker-compose.prod.yml
├── docker-compose.staging.yml
├── Dockerfile
├── phpunit.dist.xml
├── project_plan.md
├── README.md
├── symfony.lock
└── wunderkind-backend-context.md

48 directories, 316 files
```

---

## Data Models

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

#### Club
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
    private ?\DateTimeImmutable $worldInitializedAt = null;
    private ?string $paName = null;
    private int $managerTemperament = 50;
    private int $managerDiscipline = 50;
    private int $managerAmbition = 50;
```

#### FacilityTemplate
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
    private int $sortOrder = 0;
    private bool $isActive = true;
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
    private float $reputationDeltaBase = 0.15;
    private float $reputationDeltaFacilityMultiplier = 0.15;
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
    private bool $noInteract = false;
    private ?array $chainedEvents = null;
    private \DateTimeImmutable $createdAt;
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getSlug(): string { return $this->slug; }
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
    private int $loyaltyToClub = 50;
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
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
```

#### Investor
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
```

#### LeaderboardEntry
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
```

#### League
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
    private Collection $leagueSponsors;
    private Collection $sponsors;
    private \DateTimeImmutable $createdAt;
    public function __construct(string $country, int $tier, string $name)
    public function getId(): UuidV7 { return $this->id; }
    public function getCountry(): string { return $this->country; }
```

#### LeagueSponsor
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

#### MatchResult
```php
    private UuidV7 $id;
    private Club $club;
    private NpcClub $opponentClub;
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
    private \DateTimeImmutable $createdAt;
```

#### NpcClub
```php
    private UuidV7 $id;
    private string $name;
    private string $country;
    private int $tier;
    private int $reputation;
    private string $primaryColor;
    private string $secondaryColor;
    private ?string $stadiumName = null;
    private int $balance;
    private string $playingStyle = 'DIRECT';
    private string $financialApproach = 'BALANCED';
    private int $managerTemperament = 50;
    private array $facilities;
    private \DateTimeImmutable $createdAt;
    private ?League $league = null;
```

#### PersonalityProfile
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
    private ?Club $club = null;
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

#### RefreshToken
```php
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

#### SeasonRecord
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

#### SeasonSnapshot
```php
    private UuidV7 $id;
    private Club $club;
    private int $season;
    private string $country;
    private array $snapshotData;
    private \DateTimeImmutable $createdAt;
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getSeason(): int { return $this->season; }
    public function getCountry(): string { return $this->country; }
    public function getSnapshotData(): array { return $this->snapshotData; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
```

#### Sponsor
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
    private ?string $nationality = null;
    private ?string $specialty = null;
    private ?array $specialisms = null;
    private ?Club $club = null;
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
```

#### SyncRecord
```php
    private UuidV7 $id;
    private Club $club;
    private int $clientWeekNumber;
    private \DateTimeImmutable $clientTimestamp;
    private \DateTimeImmutable $serverTimestamp;
    private array $payload = [];
    private bool $isValid = true;
    private ?string $invalidReason = null;
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getClientWeekNumber(): int { return $this->clientWeekNumber; }
    public function getClientTimestamp(): \DateTimeImmutable { return $this->clientTimestamp; }
    public function getServerTimestamp(): \DateTimeImmutable { return $this->serverTimestamp; }
    public function getPayload(): array { return $this->payload; }
```

#### TacticalAdvantage
```php
    private UuidV7 $id;
    private PlayingStyle $style;
    private PlayingStyle $opponentStyle;
    private float $multiplier;
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getStyle(): PlayingStyle { return $this->style; }
    public function setStyle(PlayingStyle $style): void { $this->style = $style; }
    public function getOpponentStyle(): PlayingStyle { return $this->opponentStyle; }
    public function setOpponentStyle(PlayingStyle $opponentStyle): void { $this->opponentStyle = $opponentStyle; }
    public function getMultiplier(): float { return $this->multiplier; }
    public function setMultiplier(float $multiplier): void { $this->multiplier = $multiplier; }
```

#### Transfer
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
```

#### User
```php
    public const ROLE_CLUB = 'ROLE_CLUB';
    private UuidV7 $id;
    private string $email;
    private string $password;
    private array $roles = [];
    private ?Club $club = null;
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
  admin_club_index                           [34mGET[39m              /admin/club                                   
  admin_club_new                             [34mGET[39m|[32mPOST[39m         /admin/club/new                               
  admin_club_batch_delete                    [32mPOST[39m             /admin/club/batch-delete                      
  admin_club_autocomplete                    [34mGET[39m              /admin/club/autocomplete                      
  admin_club_render_filters                  [34mGET[39m              /admin/club/render-filters                    
  admin_club_edit                            [34mGET[39m|[32mPOST[39m|[33mPATCH[39m   /admin/club/{entityId}/edit                   
  admin_club_delete                          [32mPOST[39m             /admin/club/{entityId}/delete                 
  admin_club_detail                          [34mGET[39m              /admin/club/{entityId}                        
  admin_facility_template_index              [34mGET[39m              /admin/facility-template                      
  admin_facility_template_new                [34mGET[39m|[32mPOST[39m         /admin/facility-template/new                  
  admin_facility_template_batch_delete       [32mPOST[39m             /admin/facility-template/batch-delete         
  admin_facility_template_autocomplete       [34mGET[39m              /admin/facility-template/autocomplete         
  admin_facility_template_render_filters     [34mGET[39m              /admin/facility-template/render-filters       
  admin_facility_template_edit               [34mGET[39m|[32mPOST[39m|[33mPATCH[39m   /admin/facility-template/{entityId}/edit      
  admin_facility_template_delete             [32mPOST[39m             /admin/facility-template/{entityId}/delete    
  admin_facility_template_detail             [34mGET[39m              /admin/facility-template/{entityId}           
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
```

---

## Controllers

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

#### ClubCrudController
```php
    public function __construct(private EntityManagerInterface $em) {}
    public function configureActions(Actions $actions): Actions
    public function detail(AdminContext $context): Response
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### DashboardController
```php
    public function __construct(
    public function index(): Response
    #[Route('/admin/app-links', name: 'admin_app_links')]
    public function appLinks(): Response
    #[Route('/admin/app-links/save', name: 'admin_app_links_save', methods: ['POST'])]
    public function saveAppLinks(Request $request): Response
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
```

#### FacilityTemplateCrudController
```php
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
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

#### LeagueCrudController
```php
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### NpcClubCrudController
```php
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
    public function __construct(
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function index(AdminContext $context): KeyValueStore|Response
    public function createEntity(string $entityFqcn): Player
    public function configureFilters(Filters $filters): Filters
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
    public function __construct(private readonly ClubRepository $clubRepository) {}
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function createEntity(string $entityFqcn): Staff
    public function configureFilters(Filters $filters): Filters
    public function configureFields(string $pageName): iterable
```

#### SyncRecordCrudController
```php
    public function configureActions(Actions $actions): Actions
    public function configureCrud(Crud $crud): Crud
    public function configureFields(string $pageName): iterable
```

#### TacticalAdvantageCrudController
```php
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

#### AdminController
```php
#[Route('/api/admin')]
    #[Route('/stats', name: 'api_admin_stats', methods: ['GET'])]
    public function stats(): JsonResponse
```

#### AppLinksController
```php
#[Route('/api')]
    public function __construct(
    #[Route('/app-links', name: 'api_app_links', methods: ['GET'])]
    public function index(): JsonResponse
```

#### ArchetypeController
```php
#[Route('/api/archetypes', name: 'api_archetypes', methods: ['GET'])]
    public function __construct(
    public function __invoke(): JsonResponse
```

#### ClubController
```php
#[Route('/api/club')]
    #[Route('/initialize', name: 'api_club_initialize', methods: ['POST'])]
    public function initialize(
    #[Route('/check', name: 'api_club_check', methods: ['GET'])]
    public function check(): JsonResponse
    #[Route('/status', name: 'api_club_status', methods: ['GET'])]
    public function status(): JsonResponse
```

#### EventController
```php
#[Route('/api/events')]
    public function __construct(
    #[Route('/templates', name: 'api_events_templates', methods: ['GET'])]
    public function templates(): JsonResponse
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

#### LeagueController
```php
#[Route('/api/league')]
    public function __construct(
    #[Route('/conclude-season', name: 'api_league_conclude_season', methods: ['POST'])]
    public function concludeSeason(#[MapRequestPayload] ConcludeSeasonRequest $dto): JsonResponse
    #[Route('/season-history', name: 'api_league_season_history', methods: ['GET'])]
    public function seasonHistory(): JsonResponse
    #[Route('/season-history/{season}', name: 'api_league_season_history_detail', methods: ['GET'])]
    public function seasonHistoryDetail(int $season): JsonResponse
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
    #[Route('/consume', name: 'api_market_consume', methods: ['POST'])]
    public function consume(
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
    public function __construct(
    #[Route('', name: 'api_squad_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    #[Route('/release/{id}', name: 'api_squad_release', methods: ['POST'])]
    public function release(string $id): JsonResponse
```

#### StaffController
```php
#[Route('/api/staff')]
    #[Route('', name: 'api_staff_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
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

#### HomeController
```php
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): BinaryFileResponse
```

#### InitializeController
```php
#[Route('/api')]
    public function __construct(
    #[Route('/initialize', name: 'api_initialize', methods: ['POST'])]
    public function initialize(Request $request): JsonResponse
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

#### ClubInitializationService
```php
    public function __construct(
    public function initializeClub(User $user, string $clubName, ?string $country = null, ?array $managerProfile = null): Club
    public function getStarterBundle(): array
```

#### ConfigImportExportService
```php
    public function __construct(
    public function export(): array
    public function import(array $data): array
```

#### EconomicService
```php
    public function __construct(
    public function generateSponsorOffer(Club $club): array
    public function generateInvestorOffer(Club $club): array
    public function calculatePlayerMarketValue(Player $player): int
    public function processFinancialYearEnd(Club $club): void
    public function checkSponsorContracts(Club $club, int $currentReputation): void
    public function checkAgeOutPlayers(Club $club, int $currentWeek, \DateTimeImmutable $clientTimestamp): void
```

#### FixtureGenerationService
```php
    public function generate(array $clubIds): array
```

#### InboxService
```php
    public function __construct(
    public function sendSponsorOffer(Club $club, array $offerData): InboxMessage
    public function sendInvestorOffer(Club $club, array $offerData): InboxMessage
    public function sendAgentSaleOffer(Player $player, array $offerData): InboxMessage
    public function sendAgeOutWarning(Player $player, int $weeksRemaining): InboxMessage
    public function sendForcedSaleNotification(Player $player, int $salePrice): InboxMessage
    public function sendSystemNotification(Club $club, string $subject, string $body, array $details = []): InboxMessage
    public function acceptMessage(InboxMessage $message, User $user): void
    public function rejectMessage(InboxMessage $message): void
```

#### LeagueImportExportService
```php
    public function __construct(
    public function export(): array
    public function import(array $data): array
    public function clearAll(): void
```

#### LeagueService
```php
    public function __construct(
    public function generateLeaguesForCountry(string $country): array
    public function assignClubToLeague(NpcClub $club): void
    public function assignClubToStarterLeague(Club $club, string $country): void
    public function rollLeagueSponsors(League $league, GameConfig $config): int
    public function concludeSeason(Club $club, ConcludeSeasonRequest $dto): array
```

#### MarketDataService
```php
    public function __construct(private readonly MarketPoolService $pool) {}
    public function getMarketSnapshot(?string $nationality = null, ?Tier $tier = null): MarketDataResponse
    public function getProspectSnapshot(): array
```

#### MarketPoolService
```php
    public function __construct(
    public function generatePlayers(int $count, ?int $clubReputation = null, RecruitmentSource $source = RecruitmentSource::YOUTH_INTAKE, ?string $nationality = null): array
    public function generateStaffForRole(StaffRole $role, int $count, ?int $clubReputation = null): array
    public function generateScouts(int $count): array
    public function generateAgents(int $count): array
    public function generateSponsors(int $count): array
    public function generateInvestors(int $count): array
    public function getAvailablePlayers(int $limit = 100, ?string $nationality = null, ?int $abilityMin = null, ?int $abilityMax = null): array
    public function getAvailableProspects(int $limit = 150): array
    public function getAvailableCoaches(int $limit = 20, ?int $abilityMin = null, ?int $abilityMax = null): array
    public function getAvailableScouts(int $limit = 10, ?int $experienceMin = null, ?int $experienceMax = null): array
    public function getAgents(int $limit = 20, ?int $ratingMin = null, ?int $ratingMax = null): array
```

#### NameGeneratorService
```php
    public function generateName(string $nationality): string
    public function generatePlayerName(string $nationality): array
    public function generateFirstName(string $nationality): string
    public function generateLastName(string $nationality): string
    public function getRandomNationality(): string
```

#### NarrativeImportExportService
```php
    public function __construct(
    public function export(): array
    public function clearAll(): void
    public function import(array $data): array
```

#### NpcClubGenerationService
```php
    public function __construct(
    public function generateClubs(int $count, int $tier, string $country, bool $deleteExisting = false): array
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

#### WorldInitializationService
```php
    public function __construct(
    public function buildLeaguesPack(Club $club, string $country): array
    public function initialize(Club $club): array
```


---

## Migrations

| Migration | Date |
|---|---|
| `Version20260503110000` | 20260503 |
| `Version20260503120000` | 20260503 |
| `Version20260504000000` | 20260504 |
| `Version20260504010000` | 20260504 |
| `Version20260504020000` | 20260504 |
| `Version20260504030000` | 20260504 |
| `Version20260504040000` | 20260504 |
| `Version20260504050000` | 20260504 |
| `Version20260504165645` | 20260504 |
| `Version20260504200100` | 20260504 |
_Showing latest 10 of 81 total._

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
CLUB_STARTING_BALANCE=***
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
8a38209 return more data
56a8531 return more data
4d82878  npc_club_id is gone. Snapshot columns (player_name, player_position, club_leaving) remain. Schema is clean and in sync.
936a079 The fixture_id column is now VARCHAR(255) — the composite IDs in the payload (e.g.   {leagueId}-s{season}-r{round}-{homeId}-{awayId}) run ~110–120 chars, well within the new limit.
eeb320f added config for app URLs
797ab3c added config for app URLs
b1ad410 added config for app URLs
85ebc45 configuration fields
8c15a38 update landing page with new screenshots and feature content
480b3b4 facility non-game income config
50c5a9f playing style influnce configuration
1b52598 add properties for retirement
4a03b6a conclude season
273db98 conclude season
acb7fc7 further league service tweaks
```

---

## Architecture Notes

- **Repository Pattern** — each entity has a dedicated `Repository/` class encapsulating all query logic, keeping persistence concerns out of services and controllers
- **Service Layer** — business logic is delegated to focused service classes (`SyncService`, `EconomicService`, `LeagueService`, etc.) keeping controllers thin HTTP adapters
- **DTO (Data Transfer Object)** — `src/Dto/` separates external API input/output shapes from internal domain entities, validated before touching domain logic
- **Import/Export Command Pattern** — dedicated `*ImportExportService` classes (`ConfigImportExportService`, `LeagueImportExportService`, `NarrativeImportExportService`) suggest a pluggable data portability layer decoupled from core domain services
- **Event-Driven / Subscriber Pattern** — `src/EventSubscriber/` indicates cross-cutting concerns (auth, logging, lifecycle hooks) are handled via Symfony's event dispatcher rather than embedded in controllers or services

---

## Current Development Focus

- **Transfer sync pipeline** — `TransferSyncDto`, `TransferRepository`, snapshot columns (`player_name`, `player_position`, `club_leaving`), and schema churn across multiple migrations suggest the transfer ingestion flow is being actively reshaped; AI could help validate DTO mapping, enforce snapshot consistency, and generate edge-case tests for the sync logic.
- **Match result processing** — `MatchResultDto` and `MatchResult` entity changes alongside the `fixture_id` VARCHAR expansion point to a new or evolving match data flow; AI could help design robust composite-ID parsing, result aggregation queries, and conflict-resolution when duplicate fixture payloads arrive.
- **Game configuration system** — `GameConfig` entity and `GameConfigController` are new, with config entries for facility income and app URLs landing in rapid succession; AI could help define a typed config schema, add validation, and build a caching layer so config reads don't hit the DB on every request.
- **Migration sprawl** — eight migrations in a single day (`Version20260504*`) indicates fast, iterative schema changes that risk drift and ordering bugs; AI could audit the migration sequence for idempotency, detect reversible vs. destructive changes, and suggest consolidation before the next release.
- **Admin CRUD surface** — `ClubCrudController` and `DashboardController` edits alongside new entities suggest the admin panel is expanding rapidly; AI could accelerate generating consistent EasyAdmin CRUD controllers, sidebar entries, and permission guards for each new entity.

---

> _AI summaries generated using **claude**._
