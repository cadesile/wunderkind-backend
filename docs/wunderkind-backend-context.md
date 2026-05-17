# wunderkind-backend — Project Context

> Generated: 2026-05-17 17:46:21 | Duration: 56s | Stack: symfony 80 · PHP 8.4 · postgres:16 | Dev: lando

---

## Overview

Wunderkind Factory is a Symfony-based backend for a football academy management simulation focused on the discovery, development, and trading of youth athletes. It employs an API-driven architecture powered by API Platform and PostgreSQL, utilizing a discrete "weekly tick" system and service-oriented logic to manage complex game state transitions and player personality matrices.

---

## Document Context

### [CLAUDE.md](CLAUDE.md)
> AI Summary: `CLAUDE.md` provides comprehensive developer guidance for the Wunderkind project, covering Lando-based environment setup, essential Symfony and database commands, Git workflow standards, and the project's client-side synchronization architecture.

### [docs/event-guide.md](docs/event-guide.md)
> AI Summary: I will start by activating the `using-superpowers` skill to ensure I follow the established workflows for this session.

I will read the `docs/event-guide.md` file to ensure I have the complete content before providing a detailed summary.

The `docs/event-guide.md` file serves as the technical specification for defining and processing in-game event impacts in the Wunderkind Factory fat client, utilizing a standardized JSON schema of mutation objects with `target` paths and numerical `delta` values. Architecturally, it establishes a decoupled system where events modify granular player states—including a 1–20 personality matrix, physical health markers, and economic metrics—which directly influence simulation logic such as training XP efficiency and financial overhead. The guide documents critical engine rules where the `squadStore` enforces trait clamping and the `GameLoop` manages temporal state decay, such as injury timers and weekly wage calculations. Finally, it mandates that all processed mutations trigger a `SyncRequest` to ensure deterministic client-side updates are synchronized with the server to maintain global leaderboard integrity.

### [docs/frontend-integration.md](docs/frontend-integration.md)
> AI Summary: The `docs/frontend-integration.md` file serves as a comprehensive technical blueprint for connecting a React Native application to the Wunderkind backend, mandating a JWT-based authentication flow and environment-specific configurations. It documents the foundational architectural decision to represent all financial balances and transactions as integers in pence/cents, ensuring precision during the critical weekly synchronization process that handles aggregate game deltas, wages, and automated state updates. Additionally, the guide defines a hybrid logic model where narrative events and player archetypes are fetched from the server as weighted templates for client-side simulation, maintaining consistency across the game ecosystem. Finally, it provides exhaustive endpoint specifications for core systems like squad management, recruitment, facility upgrades, and financial oversight through a structured inbox and market system.

### [docs/frontend-spec-player-physical-personality.md](docs/frontend-spec-player-physical-personality.md)
> AI Summary: This document provides a frontend specification for displaying player physical attributes (height and weight) and an eight-trait personality matrix, emphasizing that all values are server-side generated rather than client-calculated. Architecturally, it establishes that while the API provides metric integers (cm and kg) optimized for youth academy ranges, the client is responsible for imperial unit conversions and handling growth/aging logic on-device. The personality matrix utilizes a standardized 1–20 scale for attributes like determination and professionalism, intended to be displayed as integers to inform user experience and player evaluation.

### [docs/superpowers/plans/2026-04-14-npc-club-generation.md](docs/superpowers/plans/2026-04-14-npc-club-generation.md)
> AI Summary: I will read the file `docs/superpowers/plans/2026-04-14-npc-club-generation.md` to provide a detailed summary of its purpose and architectural decisions.
This implementation plan details the backend architecture for NPC club persistence, the creation of a senior player pool, and a new "consume" endpoint for the market. Architecturally, NPC clubs are stored as pure metadata without foreign key links to players or staff, while senior players are integrated into a shared market pool with specific generation and replenishment parameters. A significant addition is the `POST /api/market/consume` endpoint, which allows the frontend to hard-delete claimed entities to ensure they are removed from the global pool. Furthermore, the plan introduces a tier-based generation service that scales club attributes such as reputation, balance, and facility levels across eight distinct league tiers.

