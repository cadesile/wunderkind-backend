# Services

#### `AccountDeletionService`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._

```php
public function __construct(private readonly EntityManagerInterface $em) {}
    public function deleteAccount(User $user): void
```

#### `AppearanceGeneratorService`

> **Purpose:** Deterministic avatar generation (port of frontend `generateAppearance`); paired with `AppearanceLifecycleSubscriber` (prePersist auto-fill) — see Avatar Appearance above

```php
public function generate(string $id, AppearanceRole $role, int $age): array
```

#### `SeededRng`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._

```php
public function __construct(int $seed)
    public function next(): float
    public function pick(array $arr): mixed
    public function chance(float $probability): bool
```

#### `ClubInitializationService`

> **Purpose:** Create Club entity, set paName + manager traits, abbreviation

```php
public function __construct(
    public function initializeClub(User $user, string $clubName, ?string $country = null, ?array $managerProfile = null): Club
    public function getStarterBundle(): array
```

#### `CommunityStatsService`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._

```php
public function __construct(
    public function getMostTransfers(StatsPeriod $period, int $limit): array
    public function getMostDevelopment(StatsPeriod $period, int $limit): array
    public function getMostSeasons(StatsPeriod $period, int $limit): array
    public function getMostTrophies(StatsPeriod $period, int $limit): array
```

#### `ConfigImportExportService`

> **Purpose:** Export/import `GameConfig`, `StarterConfig`, and `PoolConfig` rows as JSON

```php
public function __construct(
    public function export(): array
    public function import(array $data): array
```

#### `EconomicService`

> **Purpose:** Financial year-end, sponsor contracts, player market value

```php
public function __construct(
    public function generateSponsorOffer(Club $club): array
    public function generateInvestorOffer(Club $club): array
    public function calculatePlayerMarketValue(Player $player): int
    public function processFinancialYearEnd(Club $club): array
    public function checkSponsorContracts(Club $club, int $currentReputation): void
```

#### `EmailVerificationService`

> **Purpose:** Send and validate email verification / password reset tokens

```php
public function __construct(
    public function sendVerificationEmail(User $user): void
    public function sendPasswordResetEmail(User $user): void
    public function sendPasswordResetConfirmationEmail(User $user): void
    public function sendBetaVerificationEmail(string $toEmail, string $code): void
    public function verifyCode(User $user, string $code): string
    public function verifyPasswordResetCode(User $user, string $code): string
```

#### `FixtureGenerationService`

> **Purpose:** Generate match fixtures for a league season

```php
public function generate(array $clubIds): array
```

#### `InboxService`

> **Purpose:** Generate and respond to inbox offers (sponsors, investors)

```php
public function __construct(
    public function sendSponsorOffer(Club $club, array $offerData): InboxMessage
    public function sendInvestorOffer(Club $club, array $offerData): InboxMessage
    public function sendSystemNotification(Club $club, string $subject, string $body, array $details = []): InboxMessage
    public function acceptMessage(InboxMessage $message, User $user): void
    public function rejectMessage(InboxMessage $message): void
```

#### `LeagueImportExportService`

> **Purpose:** Export/import `League` + `NpcClub` world data (used for admin-driven world pack management)

```php
public function __construct(
    public function export(): array
    public function import(array $data): array
    public function clearAll(): void
```

#### `LeagueService`

> **Purpose:** Assign clubs to leagues, conclude seasons, roll league sponsors

```php
public function __construct(
    public function generateLeaguesForCountry(string $country): array
    public function assignClubToLeague(NpcClub $club): void
    public function assignClubToStarterLeague(Club $club, string $country): void
    public function rollLeagueSponsors(League $league, GameConfig $config): int
    public function concludeSeason(Club $club, ConcludeSeasonRequest $dto): array
```

#### `MarketDataService`

> **Purpose:** Serve market data to the client

```php
public function __construct(private readonly MarketPoolService $pool) {}
    public function getMarketSnapshot(?Tier $tier = null): MarketDataResponse
```

#### `MarketPoolService`

> **Purpose:** Generate and assign market entities; Player/Staff assign deletes entity and returns snapshot

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

#### `NameGeneratorService`

> **Purpose:** Procedural name generation for players and PA personas

```php
public function generateName(string $nationality): string
    public function generatePlayerName(string $nationality): array
    public function generateFirstName(string $nationality): string
    public function generateLastName(string $nationality): string
    public function getRandomNationality(): string
```

#### `NarrativeImportExportService`

> **Purpose:** Export/import event templates, facility templates, player archetypes, and `TacticalAdvantage` rows

```php
public function __construct(
    public function export(): array
    public function clearAll(): void
    public function import(array $data): array
```

#### `NpcClubGenerationService`

> **Purpose:** Generate NPC clubs with names, colors, facilities, and ability by tier

```php
public function __construct(
    public function getPlaceNames(string $countryCode): array
    public function getSuffixes(string $countryCode): array
    public function generateClubs(int $count, int $tier, string $country, bool $deleteExisting = false): array
```

#### `PeriodResolver`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._

```php
public function __construct(
    public function applyPeriodFilter(
```

#### `PlayerGenerationService`

> **Purpose:** Procedurally generate a `Player` from archetype, position, and source

```php
public function __construct(
    public function generate(PlayerPosition $position, RecruitmentSource $source, ?string $nationality = null): Player
```

#### `SocialPostRenderer`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._

```php
public function __construct(private readonly CommunityStatsService $statsService)
    public function render(SocialPostTemplate $template): ?string
```

#### `SocialPostingService`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._

```php
public function __construct(
    public function post(SocialAccountConnection $connection, string $text): void
```

#### `StarterPackService`

> **Purpose:** Pull starting Player/Staff/Scout from pool; build snapshots; delete consumed Player/Staff

```php
public function __construct(
    public function initialize(Club $club): array
```

#### `SyncService`

> **Purpose:** Sync processing, anti-cheat, leaderboard upsert, manager trait shifts

```php
public function __construct(
    public function process(User $user, SyncRequest $request): array
```

#### `TokenEncryptionService`

> _No hand-written notes found in CLAUDE.md/AGENTS.md/README.md for this name._

```php
public function __construct(string $socialTokenEncryptionKey)
    public function encrypt(string $plaintext): string
    public function decrypt(string $encoded): string
```

#### `TransferLeaderboardService`

> **Purpose:** Rank players by transfer fee across clubs

```php
public function __construct(
    public function getTopSellers(string $period = 'week', int $limit = 10): array
    public function getMostValuableSale(string $period = 'week'): ?array
```

#### `WorldInitializationService`

> **Purpose:** Build the full league pyramid + tier pack snapshot for a client; snapshot builders for Player/Staff/Scout

```php
public function __construct(
    public function buildLeaguesPack(Club $club, string $country): array
    public function buildTierPack(Club $club, string $country, int $tier): array
    public function distributeByPosition(int $total, PoolConfig $config): array
    public function assignAgents(array $players, array $agents): void
    public function selectBoundedAgentPool(array $agents, int $estimatedPlayers, int $playersPerAgent): array
    public function buildPlayerSnapshot(Player $player): array
    public function buildStaffSnapshot(Staff $staff): array
    public function buildScoutSnapshot(Scout $scout): array
```

#### `WorldPackCacheService`

> **Purpose:** Cache country/nationality worldpack data (`CountryWorldPackCache`)

```php
public function __construct(
    public function getOrBuild(string $country, int $tier, callable $generator): array
    public function allTiersCached(string $country, array $tierNumbers): bool
    public function forceRebuild(string $country, int $tier, callable $generator): array
    public function deleteByCountry(string $country): int
```
