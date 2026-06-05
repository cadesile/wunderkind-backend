# wunderkind-backend — Project Context

> Generated: 2026-06-05 19:38:00 | Duration: 38s | Stack: symfony 80 · PHP 8.4 · postgres:16 | Dev: lando

---

## Overview

Wunderkind Backend is the server-side API for a mobile-first youth football academy management game, handling authentication, global leaderboards, legacy sync from client-authoritative gameplay, and economic simulation systems. Built on Symfony 8.0 with PHP 8.4, PostgreSQL 16, and API Platform v4, it follows a hybrid architecture where all core gameplay runs on-device while the backend enforces anti-cheat validation, persists aggregate state, and serves market/narrative data. The admin panel (EasyAdmin v5) provides operational oversight of players, staff, scouts, investors, sponsors, and game event templates.

---

## Document Context


**./**
- [CLAUDE.md](CLAUDE.md)

**docs/**
- [docs/event-guide.md](docs/event-guide.md)
- [docs/frontend-integration.md](docs/frontend-integration.md)
- [docs/frontend-spec-player-physical-personality.md](docs/frontend-spec-player-physical-personality.md)

**docs/superpowers/plans/**
- [docs/superpowers/plans/2026-04-14-npc-club-generation.md](docs/superpowers/plans/2026-04-14-npc-club-generation.md)
- [docs/superpowers/plans/2026-04-18-admin-starter-config-league-ability-ranges.md](docs/superpowers/plans/2026-04-18-admin-starter-config-league-ability-ranges.md)
- [docs/superpowers/plans/2026-05-12-initialize-endpoint-redesign.md](docs/superpowers/plans/2026-05-12-initialize-endpoint-redesign.md)
- [docs/superpowers/plans/2026-05-18-worldpack-cache-admin.md](docs/superpowers/plans/2026-05-18-worldpack-cache-admin.md)

**docs/superpowers/specs/**
- [docs/superpowers/specs/2026-04-14-npc-club-generation-design.md](docs/superpowers/specs/2026-04-14-npc-club-generation-design.md)
- [docs/superpowers/specs/2026-05-12-initialize-endpoint-redesign.md](docs/superpowers/specs/2026-05-12-initialize-endpoint-redesign.md)

**docs/**
- [docs/wunderkind-backend-context.md](docs/wunderkind-backend-context.md)

**migrations/archive/**
- [migrations/archive/README.md](migrations/archive/README.md)

**./**
- [README.md](README.md)

**scripts/global-context-generator/**
- [scripts/global-context-generator/README.md](scripts/global-context-generator/README.md)

---

## Metrics

| Category | Count |
|---|---|
| PHP files         | 336 |
| Entities/Models   | 32 |
| Controllers       | 45 |
| Services          | 18 |
| Migrations        | 118 |

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
- `nyholm/psr7`: ^1.8
- `symfony/console`: 8.0.*
- `symfony/dotenv`: 8.0.*
- `symfony/flex`: ^2
- `symfony/framework-bundle`: 8.0.*
- `symfony/google-mailer`: 8.0.*
- `symfony/http-client`: 8.0.*
- `symfony/mailer`: 8.0.*
- `symfony/monolog-bundle`: ^4.0
- `symfony/resend-mailer`: 8.0.*
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
│   │   ├── mailer.yaml
│   │   ├── monolog.yaml
│   │   ├── nelmio_cors.yaml
│   │   ├── nyholm_psr7.yaml
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
│   ├── pool-warm.sh
│   ├── supervisord.conf
│   └── worldpack-warm.sh
├── docs
│   ├── superpowers
│   │   ├── plans
│   │   └── specs
│   ├── event-guide.md
│   ├── frontend-integration.md
│   ├── frontend-spec-player-physical-personality.md
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
│   ├── Version20260504200100.php
│   ├── Version20260506000001.php
│   ├── Version20260507000001.php
│   ├── Version20260508083809.php
│   ├── Version20260508093952.php
│   ├── Version20260508094245.php
│   ├── Version20260508202213.php
│   ├── Version20260509133608.php
│   ├── Version20260510091816.php
│   ├── Version20260510093027.php
│   ├── Version20260511121000.php
│   ├── Version20260511181003.php
│   ├── Version20260511182946.php
│   ├── Version20260512000001.php
│   ├── Version20260516000001.php
│   ├── Version20260524000001.php
│   ├── Version20260524000002.php
│   ├── Version20260524000003.php
│   ├── Version20260525000001.php
│   ├── Version20260525000002.php
│   ├── Version20260525000003.php
│   ├── Version20260526231012.php
│   ├── Version20260527091321.php
│   ├── Version20260527190328.php
│   ├── Version20260528000001.php
│   ├── Version20260529000001.php
│   ├── Version20260529000002.php
│   ├── Version20260529000003.php
│   ├── Version20260530000001.php
│   ├── Version20260531000001.php
│   ├── Version20260531000002.php
│   ├── Version20260601000001.php
│   ├── Version20260602000001.php
│   ├── Version20260602000002.php
│   ├── Version20260603000001.php
│   ├── Version20260603000002.php
│   ├── Version20260604000001.php
│   └── Version20260604231350.php
├── public
│   ├── bundles
│   │   ├── apiplatform
│   │   └── easyadmin
│   ├── images
│   │   ├── trophies
│   │   ├── logo.png
│   │   └── logo.webp
│   ├── screenshots
│   │   ├── buildmyclub
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
│   │   ├── Screenshot_20260511-111653.png
│   │   ├── Screenshot_20260511-111702.png
│   │   ├── Screenshot_20260511-111729.png
│   │   ├── Screenshot_20260511-111737.png
│   │   ├── Screenshot_20260511-111743.png
│   │   ├── Screenshot_20260511-111937.png
│   │   ├── Screenshot_20260511-111947.png
│   │   ├── Screenshot_20260511-111952.png
│   │   ├── Screenshot_20260511-111957.png
│   │   ├── Screenshot_20260511-112001.png
│   │   ├── Screenshot_20260511-112005.png
│   │   ├── Screenshot_20260511-112022.png
│   │   ├── Screenshot_20260511-112035.png
│   │   ├── Screenshot_20260511-112044.png
│   │   ├── Screenshot_20260511-112051.png
│   │   ├── Screenshot_20260511-112057.png
│   │   ├── Screenshot_20260511-112121.png
│   │   ├── Screenshot_20260511-112153.png
│   │   ├── Screenshot_20260511-112202.png
│   │   ├── Screenshot_20260511-112210.png
│   │   ├── Screenshot_20260511-112218.png
│   │   ├── Screenshot_20260511-112233.png
│   │   ├── Screenshot_20260511-112240.png
│   │   ├── Screenshot_20260511-112245.png
│   │   ├── Screenshot_20260511-112254.png
│   │   ├── Screenshot_20260511-112300.png
│   │   ├── squad.png
│   │   └── welcome.png
│   ├── admin-login.css
│   ├── admin-theme.css
│   ├── favicon.ico
│   ├── index.html
│   ├── index.php
│   ├── logo-16.png
│   ├── logo-180.png
│   ├── logo-192.png
│   ├── logo-32.png
│   ├── logo-48.png
│   ├── logo-512.png
│   └── logo.png
├── scripts
│   ├── global-context-generator
│   │   ├── generate_project_context.sh
│   │   └── README.md
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
│   │   ├── SeedMoraleEventsCommand.php
│   │   ├── SeedPlayerEventsCommand.php
│   │   ├── SetExistingClubBalancesCommand.php
│   │   ├── WarmPoolCommand.php
│   │   └── WarmWorldPackCommand.php
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
│   │   ├── CountryWorldPackCache.php
│   │   ├── EmailVerification.php
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
│   │   ├── SeasonRatingsSnapshot.php
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
│   │   ├── TransferType.php
│   │   ├── TrophyColour.php
│   │   └── VerificationPurpose.php
│   ├── Form
│   │   └── Type
│   ├── Repository
│   │   ├── AdminRepository.php
│   │   ├── AgentRepository.php
│   │   ├── ClubRepository.php
│   │   ├── CountryWorldPackCacheRepository.php
│   │   ├── EmailVerificationRepository.php
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
│   │   ├── SeasonRatingsSnapshotRepository.php
│   │   ├── SeasonRecordRepository.php
│   │   ├── SeasonSnapshotRepository.php
│   │   ├── SponsorRepository.php
│   │   ├── StaffRepository.php
│   │   ├── StarterConfigRepository.php
│   │   ├── TacticalAdvantageRepository.php
│   │   └── TransferRepository.php
│   ├── Security
│   │   └── VerificationAwareAuthenticationSuccessHandler.php
│   ├── Service
│   │   ├── ClubInitializationService.php
│   │   ├── ConfigImportExportService.php
│   │   ├── EconomicService.php
│   │   ├── EmailVerificationService.php
│   │   ├── FixtureGenerationService.php
│   │   ├── InboxService.php
│   │   ├── LeagueImportExportService.php
│   │   ├── LeagueService.php
│   │   ├── MarketDataService.php
│   │   ├── MarketPoolService.php
│   │   ├── NameGeneratorService.php
│   │   ├── NarrativeImportExportService.php
│   │   ├── NpcClubGenerationService.php
│   │   ├── StarterPackService.php
│   │   ├── SyncService.php
│   │   ├── TransferLeaderboardService.php
│   │   ├── WorldInitializationService.php
│   │   └── WorldPackCacheService.php
│   └── Kernel.php
├── templates
│   ├── admin
│   │   ├── _macros.html.twig
│   │   ├── app_links.html.twig
│   │   ├── club_profile.html.twig
│   │   ├── config_content.html.twig
│   │   ├── dashboard.html.twig
│   │   ├── facilities_content.html.twig
│   │   ├── game_config.html.twig
│   │   ├── leagues_content.html.twig
│   │   ├── login.html.twig
│   │   ├── logs.html.twig
│   │   ├── narrative_content.html.twig
│   │   ├── npc_clubs_content.html.twig
│   │   ├── player_index.html.twig
│   │   ├── pool_config.html.twig
│   │   ├── settings.html.twig
│   │   ├── starter_config.html.twig
│   │   ├── world_content.html.twig
│   │   └── worldpack_cache.html.twig
│   ├── bundles
│   │   └── EasyAdminBundle
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
├── README.md
└── symfony.lock

53 directories, 412 files
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
    private ?string $abbreviation = null;
    private ?\DateTimeImmutable $worldInitializedAt = null;
    private ?\DateTimeImmutable $starterInitializedAt = null;
    private ?string $paName = null;
    private int $managerTemperament = 50;
```

#### CountryWorldPackCache
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

#### EmailVerification
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
    private int $baseConstructionWeeks = 4;
    private int $sortOrder = 0;
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
    private float $potentialOvershootMax = 0.05;
    private float $potentialDecayRate = 0.5;
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
    private int $sponsorCount = 0;
    private ?string $trophyImage = null;
    private ?TrophyColour $trophyColour = null;
    private Collection $leagueSponsors;
    private Collection $sponsors;
    private \DateTimeImmutable $createdAt;
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
    private ?string $abbreviation = null;
    private ?string $stadiumName = null;
    private int $balance;
    private string $playingStyle = 'DIRECT';
    private string $financialApproach = 'BALANCED';
    private int $managerTemperament = 50;
    private array $facilities;
    private \DateTimeImmutable $createdAt;
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

#### SeasonRatingsSnapshot
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
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getSeason(): int { return $this->season; }
    public function getWeekNum(): int { return $this->weekNum; }
    public function getTier(): int { return $this->tier; }
    public function getClubId(): string { return $this->clubId; }
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
    private const DEFAULT_TIER_RANGES = [
```

#### SyncRecord
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
    public function __construct(
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getClientWeekNumber(): int { return $this->clientWeekNumber; }
    public function getClientTimestamp(): \DateTimeImmutable { return $this->clientTimestamp; }
    public function getServerTimestamp(): \DateTimeImmutable { return $this->serverTimestamp; }
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
    private Collection $clubs;
    private ?array $managerProfile = null;
    private bool $isVerified = false;
    private ?\DateTimeImmutable $verifiedAt = null;
    private \DateTimeImmutable $createdAt;
    public function __construct(string $email)
    public function getId(): UuidV7 { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
    public function getUserIdentifier(): string { return $this->email; }
```


---

## Database Schema (Doctrine / PostgreSQL)

**Migrations (latest 10):**

- `Version20260530000001`
  → ALTER TABLE game_config ADD COLUMN beta_request_email VARCHAR(255) DEFAULT NULL
  → ALTER TABLE game_config ADD COLUMN recaptcha_site_key VARCHAR(255) DEFAULT NULL
  → ALTER TABLE game_config ADD COLUMN recaptcha_secret_key VARCHAR(255) DEFAULT NULL
- `Version20260531000001`
  → ALTER TABLE game_config DROP COLUMN npc_club_balance_ranges
- `Version20260531000002`
  → ALTER TABLE game_config DROP COLUMN npc_facility_level_ranges
- `Version20260601000001`
  → ALTER TABLE game_config DROP COLUMN league_win_points
- `Version20260602000001`
  → ALTER TABLE game_config DROP COLUMN pyramid_news_frequency_weeks
  → ALTER TABLE game_config DROP COLUMN pyramid_news_config
- `Version20260602000002`
  → CREATE INDEX idx_srs_season_tier ON season_ratings_snapshot (season, tier)
  → DROP TABLE season_ratings_snapshot
- `Version20260603000001`
  → ALTER TABLE "user" ADD COLUMN is_verified BOOLEAN NOT NULL DEFAULT FALSE
  → ALTER TABLE "user" ADD COLUMN verified_at TIMESTAMPTZ DEFAULT NULL
  → DROP TABLE email_verification
- `Version20260603000002`
  → ALTER TABLE league ADD COLUMN sponsor_count SMALLINT NOT NULL DEFAULT 0
  → ALTER TABLE league DROP COLUMN sponsor_count
- `Version20260604000001`
  → DROP INDEX IF EXISTS uniq_b8ee3872a76ed395
  → CREATE UNIQUE INDEX uniq_b8ee3872a76ed395 ON club (user_id)
- `Version20260604231350`
  → DROP INDEX idx_email_verification_user
  → ALTER TABLE email_verification ADD purpose VARCHAR(20) DEFAULT \
  → COMMENT ON COLUMN email_verification.expires_at IS \

**Entity column/relation map:**

#### `Admin`
```php
    private UuidV7 $id;
    private string $email;
    private string $password;
    private ?string $name = null;
    private ?string $department = null;
    private int $accessLevel = 1;
    private \DateTimeImmutable $createdAt;
    public function __construct(string $email)
        $this->id        = new UuidV7();
        $this->email     = $email;
        $this->createdAt = new \DateTimeImmutable();
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
        $this->id      = new UuidV7();
        $this->name    = $name;
        $this->players = new ArrayCollection();
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
```

#### `Club`
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
    private ?string $paName = null;
    private int $managerTemperament = 50;
    private int $managerDiscipline = 50;
    private int $managerAmbition = 50;
    private int $balance = 0;
    private ?array $managerProfile = null;
    private \DateTimeImmutable $createdAt;
    private User $user;
    private Collection $players;
    private Collection $staff;
    private Collection $transfers;
    private Collection $syncRecords;
```

#### `CountryWorldPackCache`
```php
    private UuidV7 $id;
    private string $country;
    private int $tier;
    private array $payload;
    private \DateTimeImmutable $generatedAt;
    public function __construct(string $country, int $tier, array $payload)
        $this->id          = new UuidV7();
        $this->country     = $country;
        $this->tier        = $tier;
        $this->payload     = $payload;
        $this->generatedAt = new \DateTimeImmutable();
    public function getId(): UuidV7 { return $this->id; }
    public function getCountry(): string { return $this->country; }
    public function getTier(): int { return $this->tier; }
    public function getPayload(): array { return $this->payload; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
```

#### `EmailVerification`
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
        $this->id        = new UuidV7();
        $this->user      = $user;
        $this->code      = $code;
        $this->purpose   = $purpose;
        $this->expiresAt = new \DateTimeImmutable('+15 minutes');
        $this->createdAt = new \DateTimeImmutable();
    public function getId(): UuidV7 { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getCode(): string { return $this->code; }
    public function getPurpose(): VerificationPurpose { return $this->purpose; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): void { $this->expiresAt = $expiresAt; }
    public function getAttempts(): int { return $this->attempts; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
        return $this->expiresAt < new \DateTimeImmutable();
```

#### `FacilityTemplate`
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
        string $slug = '',
        string $label = '',
        string $description = '',
        string $category = 'TRAINING',
        int $baseCost = 0,
        $this->id          = new UuidV7();
        $this->slug        = $slug;
        $this->label       = $label;
```

#### `GameConfig`
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
        string $slug = '',
        EventCategory $category = EventCategory::PLAYER,
        string $title = '',
        string $bodyTemplate = '',
        array $impacts = [],
        int $weight = 1,
        $this->id           = new UuidV7();
        $this->slug         = $slug;
        $this->category     = $category;
        $this->title        = $title;
        $this->bodyTemplate = $bodyTemplate;
        $this->impacts      = $impacts;
        $this->weight       = $weight;
```

#### `Guardian`
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
        $this->id        = new UuidV7();
        $this->firstName = $firstName;
        $this->lastName  = $lastName;
        $this->player    = $player;
        $this->gender    = $gender;
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
```

#### `InboxMessage`
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
        Club $club,
        MessageSenderType $senderType,
        string $senderName,
        string $subject,
        string $body,
        $this->id         = new UuidV7();
        $this->club    = $club;
        $this->senderType = $senderType;
        $this->senderName = $senderName;
        $this->subject    = $subject;
        $this->body       = $body;
        $this->createdAt  = new \DateTimeImmutable();
    public function getId(): UuidV7 { return $this->id; }
```

#### `Investor`
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
        $this->id        = new UuidV7();
        $this->company   = $company;
        $this->createdAt = new \DateTimeImmutable();
    public function getId(): UuidV7 { return $this->id; }
    public function getCompany(): string { return $this->company; }
    public function setCompany(string $company): void { $this->company = $company; }
    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }
    public function getSize(): CompanySize { return $this->size; }
    public function setSize(CompanySize $size): void { $this->size = $size; }
    public function isActive(): bool { return $this->isActive; }
```

#### `LeaderboardEntry`
```php
    private UuidV7 $id;
    private Club $club;
    private LeaderboardCategory $category;
    private int $score = 0;
    private string $period;
    private ?int $rank = null;
    private \DateTimeImmutable $updatedAt;
    public function __construct(Club $club, LeaderboardCategory $category, string $period)
        $this->id        = new UuidV7();
        $this->club   = $club;
        $this->category  = $category;
        $this->period    = $period;
        $this->updatedAt = new \DateTimeImmutable();
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getCategory(): LeaderboardCategory { return $this->category; }
    public function getCategoryValue(): string { return $this->category->value; }
    public function getPeriod(): string { return $this->period; }
    public function getScore(): int { return $this->score; }
    public function setScore(int $score): void
        $this->score     = $score;
        $this->updatedAt = new \DateTimeImmutable();
    public function getRank(): ?int { return $this->rank; }
    public function setRank(?int $rank): void { $this->rank = $rank; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
```

#### `League`
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
        $this->id             = new UuidV7();
        $this->country        = $country;
        $this->tier           = $tier;
        $this->name           = $name;
        $this->leagueSponsors = new ArrayCollection();
        $this->sponsors       = new ArrayCollection();
        $this->createdAt      = new \DateTimeImmutable();
    public function getId(): UuidV7 { return $this->id; }
    public function getCountry(): string { return $this->country; }
```

#### `LeagueSponsor`
```php
    private League $league;
    private Sponsor $sponsor;
    private int $rolledValue = 0;
    public function __construct(League $league, Sponsor $sponsor, int $rolledValue = 0)
        $this->league      = $league;
        $this->sponsor     = $sponsor;
        $this->rolledValue = $rolledValue;
    public function getLeague(): League { return $this->league; }
    public function getSponsor(): Sponsor { return $this->sponsor; }
    public function getRolledValue(): int { return $this->rolledValue; }
    public function setRolledValue(int $v): static { $this->rolledValue = $v; return $this; }
```

#### `MatchResult`
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
        Club $club,
        int $goalsFor,
        int $goalsAgainst,
        int $week,
        int $season,
        $this->id           = new UuidV7();
        $this->club         = $club;
        $this->goalsFor     = $goalsFor;
        $this->goalsAgainst = $goalsAgainst;
```

#### `NpcClub`
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
        string $name,
        string $country,
        int $tier,
        int $reputation,
        string $primaryColor,
        string $secondaryColor,
        int $balance,
        array $facilities,
```

#### `PersonalityProfile`
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
```

#### `PlayerArchetype`
```php
    private ?int $id = null;
    private string $name;
    private string $description;
    private array $traitMapping = [];
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
        string $name = '',
        string $description = '',
        array $traitMapping = [],
        $this->name         = $name;
        $this->description  = $description;
        $this->traitMapping = $traitMapping;
        $this->createdAt    = new \DateTimeImmutable();
        $this->updatedAt    = new \DateTimeImmutable();
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): void { $this->description = $description; }
    public function getTraitMapping(): array { return $this->traitMapping; }
    public function setTraitMapping(array $traitMapping): void { $this->traitMapping = $traitMapping; }
        return json_encode($this->traitMapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    public function setTraitMappingJson(string $json): void
        $decoded = json_decode($json, true);
        $this->traitMapping = is_array($decoded) ? $decoded : [];
```

#### `PoolConfig`
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

#### `RefreshToken`
```php
```

#### `Scout`
```php
    private UuidV7 $id;
    private string $name;
    private ?\DateTimeImmutable $dob = null;
    private ?string $nationality = null;
    private array $judgements = [];
    private int $experience = 0;
    private \DateTimeImmutable $createdAt;
    public function __construct(string $name = '')
        $this->id        = new UuidV7();
        $this->name      = $name;
        $this->createdAt = new \DateTimeImmutable();
    public function getId(): UuidV7 { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getDob(): ?\DateTimeImmutable { return $this->dob; }
    public function setDob(?\DateTimeImmutable $dob): void { $this->dob = $dob; }
    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }
    public function getJudgements(): array { return $this->judgements; }
    public function setJudgements(array $judgements): void { $this->judgements = $judgements; }
        return json_encode($this->judgements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    public function setJudgementsJson(?string $json): void
        $decoded = json_decode(trim($json ?? ''), true);
        $this->judgements = is_array($decoded) ? $decoded : [];
    public function getExperience(): int { return $this->experience; }
```

#### `SeasonRatingsSnapshot`
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
        int $season,
        int $weekNum,
        int $tier,
        string $clubId,
        string $clubName,
        int $overallRating,
        int $expectedPosition,
        $this->id               = new UuidV7();
        $this->season           = $season;
        $this->weekNum          = $weekNum;
        $this->tier             = $tier;
        $this->clubId           = $clubId;
        $this->clubName         = $clubName;
        $this->overallRating    = $overallRating;
        $this->expectedPosition = $expectedPosition;
        $this->createdAt        = new \DateTimeImmutable();
```

#### `SeasonRecord`
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
        Club $club,
        League $league,
        int $season,
        int $finalPosition,
        int $gamesPlayed,
        int $wins,
        int $draws,
        int $losses,
        int $goalsFor,
        int $goalsAgainst,
```

#### `SeasonSnapshot`
```php
    private UuidV7 $id;
    private Club $club;
    private int $season;
    private string $country;
    private array $snapshotData;
    private \DateTimeImmutable $createdAt;
        Club $club,
        int $season,
        string $country,
        array $snapshotData,
        $this->id           = new UuidV7();
        $this->club      = $club;
        $this->season       = $season;
        $this->country      = $country;
        $this->snapshotData = $snapshotData;
        $this->createdAt    = new \DateTimeImmutable();
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getSeason(): int { return $this->season; }
    public function getCountry(): string { return $this->country; }
    public function getSnapshotData(): array { return $this->snapshotData; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
```

#### `Sponsor`
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
        $this->id        = new UuidV7();
        $this->company   = $company;
        $this->createdAt = new \DateTimeImmutable();
    public function __toString(): string { return $this->company; }
    public function getId(): UuidV7 { return $this->id; }
    public function getCompany(): string { return $this->company; }
    public function setCompany(string $company): void { $this->company = $company; }
```

#### `Staff`
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
        string $firstName = '',
        string $lastName = '',
        StaffRole $role = StaffRole::COACH,
        ?Club $club = null,
        $this->id        = new UuidV7();
        $this->firstName = $firstName;
        $this->lastName  = $lastName;
        $this->role      = $role;
        $this->club   = $club;
        $this->hiredAt   = new \DateTimeImmutable();
    public function getId(): UuidV7 { return $this->id; }
```

#### `StarterConfig`
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
    private array $npcSquadConfig = [
    private array $fanBaseRanges = [
    private float $fanBasePromotionIncrease = 0.20;
    private float $fanBaseRelegationDecrease = 0.10;
    public function getId(): int { return $this->id; }
    public function getStartingBalance(): int { return $this->startingBalance; }
    public function setStartingBalance(int $v): static { $this->startingBalance = $v; return $this; }
    public function getStartingBalancePounds(): int { return (int) round($this->startingBalance / 100); }
    public function setStartingBalancePounds(int $pounds): static { $this->startingBalance = $pounds * 100; return $this; }
    public function getStarterPlayerCount(): int { return $this->starterPlayerCount; }
```

#### `SyncRecord`
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
        Club $club,
        int $clientWeekNumber,
        \DateTimeImmutable $clientTimestamp,
        array $payload,
        $this->id               = new UuidV7();
        $this->club          = $club;
        $this->clientWeekNumber = $clientWeekNumber;
        $this->clientTimestamp  = $clientTimestamp;
        $this->serverTimestamp  = new \DateTimeImmutable();
        $this->payload          = $payload;
    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getClientWeekNumber(): int { return $this->clientWeekNumber; }
    public function getClientTimestamp(): \DateTimeImmutable { return $this->clientTimestamp; }
    public function getServerTimestamp(): \DateTimeImmutable { return $this->serverTimestamp; }
    public function getPayload(): array { return $this->payload; }
```

#### `TacticalAdvantage`
```php
    private UuidV7 $id;
    private PlayingStyle $style;
    private PlayingStyle $opponentStyle;
    private float $multiplier;
        PlayingStyle $style = PlayingStyle::POSSESSION,
        PlayingStyle $opponentStyle = PlayingStyle::DIRECT,
        float $multiplier = 1.0
        $this->id            = new UuidV7();
        $this->style         = $style;
        $this->opponentStyle = $opponentStyle;
        $this->multiplier    = $multiplier;
    public function getId(): UuidV7 { return $this->id; }
    public function getStyle(): PlayingStyle { return $this->style; }
    public function setStyle(PlayingStyle $style): void { $this->style = $style; }
    public function getOpponentStyle(): PlayingStyle { return $this->opponentStyle; }
    public function setOpponentStyle(PlayingStyle $opponentStyle): void { $this->opponentStyle = $opponentStyle; }
    public function getMultiplier(): float { return $this->multiplier; }
    public function setMultiplier(float $multiplier): void { $this->multiplier = $multiplier; }
```

#### `Transfer`
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
        ?Player $player,
        ?Club $club,
        string $destinationClubName,
        TransferType $type,
        \DateTimeImmutable $occurredAt,
        $this->id                  = new UuidV7();
        $this->player              = $player;
        $this->club                = $club;
        $this->destinationClubName = $destinationClubName;
```

#### `User`
```php
    private UuidV7 $id;
    private string $email;
    private string $password;
    private array $roles = [];
    private Collection $clubs;
    private ?array $managerProfile = null;
    private bool $isVerified = false;
    private ?\DateTimeImmutable $verifiedAt = null;
    private \DateTimeImmutable $createdAt;
    public function __construct(string $email)
        $this->id        = new UuidV7();
        $this->email     = $email;
        $this->createdAt = new \DateTimeImmutable();
        $this->clubs     = new ArrayCollection();
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
```


---

## Doctrine Entity Definitions

#### `Admin`
```php
```

#### `Agent`
```php
```

#### `Club`
```php
```

#### `CountryWorldPackCache`
```php
```

#### `EmailVerification`
```php
```

#### `FacilityTemplate`
```php
     * @var array<string, float>
```

#### `GameConfig`
```php
     * @var float[]
     * @var array<string, string[]>
     * @var array<array{min:int,max:int}>
     * @var array<int, array<string, array{min: int, max: int}>>
     * @var array<array{country: string, leagues: array<array{tier: int, min: int, max: int}>}>
     * @var array<array{maxAbility: int|null, playerMultiplier: float, staffMultiplier: float}>
```

#### `GameEventTemplate`
```php
     * @var array<int, array<string, mixed>>
     * @var array<int, array<string, mixed>>|null
```

#### `Guardian`
```php
```

#### `InboxMessage`
```php
```

#### `Investor`
```php
```

#### `LeaderboardEntry`
```php
```

#### `League`
```php
```

#### `LeagueSponsor`
```php
```

#### `MatchResult`
```php
```

#### `NpcClub`
```php
```

#### `PersonalityProfile`
```php
```

#### `Player`
```php
```

#### `PlayerArchetype`
```php
     * @var array<string, mixed>
```

#### `PoolConfig`
```php
```

#### `RefreshToken`
```php
```

#### `Scout`
```php
```

#### `SeasonRatingsSnapshot`
```php
```

#### `SeasonRecord`
```php
```

#### `SeasonSnapshot`
```php
```

#### `Sponsor`
```php
```

#### `Staff`
```php
```

#### `StarterConfig`
```php
     * @var array<string, array{min: int, max: int}>
```

#### `SyncRecord`
```php
     * @var array<string, mixed>
     * @var array<string, mixed>|null
```

#### `TacticalAdvantage`
```php
```

#### `Transfer`
```php
```

#### `User`
```php
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
    public function deleteEntity(EntityManagerInterface $em, $entityInstance): void
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

#### FacilityAdminController
```php
    public function __construct(private EntityManagerInterface $em) {}
    #[Route('/admin/facilities/{id}/quick-edit', name: 'admin_facility_quick_edit', methods: ['POST'])]
    public function quickEdit(Request $request, FacilityTemplate $facility): Response
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

#### LeagueAdminController
```php
    public function __construct(private EntityManagerInterface $em) {}
    #[Route('/admin/leagues/{id}/quick-edit', name: 'admin_league_quick_edit', methods: ['POST'])]
    public function quickEdit(Request $request, League $league): Response
```

#### LeagueCrudController
```php
    public function configureCrud(Crud $crud): Crud
    public function configureFilters(Filters $filters): Filters
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

#### WorldPackController
```php
    public function __construct(
    #[Route('/admin/worldpack-cache/delete/{id}', name: 'admin_worldpack_delete_entry', methods: ['POST'])]
    public function deleteEntry(string $id, Request $request): Response
    #[Route('/admin/worldpack-cache/delete-country', name: 'admin_worldpack_delete_country', methods: ['POST'])]
    public function deleteCountry(Request $request): Response
    #[Route('/admin/worldpack-cache/tiers/{country}', name: 'admin_worldpack_tiers', methods: ['GET'])]
    public function getTiers(string $country): JsonResponse
    #[Route('/admin/worldpack-cache/warm-tier', name: 'admin_worldpack_warm_tier', methods: ['POST'])]
    public function warmTier(Request $request): JsonResponse
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

#### BetaRequestController
```php
#[Route('/api')]
    public function __construct(
    #[Route('/beta-request', name: 'api_beta_request', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
```

#### ClubController
```php
#[Route('/api/club')]
    public function __construct(private readonly ClubRepository $clubRepository) {}
    #[Route('/name-options', name: 'api_clubs_name_options', methods: ['GET'])]
    public function nameOptions(Request $request): JsonResponse
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

#### ScoutSearchController
```php
#[Route('/api/scout')]
    #[Route('/search', name: 'api_scout_search', methods: ['GET'])]
    public function search(Request $request, PlayerRepository $playerRepo): JsonResponse
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
    public function __construct(private readonly ClubRepository $clubRepository) {}
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
#[Route('/api/initialize')]
    public function __construct(
    #[Route('/starter', name: 'api_initialize_starter', methods: ['POST'])]
    public function starter(Request $request): JsonResponse
    #[Route('/leagues', name: 'api_initialize_leagues', methods: ['GET'])]
    public function leagues(): JsonResponse
    #[Route('/league/{tier}', name: 'api_initialize_league_tier', requirements: ['tier' => '\d+'], methods: ['POST'])]
    public function tier(int $tier): JsonResponse
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
    #[Route('/verify-email', name: 'api_verify_email', methods: ['POST'])]
    public function verifyEmail(
    #[Route('/resend-verification', name: 'api_resend_verification', methods: ['POST'])]
    public function resendVerification(
    #[Route('/forgot-password', name: 'api_forgot_password', methods: ['POST'])]
    public function forgotPassword(
    #[Route('/reset-password', name: 'api_reset_password', methods: ['POST'])]
    public function resetPassword(
    #[Route('/resend-password-reset', name: 'api_resend_password_reset', methods: ['POST'])]
    public function resendPasswordReset(
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
    public function processFinancialYearEnd(Club $club): array
    public function checkSponsorContracts(Club $club, int $currentReputation): void
    public function checkAgeOutPlayers(Club $club, int $currentWeek, \DateTimeImmutable $clientTimestamp): void
```

#### EmailVerificationService
```php
    public function __construct(
    public function sendVerificationEmail(User $user): void
    public function sendPasswordResetEmail(User $user): void
    public function sendPasswordResetConfirmationEmail(User $user): void
    public function verifyCode(User $user, string $code): string
    public function verifyPasswordResetCode(User $user, string $code): string
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
```

#### MarketPoolService
```php
    public function __construct(
    public function generatePlayers(int $count, RecruitmentSource $source = RecruitmentSource::YOUTH_INTAKE, ?string $nationality = null): array
    public function generateStaffForRole(StaffRole $role, int $count, ?string $nationality = null): array
    public function generateScouts(int $count, ?string $nationality = null): array
    public function generateAgents(int $count): array
    public function generateSponsors(int $count): array
    public function generateInvestors(int $count): array
    public function getAvailablePlayers(int $limit = 100, ?string $nationality = null, ?int $abilityMin = null, ?int $abilityMax = null): array
    public function getAvailableCoaches(int $limit = 20, ?int $abilityMin = null, ?int $abilityMax = null): array
    public function getAvailableScouts(int $limit = 10, ?int $experienceMin = null, ?int $experienceMax = null): array
    public function getAgents(int $limit = 20, ?int $ratingMin = null, ?int $ratingMax = null): array
    public function getAvailableSponsorPool(int $limit = 20): array
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

#### StarterPackService
```php
    public function __construct(
    public function initialize(Club $club): array
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
    public function buildTierPack(Club $club, string $country, int $tier): array
    public function distributeByPosition(int $total, PoolConfig $config): array
    public function buildPlayerSnapshot(Player $player): array
    public function buildStaffSnapshot(Staff $staff): array
    public function buildScoutSnapshot(Scout $scout): array
```

#### WorldPackCacheService
```php
    public function __construct(
    public function getOrBuild(string $country, int $tier, callable $generator): array
    public function allTiersCached(string $country, array $tierNumbers): bool
    public function forceRebuild(string $country, int $tier, callable $generator): array
    public function deleteByCountry(string $country): int
```


---

## Migrations

| Migration | Date |
|---|---|
| `Version20260530000001` | 20260530 |
| `Version20260531000001` | 20260531 |
| `Version20260531000002` | 20260531 |
| `Version20260601000001` | 20260601 |
| `Version20260602000001` | 20260602 |
| `Version20260602000002` | 20260602 |
| `Version20260603000001` | 20260603 |
| `Version20260603000002` | 20260603 |
| `Version20260604000001` | 20260604 |
| `Version20260604231350` | 20260604 |
_Showing latest 10 of 118 total._

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
MAILER_DSN=***
MAILER_FROM=***
MAILER_FROM_NAME=***
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
f0e158e password recovery git
73f140b club to user relationship
9e135fe club to user relationship
dca31dd force deploy
4e30572 force deploy
539fd00 force deploy
5a14668 force deploy
789610c force deploy
9f32a11 force deploy
520b252 chore: replace wunderkindfactory.com with buildmyclub.co.uk throughout
828b77e added resend
0552836 added resend
8e76857 added variables to deploy
3f70635 added email config
918e878 chore: add global-context-generator as git submodule
```

---

## Architecture Notes

- **Repository Pattern** — every entity has a dedicated `*Repository` class encapsulating all query logic, keeping persistence concerns out of controllers and services
- **Service Layer** — fat services (`SyncService`, `EconomicService`, `LeagueService`, `WorldInitializationService`) own business logic; controllers are thin HTTP adapters that delegate immediately
- **DTO / Request Mapping** — `src/Dto/` holds validated input objects (e.g. `SyncRequest`) deserialized via Symfony's `#[MapRequestPayload]`, separating wire format from domain entities
- **Command Pattern (CLI operations)** — `src/Command/` externalises admin/ops tasks (seeding, cleanup, import/export) as Symfony console commands rather than embedding them in request-path code
- **Import/Export Service Decomposition** — dedicated `*ImportExportService` classes (`ConfigImportExportService`, `LeagueImportExportService`, `NarrativeImportExportService`) and a `WorldPackCacheService`/`StarterPackService` pair suggest a snapshot/portability subsystem isolated from the core sync and market services

---

## Current Development Focus

- **Password recovery & email verification flow** — `EmailVerification`, `VerificationPurpose` enum, and `EmailVerificationRepository` are all new; the token lifecycle (expiry, single-use enforcement, resend throttling) is error-prone and security-sensitive, making it a strong candidate for review and hardening.
- **Club↔User data model restructuring** — three commits and changes across `Club`, `User`, `ClubRepository`, `ClubInitializationService`, and three API controllers suggest an in-progress relationship refactor; AI can help audit cascades, orphan handling, and API contract consistency across all affected endpoints.
- **League management system** — `League` entity plus both a CRUD and a custom admin controller are active simultaneously; AI can help define clear boundaries between the two, prevent duplicate logic, and design the league-membership data model cleanly before it grows further.
- **Domain separation & multi-tenancy routing** — `DomainSeparationSubscriber` alongside the domain rename suggests routing or firewall logic is being split by hostname; AI can help stress-test edge cases (redirects, CORS, JWT audience, cookie scope) before they cause auth regressions in production.
- **Deployment stability** — six consecutive `force deploy` commits in a row points to a broken or fragile CI/CD pipeline; AI can help audit `docker-compose.prod.yml`, migration execution order, and the deploy script to eliminate the root cause rather than forcing pushes.

---

> _AI summaries generated using **claude**._