### [docs/superpowers/plans/2026-04-18-admin-starter-config-league-ability-ranges.md](docs/superpowers/plans/2026-04-18-admin-starter-config-league-ability-ranges.md)
> AI Summary: I will read the specified documentation file to provide a detailed summary of its purpose and architectural decisions.
This implementation plan outlines the integration of a dynamic configuration matrix within the `StarterConfig` entity to manage player ability ranges across various countries and league tiers. Architecturally, the system utilizes a JSON database column for flexible storage and leverages EasyAdmin to dynamically generate management form inputs based on existing league records. The plan further details updates to the `WorldInitializationService` to apply these custom ranges during player generation while ensuring frontend compatibility by extending the TypeScript `StarterConfig` interface.

### [docs/superpowers/plans/2026-05-12-initialize-endpoint-redesign.md](docs/superpowers/plans/2026-05-12-initialize-endpoint-redesign.md)
> AI Summary: This document outlines an implementation plan to replace the monolithic `/api/initialize` endpoint with a chunked, multi-step process to ensure world generation is retryable and timeout-safe. Architecturally, the plan splits the workflow into distinct endpoints for assigning a starter squad (`/starter`), fetching league metadata (`/leagues`), and generating NPC squads on a per-tier basis (`/league/{tier}`). It introduces a `CountryWorldPackCache` entity to store generated squads incrementally, preventing timeouts and allowing interrupted initialization processes to resume seamlessly. Additionally, a new CLI command (`WarmWorldPackCommand`) is documented to allow pre-warming of the country cache, providing a mechanism to offload heavy generation workloads from synchronous API requests.

### [docs/superpowers/specs/2026-04-14-npc-club-generation-design.md](docs/superpowers/specs/2026-04-14-npc-club-generation-design.md)
> AI Summary: This design specification details the backend architecture for the NPC Club Generation system, introducing the `NpcClub` entity as persistent metadata to support the Club Sim expansion. It establishes a "producer, not tracker" architecture where the backend generates and serves entities via a market API but delegates state tracking and squad assembly to the frontend, making NPC clubs a unique exception for persistent storage. The `NpcClub` entity avoids direct relationships with players or staff, instead storing tier-scaled reputation, financial balance, and a JSON-based facilities configuration. This approach minimizes backend complexity while providing a structured foundation for future league and competition features.

### [docs/superpowers/specs/2026-05-12-initialize-endpoint-redesign.md](docs/superpowers/specs/2026-05-12-initialize-endpoint-redesign.md)
> AI Summary: I will read the specified documentation file to provide a detailed summary of its purpose and architectural decisions.
This documentation outlines the redesign of the monolithic `POST /api/initialize` endpoint into a series of four sequential, independently retryable endpoints to eliminate server-side timeouts and improve system resilience. The core architectural shift introduces a shared `CountryWorldPackCache` that persists NPC squad data at the country-tier level, ensuring that resource-heavy generation occurs only once per country rather than per club. To manage this new flow, the `Club` entity is extended with `starterInitializedAt` to track partial initialization progress, while specialized logic is decoupled into new `StarterPackService` and `WorldPackCacheService` components. This granular approach allows for reliable client-side retries and enables an idempotent "pre-warm" command to generate world data ahead of club registration.

### [docs/wunderkind-backend-context.md](docs/wunderkind-backend-context.md)
> AI Summary: The `docs/wunderkind-backend-context.md` file serves as an auto-generated, high-level entry point for the Wunderkind Factory backend, summarizing its technology stack (Symfony 8, PHP 8.4, PostgreSQL) and core project structure. Its primary purpose is to act as a navigational index for developers and AI assistants by aggregating metrics, documentation summaries, API routes, and architecture notes into a single reference. It documents key architectural decisions, most notably the project's client-authoritative hybrid sync model where gameplay simulations run entirely on-device while the API strictly handles validation, anti-cheat, and global leaderboards. Furthermore, it highlights architectural patterns such as the separation of concerns via Repositories and Services, the use of DTOs for API boundaries, and a core contract where server-defined JSON mutations dictate game event impacts that are executed locally by the client.

### [migrations/archive/README.md](migrations/archive/README.md)
> AI Summary: This README documents archived MySQL-specific migrations that were replaced by a PostgreSQL baseline during the project's database migration on March 26, 2026.

### [README.md](README.md)
> AI Summary: The Wunderkind Factory — Backend is a Symfony 8.0 and API Platform-powered system that facilitates a mobile football academy management game through a hybrid sync model for tracking player development and global academy legacy.

---

## Metrics

| Category | Count |
|---|---|
| PHP files         | 299 |
| Entities/Models   | 30 |
| Controllers       | 40 |
| Services          | 17 |
| Migrations        | 95 |

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
- `symfony/monolog-bundle`: ^4.0
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
│   │   ├── monolog.yaml
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
│   └── Version20260516000001.php
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
│   │   ├── SetExistingClubBalancesCommand.php
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
│   │   ├── CountryWorldPackCacheRepository.php
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
│   │   ├── game_config.html.twig
│   │   ├── login.html.twig
│   │   ├── logs.html.twig
│   │   ├── narrative_content.html.twig
│   │   ├── npc_clubs_content.html.twig
│   │   ├── player_index.html.twig
│   │   ├── pool_config.html.twig
│   │   ├── settings.html.twig
│   │   ├── starter_config.html.twig
│   │   └── world_content.html.twig
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

50 directories, 362 files
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
    public function __construct(
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
    public function generatePlayers(int $count, RecruitmentSource $source = RecruitmentSource::YOUTH_INTAKE, ?string $nationality = null): array
    public function generateStaffForRole(StaffRole $role, int $count): array
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
    public function deleteByCountry(string $country): int
```


---

## Migrations

| Migration | Date |
|---|---|
| `Version20260508094245` | 20260508 |
| `Version20260508202213` | 20260508 |
| `Version20260509133608` | 20260509 |
| `Version20260510091816` | 20260510 |
| `Version20260510093027` | 20260510 |
| `Version20260511121000` | 20260511 |
| `Version20260511181003` | 20260511 |
| `Version20260511182946` | 20260511 |
| `Version20260512000001` | 20260512 |
| `Version20260516000001` | 20260516 |
_Showing latest 10 of 95 total._

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
fe6b537 manager styles
47841a7 tweaked name generation
0a5247f fix: add strlen guard and per-tier exception handling to WarmWorldPackCommand
1d65561 feat: add WarmWorldPackCommand (app:worldpack:warm {country} [--force])
f1500f4 fix: add missing ClubInitializationService use import to StarterPackService
349ab0b feat: split InitializeController into starter/leagues/tier endpoints
3138172 fix: deduplicate ampScouts after nationality backfill
1199e3c feat: add StarterPackService (AMP squad assembly extracted from WorldInitializationService)
2aa264c feat: add WorldPackCacheService (getOrBuild, allTiersCached, deleteByCountry)
4eac6e4 fix: align json/char column types, add getId(), add SORT_REGULAR comment
310e2d5 refactor: promote snapshot methods to public, add buildTierPack(), remove initialize()
c2a2783 feat: add CountryWorldPackCache entity and repository
507e109 fix: make setStarterInitializedAt return static to match worldInitializedAt pattern
5d7d9e3 feat: add starterInitializedAt to Club + country_world_pack_cache migration
6f48d1c docs: initialize endpoint redesign implementation plan
```

---

## Architecture Notes

* **Service Layer Pattern**: Business logic is centralized in specialized service classes within `src/Service`.
* **Repository Pattern**: Data persistence and retrieval logic are abstracted into the `src/Repository` layer.
* **Data Transfer Object (DTO) Pattern**: Specialized objects in `src/Dto` are utilized for structured data exchange between layers.
* **API Resource Pattern**: The application leverages `src/ApiResource` to decouple external API contracts from internal Doctrine entities.

---

## Current Development Focus

* Optimization and unit testing of the refactored game initialization workflow, specifically the decoupled `StarterPackService` and split `InitializeController` endpoints.
* Enhancement of procedural content generation logic within `NameGeneratorService` and the `Staff` entity to ensure diverse and realistic game data.
* Performance tuning and concurrency management for the `WarmWorldPackCommand` and `WorldPackCacheService` to handle large-scale world-building operations.
* Refinement of the EasyAdmin dashboard and Twig templates to provide a more sophisticated interface for managing `GameConfig` and manager styles.
* Implementation of robust validation and error-handling strategies for the recently added JSON and character-based column types in core entities.

---

> _AI summaries generated using **gemini**._
