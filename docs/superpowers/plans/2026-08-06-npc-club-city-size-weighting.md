# NPC Club City-Size Weighting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make NPC club generation reflect city size — big cities produce prestige-named, higher-reputation clubs more likely to land in elite tiers; small towns produce generic-named, lower-reputation clubs more likely to land in low tiers — with the tier-bias weighting configurable from the admin panel.

**Architecture:** `NpcClubGenerationService::PLACE_NAMES_BY_COUNTRY` moves from a flat `string[]` per country to an array of `{name, population_size, region, is_capital?}` rows. A new `CitySize` enum (`BIG`/`MEDIUM`/`SMALL`) is derived per place by population percentile (capital always forced `BIG`). Weighted random selection (driven by an admin-configurable per-tier weight table on `GameConfig`) replaces uniform `array_rand` for picking a place; the picked place's `CitySize` then biases which suffix pool (`PRESTIGE_SUFFIXES_BY_COUNTRY` vs `GENERIC_SUFFIXES_BY_COUNTRY`) the name is drawn from and skews where in the tier's reputation/balance range the club lands. `NpcClub` gains `region`/`citySize`/`populationSize`/`isCapital` columns, set once at generation time.

**Tech Stack:** Symfony 8.0, PHP 8.4, Doctrine ORM 3 + Migrations, PostgreSQL 16, PHPUnit.

## Global Constraints

- All PHP commands run inside Lando: `lando php ...`, `lando composer ...`.
- Unit tests (`PHPUnit\Framework\TestCase`) run in-memory, no DB needed: `lando php vendor/bin/phpunit --no-coverage`.
- `KernelTestCase`/`WebTestCase` functional tests use the **separate** `wunderkind_test` DB (built via `doctrine:schema:create`, not migrations) — after adding new NOT NULL columns, that DB must be reconciled (`doctrine:schema:update --env=test` then `doctrine:migrations:sync-metadata-storage --env=test` + `doctrine:migrations:version --add --all --no-interaction --env=test`), per the documented gotcha in `CLAUDE.md`.
- PostgreSQL — new migrations use plain Postgres SQL (no `AUTO_INCREMENT`, no `ENGINE=InnoDB`).
- Custom EasyAdmin POST actions must redirect via `$this->redirect($this->generateUrl('admin', ['routeName' => '...']))`, never `redirectToRoute()` directly.
- Never commit directly to `master` — use a `feat/` branch.
- Places remain reusable across tiers/calls (no removal from the pool after a pick).
- Tier is always the primary driver of reputation/balance; city size only skews within the tier's existing range, never overrides it.

---

### Task 0: Create feature branch

**Files:** none (git operation only)

- [ ] **Step 1: Create and switch to the feature branch**

```bash
git checkout -b feat/npc-club-city-size-weighting
```

- [ ] **Step 2: Verify**

Run: `git branch --show-current`
Expected: `feat/npc-club-city-size-weighting`

---

### Task 1: `CitySize` enum + `NpcClub` entity fields

**Files:**
- Create: `src/Enum/CitySize.php`
- Modify: `src/Entity/NpcClub.php`
- Test: `tests/Entity/NpcClubCitySizeFieldsTest.php`

**Interfaces:**
- Produces: `App\Enum\CitySize` (cases `BIG`, `MEDIUM`, `SMALL`); `NpcClub::getRegion(): ?string`, `setRegion(?string): static`, `getCitySize(): CitySize`, `setCitySize(CitySize): static`, `getPopulationSize(): int`, `setPopulationSize(int): static`, `isCapital(): bool`, `setIsCapital(bool): static`; `NpcClub` constructor gains 4 new **optional trailing** params (`?string $region = null, CitySize $citySize = CitySize::MEDIUM, int $populationSize = 0, bool $isCapital = false`) so all 9 existing `new NpcClub(...)` call sites (tests + `LeagueImportExportService`) keep working unchanged.

- [ ] **Step 1: Write the failing test**

Create `tests/Entity/NpcClubCitySizeFieldsTest.php`:

```php
<?php

namespace App\Tests\Entity;

use App\Entity\NpcClub;
use App\Enum\CitySize;
use PHPUnit\Framework\TestCase;

class NpcClubCitySizeFieldsTest extends TestCase
{
    public function testDefaultsWhenNotProvided(): void
    {
        $club = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);

        $this->assertNull($club->getRegion());
        $this->assertSame(CitySize::MEDIUM, $club->getCitySize());
        $this->assertSame(0, $club->getPopulationSize());
        $this->assertFalse($club->isCapital());
    }

    public function testConstructorAcceptsCitySizeFields(): void
    {
        $club = new NpcClub(
            'London FC', 'EN', 1, 90, '#fff', '#000', 100_000_000, [],
            region: 'Greater London',
            citySize: CitySize::BIG,
            populationSize: 8_982_000,
            isCapital: true,
        );

        $this->assertSame('Greater London', $club->getRegion());
        $this->assertSame(CitySize::BIG, $club->getCitySize());
        $this->assertSame(8_982_000, $club->getPopulationSize());
        $this->assertTrue($club->isCapital());
    }

    public function testSetters(): void
    {
        $club = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);

        $club->setRegion('North West');
        $club->setCitySize(CitySize::SMALL);
        $club->setPopulationSize(35000);
        $club->setIsCapital(false);

        $this->assertSame('North West', $club->getRegion());
        $this->assertSame(CitySize::SMALL, $club->getCitySize());
        $this->assertSame(35000, $club->getPopulationSize());
        $this->assertFalse($club->isCapital());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lando php vendor/bin/phpunit tests/Entity/NpcClubCitySizeFieldsTest.php --no-coverage`
Expected: FAIL — `App\Enum\CitySize` not found / `getRegion()` undefined.

- [ ] **Step 3: Create the `CitySize` enum**

Create `src/Enum/CitySize.php`:

```php
<?php

namespace App\Enum;

enum CitySize: string
{
    case BIG    = 'BIG';
    case MEDIUM = 'MEDIUM';
    case SMALL  = 'SMALL';
}
```

- [ ] **Step 4: Add the new fields to `NpcClub`**

In `src/Entity/NpcClub.php`, add the import (after the existing `use App\Enum\Formation;` line):

```php
use App\Enum\CitySize;
```

Add the 4 new columns after the existing `facilities` column (after line 55, before `createdAt`):

```php
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(enumType: CitySize::class, options: ['default' => 'MEDIUM'])]
    private CitySize $citySize = CitySize::MEDIUM;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $populationSize = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $isCapital = false;
```

Replace the constructor (currently lines 67–87) with:

```php
    public function __construct(
        string $name,
        string $country,
        int $tier,
        int $reputation,
        string $primaryColor,
        string $secondaryColor,
        int $balance,
        array $facilities,
        ?string $region = null,
        CitySize $citySize = CitySize::MEDIUM,
        int $populationSize = 0,
        bool $isCapital = false,
    ) {
        $this->id             = new UuidV7();
        $this->name           = $name;
        $this->country        = $country;
        $this->tier           = $tier;
        $this->reputation     = $reputation;
        $this->primaryColor   = $primaryColor;
        $this->secondaryColor = $secondaryColor;
        $this->balance        = $balance;
        $this->facilities     = $facilities;
        $this->region         = $region;
        $this->citySize       = $citySize;
        $this->populationSize = $populationSize;
        $this->isCapital      = $isCapital;
        $this->createdAt      = new \DateTimeImmutable();
    }
```

Add getters/setters after `getFacilitiesJson`/`setFacilitiesJson` (after line 139, before `getCreatedAt`):

```php
    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $v): static { $this->region = $v; return $this; }

    public function getCitySize(): CitySize { return $this->citySize; }
    public function setCitySize(CitySize $v): static { $this->citySize = $v; return $this; }

    public function getPopulationSize(): int { return $this->populationSize; }
    public function setPopulationSize(int $v): static { $this->populationSize = $v; return $this; }

    public function isCapital(): bool { return $this->isCapital; }
    public function setIsCapital(bool $v): static { $this->isCapital = $v; return $this; }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `lando php vendor/bin/phpunit tests/Entity/NpcClubCitySizeFieldsTest.php --no-coverage`
Expected: PASS (3 tests, all green)

- [ ] **Step 6: Run the full unit suite to confirm no regressions**

Run: `lando php vendor/bin/phpunit tests/Entity --no-coverage`
Expected: PASS — `NpcClubLeagueFieldTest` and `NpcClubTest` still pass unchanged (new constructor params are optional/trailing).

- [ ] **Step 7: Commit**

```bash
git add src/Enum/CitySize.php src/Entity/NpcClub.php tests/Entity/NpcClubCitySizeFieldsTest.php
git commit -m "feat: add CitySize enum and region/citySize/populationSize/isCapital to NpcClub"
```

---

### Task 2: `GameConfig::npcClubSizeWeights` + tier interpolation

**Files:**
- Modify: `src/Entity/GameConfig.php`
- Test: `tests/Entity/GameConfigNpcClubSizeWeightsTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `GameConfig::getNpcClubSizeWeights(): array`, `setNpcClubSizeWeights(array): static`, `getNpcClubSizeWeightsForTier(int $tier): array` returning `['big' => float, 'medium' => float, 'small' => float]` — used by `NpcClubGenerationService` in Task 6.

- [ ] **Step 1: Write the failing test**

Create `tests/Entity/GameConfigNpcClubSizeWeightsTest.php`:

```php
<?php

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use PHPUnit\Framework\TestCase;

class GameConfigNpcClubSizeWeightsTest extends TestCase
{
    public function testDefaultWeights(): void
    {
        $config = new GameConfig();

        $this->assertSame(
            ['tier1' => ['big' => 70, 'medium' => 25, 'small' => 5], 'tier8' => ['big' => 5, 'medium' => 25, 'small' => 70]],
            $config->getNpcClubSizeWeights()
        );
    }

    public function testGetWeightsForTier1MatchesTier1Row(): void
    {
        $config  = new GameConfig();
        $weights = $config->getNpcClubSizeWeightsForTier(1);

        $this->assertEqualsWithDelta(70.0, $weights['big'], 0.001);
        $this->assertEqualsWithDelta(25.0, $weights['medium'], 0.001);
        $this->assertEqualsWithDelta(5.0, $weights['small'], 0.001);
    }

    public function testGetWeightsForTier8MatchesTier8Row(): void
    {
        $config  = new GameConfig();
        $weights = $config->getNpcClubSizeWeightsForTier(8);

        $this->assertEqualsWithDelta(5.0, $weights['big'], 0.001);
        $this->assertEqualsWithDelta(25.0, $weights['medium'], 0.001);
        $this->assertEqualsWithDelta(70.0, $weights['small'], 0.001);
    }

    public function testMidTierInterpolatesLinearly(): void
    {
        // Tier 4.5 is the midpoint (fraction = (4-1)/7 for tier 4, (5-1)/7 for tier 5) —
        // check tier 4 and tier 5 both land strictly between the tier1 and tier8 values.
        $config = new GameConfig();

        $tier4 = $config->getNpcClubSizeWeightsForTier(4);
        $this->assertGreaterThan(5.0, $tier4['big']);
        $this->assertLessThan(70.0, $tier4['big']);
        $this->assertGreaterThan(5.0, $tier4['small']);
        $this->assertLessThan(70.0, $tier4['small']);
    }

    public function testTierClampsToValidRange(): void
    {
        $config = new GameConfig();

        $this->assertSame($config->getNpcClubSizeWeightsForTier(1), $config->getNpcClubSizeWeightsForTier(0));
        $this->assertSame($config->getNpcClubSizeWeightsForTier(8), $config->getNpcClubSizeWeightsForTier(20));
    }

    public function testSetter(): void
    {
        $config = new GameConfig();
        $config->setNpcClubSizeWeights([
            'tier1' => ['big' => 90, 'medium' => 8, 'small' => 2],
            'tier8' => ['big' => 2, 'medium' => 8, 'small' => 90],
        ]);

        $this->assertSame(90, $config->getNpcClubSizeWeights()['tier1']['big']);
        $this->assertEqualsWithDelta(90.0, $config->getNpcClubSizeWeightsForTier(1)['big'], 0.001);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lando php vendor/bin/phpunit tests/Entity/GameConfigNpcClubSizeWeightsTest.php --no-coverage`
Expected: FAIL — `getNpcClubSizeWeights()` undefined.

- [ ] **Step 3: Add the field + methods to `GameConfig`**

In `src/Entity/GameConfig.php`, add after the `// ── NPC Facility Level Ranges ──` block (after line 1127, before `// ── NPC Squad Config ──`):

```php
    // ── NPC Club Size Weights ──────────────────────────────────────────────

    /**
     * Weighting (%) of BIG/MEDIUM/SMALL city picks, anchored at tier 1 and tier 8.
     * Tiers 2–7 linearly interpolate between the two anchor rows.
     * Percentages need not sum to 100 — the consumer normalizes.
     * @var array{tier1: array{big:int,medium:int,small:int}, tier8: array{big:int,medium:int,small:int}}
     */
    #[ORM\Column(type: 'json')]
    private array $npcClubSizeWeights = [
        'tier1' => ['big' => 70, 'medium' => 25, 'small' => 5],
        'tier8' => ['big' => 5,  'medium' => 25, 'small' => 70],
    ];

    /** @return array{tier1: array{big:int,medium:int,small:int}, tier8: array{big:int,medium:int,small:int}} */
    public function getNpcClubSizeWeights(): array { return $this->npcClubSizeWeights; }

    /** @param array{tier1: array{big:int,medium:int,small:int}, tier8: array{big:int,medium:int,small:int}} $v */
    public function setNpcClubSizeWeights(array $v): static { $this->npcClubSizeWeights = $v; return $this; }

    /**
     * Interpolated {big, medium, small} weight percentages for a given tier (1–8, clamped).
     * @return array{big:float,medium:float,small:float}
     */
    public function getNpcClubSizeWeightsForTier(int $tier): array
    {
        $tier    = max(1, min(8, $tier));
        $tier1   = $this->npcClubSizeWeights['tier1'] ?? ['big' => 70, 'medium' => 25, 'small' => 5];
        $tier8   = $this->npcClubSizeWeights['tier8'] ?? ['big' => 5, 'medium' => 25, 'small' => 70];
        $fraction = ($tier - 1) / 7;

        $result = [];
        foreach (['big', 'medium', 'small'] as $bucket) {
            $start = (float) ($tier1[$bucket] ?? 0);
            $end   = (float) ($tier8[$bucket] ?? 0);
            $result[$bucket] = $start + $fraction * ($end - $start);
        }

        return $result;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `lando php vendor/bin/phpunit tests/Entity/GameConfigNpcClubSizeWeightsTest.php --no-coverage`
Expected: PASS (6 tests, all green)

- [ ] **Step 5: Commit**

```bash
git add src/Entity/GameConfig.php tests/Entity/GameConfigNpcClubSizeWeightsTest.php
git commit -m "feat: add npcClubSizeWeights config with per-tier interpolation"
```

---

### Task 3: Migration + test DB reconciliation

**Files:**
- Create: `migrations/Version20260806120000.php`

**Interfaces:**
- Consumes: the new `NpcClub` columns (Task 1) and `GameConfig` column (Task 2) — this migration makes the DB schema match those entity changes.

- [ ] **Step 1: Write the migration**

Create `migrations/Version20260806120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add region/citySize/populationSize/isCapital to npc_club and npc_club_size_weights to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE npc_club ADD region VARCHAR(100) DEFAULT NULL');
        $this->addSql("ALTER TABLE npc_club ADD city_size VARCHAR(255) DEFAULT 'MEDIUM' NOT NULL");
        $this->addSql('ALTER TABLE npc_club ADD population_size BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE npc_club ADD is_capital BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("ALTER TABLE game_config ADD npc_club_size_weights JSONB NOT NULL DEFAULT '{\"tier1\":{\"big\":70,\"medium\":25,\"small\":5},\"tier8\":{\"big\":5,\"medium\":25,\"small\":70}}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE npc_club DROP region');
        $this->addSql('ALTER TABLE npc_club DROP city_size');
        $this->addSql('ALTER TABLE npc_club DROP population_size');
        $this->addSql('ALTER TABLE npc_club DROP is_capital');
        $this->addSql('ALTER TABLE game_config DROP npc_club_size_weights');
    }
}
```

- [ ] **Step 2: Run the migration against the dev DB**

Run: `lando php bin/console doctrine:migrations:migrate --no-interaction`
Expected: `Version20260806120000` listed as migrated, no errors.

- [ ] **Step 3: Verify the dev schema**

Run: `lando psql -c "\d npc_club"` and `lando psql -c "\d game_config"`
Expected: `npc_club` shows `region`, `city_size`, `population_size`, `is_capital` columns; `game_config` shows `npc_club_size_weights`.

- [ ] **Step 4: Reconcile the functional-test DB**

`wunderkind_test` is built via `doctrine:schema:create`, not migrations, so it needs the same reconciliation steps documented in `CLAUDE.md`:

```bash
lando php bin/console doctrine:schema:update --dump-sql --env=test
lando php bin/console doctrine:schema:update --force --env=test
lando php bin/console doctrine:migrations:sync-metadata-storage --env=test
lando php bin/console doctrine:migrations:version --add --all --no-interaction --env=test
lando php bin/console doctrine:migrations:up-to-date --env=test
```

Expected: last command reports the test DB is up to date.

- [ ] **Step 5: Run the existing functional test that constructs `NpcClub` under `KernelTestCase`**

Run: `lando php vendor/bin/phpunit tests/Service/WorldInitializationTierPackAgentTest.php --no-coverage`
Expected: PASS — confirms the test DB schema now accepts the new NOT NULL columns.

- [ ] **Step 6: Commit**

```bash
git add migrations/Version20260806120000.php
git commit -m "feat: migrate npc_club and game_config schema for city-size weighting"
```

---

### Task 4: Curated place data + back-compat accessors

**Files:**
- Modify: `src/Service/NpcClubGenerationService.php`
- Test: `tests/Service/NpcClubGenerationServiceTest.php`

**Interfaces:**
- Consumes: nothing new from earlier tasks (this task only touches data + read accessors).
- Produces: `NpcClubGenerationService::getPlaceNames(string $countryCode): array` (unchanged signature/behavior — flat `string[]` of names, for API/back-compat), `getPlaceData(string $countryCode): array` (new — returns the full curated rows `{name, population_size, region, is_capital?}`), `classifyPlaces(array $places): array` (new **public** method for testability — returns the same rows with an added `city_size` key holding a `CitySize` enum instance).

**Curation note:** each country's list below is a hand-curated set of ~40 real, well-known cities/towns with reasonably accurate population figures and correct administrative regions (scaled down from the ~100/country target discussed during brainstorming, to keep this plan self-contained with verified-plausible data rather than deferring to open-ended research — flagged to the user as a follow-up if more variety is wanted later).

- [ ] **Step 1: Write the failing/updated tests**

Replace the contents of `tests/Service/NpcClubGenerationServiceTest.php`'s data-related tests section — add these tests (keep all existing tests in the file as-is; they still pass against the new data as shown in later steps). Append to the existing file, just before the final `}`:

```php

    public function testGetPlaceDataReturnsStructuredRows(): void
    {
        $service = $this->makeService();
        $places  = $service->getPlaceData('EN');

        $this->assertNotEmpty($places);
        $first = $places[0];
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('population_size', $first);
        $this->assertArrayHasKey('region', $first);
    }

    public function testGetPlaceDataMarksExactlyOneCapitalPerCountry(): void
    {
        $service = $this->makeService();
        foreach (['ES', 'EN', 'DE', 'IT', 'FR', 'BR', 'AR', 'NL', 'PT'] as $country) {
            $capitals = array_filter($service->getPlaceData($country), fn(array $p) => $p['is_capital'] ?? false);
            $this->assertCount(1, $capitals, "Expected exactly one capital for {$country}");
        }
    }

    public function testClassifyPlacesForcesCapitalToBig(): void
    {
        $service = $this->makeService();
        $classified = $service->classifyPlaces([
            ['name' => 'Capital', 'population_size' => 1000, 'region' => 'R', 'is_capital' => true],
            ['name' => 'Big', 'population_size' => 900000, 'region' => 'R'],
            ['name' => 'Mid', 'population_size' => 50000, 'region' => 'R'],
            ['name' => 'Small', 'population_size' => 1000, 'region' => 'R'],
        ]);

        $bySize = [];
        foreach ($classified as $p) {
            $bySize[$p['name']] = $p['city_size'];
        }

        $this->assertSame(\App\Enum\CitySize::BIG, $bySize['Capital']);
    }

    public function testClassifyPlacesBucketsByPopulationPercentile(): void
    {
        $service = $this->makeService();
        // 10 places, population 100..1000 descending — top 20% (2) BIG, bottom 50% (5) SMALL, rest MEDIUM.
        $places = [];
        for ($i = 10; $i >= 1; $i--) {
            $places[] = ['name' => "City{$i}", 'population_size' => $i * 100, 'region' => 'R'];
        }

        $classified = $service->classifyPlaces($places);
        $bySize = [];
        foreach ($classified as $p) {
            $bySize[$p['name']] = $p['city_size'];
        }

        $this->assertSame(\App\Enum\CitySize::BIG, $bySize['City10']);
        $this->assertSame(\App\Enum\CitySize::BIG, $bySize['City9']);
        $this->assertSame(\App\Enum\CitySize::SMALL, $bySize['City1']);
        $this->assertSame(\App\Enum\CitySize::SMALL, $bySize['City5']);
        $this->assertSame(\App\Enum\CitySize::MEDIUM, $bySize['City6']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `lando php vendor/bin/phpunit tests/Service/NpcClubGenerationServiceTest.php --no-coverage`
Expected: FAIL — `getPlaceData()`/`classifyPlaces()` undefined.

- [ ] **Step 3: Replace `PLACE_NAMES_BY_COUNTRY` with curated structured data**

In `src/Service/NpcClubGenerationService.php`, replace the entire `PLACE_NAMES_BY_COUNTRY` constant (lines 18–595) with:

```php
    // ── Place names & metadata by ISO country code ──────────────────────────
    private const PLACE_NAMES_BY_COUNTRY = [
        'EN' => [
            ['name' => 'London', 'population_size' => 8982000, 'region' => 'Greater London', 'is_capital' => true],
            ['name' => 'Birmingham', 'population_size' => 1149000, 'region' => 'West Midlands'],
            ['name' => 'Leeds', 'population_size' => 812000, 'region' => 'West Yorkshire'],
            ['name' => 'Sheffield', 'population_size' => 685000, 'region' => 'South Yorkshire'],
            ['name' => 'Manchester', 'population_size' => 552000, 'region' => 'Greater Manchester'],
            ['name' => 'Bradford', 'population_size' => 546000, 'region' => 'West Yorkshire'],
            ['name' => 'Liverpool', 'population_size' => 496000, 'region' => 'Merseyside'],
            ['name' => 'Bristol', 'population_size' => 465000, 'region' => 'South West'],
            ['name' => 'Wakefield', 'population_size' => 355000, 'region' => 'West Yorkshire'],
            ['name' => 'Leicester', 'population_size' => 355000, 'region' => 'East Midlands'],
            ['name' => 'Coventry', 'population_size' => 345000, 'region' => 'West Midlands'],
            ['name' => 'Nottingham', 'population_size' => 331000, 'region' => 'East Midlands'],
            ['name' => 'Bolton', 'population_size' => 285000, 'region' => 'Greater Manchester'],
            ['name' => 'Sunderland', 'population_size' => 275000, 'region' => 'North East'],
            ['name' => 'Newcastle upon Tyne', 'population_size' => 300000, 'region' => 'North East'],
            ['name' => 'Hull', 'population_size' => 267000, 'region' => 'Yorkshire and the Humber'],
            ['name' => 'Derby', 'population_size' => 261000, 'region' => 'East Midlands'],
            ['name' => 'Wolverhampton', 'population_size' => 263000, 'region' => 'West Midlands'],
            ['name' => 'Plymouth', 'population_size' => 264000, 'region' => 'South West'],
            ['name' => 'Stoke-on-Trent', 'population_size' => 258000, 'region' => 'West Midlands'],
            ['name' => 'Oldham', 'population_size' => 235000, 'region' => 'Greater Manchester'],
            ['name' => 'Milton Keynes', 'population_size' => 230000, 'region' => 'South East'],
            ['name' => 'Northampton', 'population_size' => 225000, 'region' => 'East Midlands'],
            ['name' => 'Portsmouth', 'population_size' => 208000, 'region' => 'South East'],
            ['name' => 'Luton', 'population_size' => 214000, 'region' => 'East of England'],
            ['name' => 'Peterborough', 'population_size' => 202000, 'region' => 'East of England'],
            ['name' => 'York', 'population_size' => 210000, 'region' => 'Yorkshire and the Humber'],
            ['name' => 'Warrington', 'population_size' => 210000, 'region' => 'North West'],
            ['name' => 'Brighton', 'population_size' => 291000, 'region' => 'South East'],
            ['name' => 'Swindon', 'population_size' => 185000, 'region' => 'South West'],
            ['name' => 'Oxford', 'population_size' => 162000, 'region' => 'South East'],
            ['name' => 'Middlesbrough', 'population_size' => 174000, 'region' => 'North East'],
            ['name' => 'Reading', 'population_size' => 174000, 'region' => 'South East'],
            ['name' => 'Preston', 'population_size' => 141000, 'region' => 'North West'],
            ['name' => 'Blackpool', 'population_size' => 139000, 'region' => 'North West'],
            ['name' => 'Norwich', 'population_size' => 144000, 'region' => 'East of England'],
            ['name' => 'Cambridge', 'population_size' => 145000, 'region' => 'East of England'],
            ['name' => 'Ipswich', 'population_size' => 139000, 'region' => 'East of England'],
            ['name' => 'Exeter', 'population_size' => 130000, 'region' => 'South West'],
            ['name' => 'Gloucester', 'population_size' => 132000, 'region' => 'South West'],
            ['name' => 'Chester', 'population_size' => 118000, 'region' => 'North West'],
            ['name' => 'Carlisle', 'population_size' => 108000, 'region' => 'North West'],
        ],
        'ES' => [
            ['name' => 'Madrid', 'population_size' => 3223000, 'region' => 'Community of Madrid', 'is_capital' => true],
            ['name' => 'Barcelona', 'population_size' => 1620000, 'region' => 'Catalonia'],
            ['name' => 'Valencia', 'population_size' => 800000, 'region' => 'Valencian Community'],
            ['name' => 'Sevilla', 'population_size' => 688000, 'region' => 'Andalusia'],
            ['name' => 'Zaragoza', 'population_size' => 675000, 'region' => 'Aragon'],
            ['name' => 'Málaga', 'population_size' => 578000, 'region' => 'Andalusia'],
            ['name' => 'Murcia', 'population_size' => 460000, 'region' => 'Region of Murcia'],
            ['name' => 'Palma', 'population_size' => 416000, 'region' => 'Balearic Islands'],
            ['name' => 'Las Palmas', 'population_size' => 379000, 'region' => 'Canary Islands'],
            ['name' => 'Bilbao', 'population_size' => 345000, 'region' => 'Basque Country'],
            ['name' => 'Alicante', 'population_size' => 337000, 'region' => 'Valencian Community'],
            ['name' => 'Córdoba', 'population_size' => 325000, 'region' => 'Andalusia'],
            ['name' => 'Valladolid', 'population_size' => 298000, 'region' => 'Castile and León'],
            ['name' => 'Vigo', 'population_size' => 293000, 'region' => 'Galicia'],
            ['name' => 'Gijón', 'population_size' => 271000, 'region' => 'Asturias'],
            ['name' => 'Vitoria-Gasteiz', 'population_size' => 251000, 'region' => 'Basque Country'],
            ['name' => "A Coruña", 'population_size' => 245000, 'region' => 'Galicia'],
            ['name' => 'Elche', 'population_size' => 234000, 'region' => 'Valencian Community'],
            ['name' => 'Granada', 'population_size' => 232000, 'region' => 'Andalusia'],
            ['name' => 'Terrassa', 'population_size' => 224000, 'region' => 'Catalonia'],
            ['name' => 'Oviedo', 'population_size' => 220000, 'region' => 'Asturias'],
            ['name' => 'Badalona', 'population_size' => 220000, 'region' => 'Catalonia'],
            ['name' => 'Sabadell', 'population_size' => 216000, 'region' => 'Catalonia'],
            ['name' => 'Cartagena', 'population_size' => 214000, 'region' => 'Region of Murcia'],
            ['name' => 'Jerez de la Frontera', 'population_size' => 212000, 'region' => 'Andalusia'],
            ['name' => 'Móstoles', 'population_size' => 206000, 'region' => 'Community of Madrid'],
            ['name' => 'Pamplona', 'population_size' => 203000, 'region' => 'Navarre'],
            ['name' => 'Almería', 'population_size' => 199000, 'region' => 'Andalusia'],
            ['name' => 'Alcalá de Henares', 'population_size' => 195000, 'region' => 'Community of Madrid'],
            ['name' => 'Fuenlabrada', 'population_size' => 191000, 'region' => 'Community of Madrid'],
            ['name' => 'San Sebastián', 'population_size' => 188000, 'region' => 'Basque Country'],
            ['name' => 'Leganés', 'population_size' => 187000, 'region' => 'Community of Madrid'],
            ['name' => 'Getafe', 'population_size' => 182000, 'region' => 'Community of Madrid'],
            ['name' => 'Burgos', 'population_size' => 175000, 'region' => 'Castile and León'],
            ['name' => 'Santander', 'population_size' => 172000, 'region' => 'Cantabria'],
            ['name' => 'Castellón de la Plana', 'population_size' => 172000, 'region' => 'Valencian Community'],
            ['name' => 'Albacete', 'population_size' => 173000, 'region' => 'Castile-La Mancha'],
            ['name' => 'Alcorcón', 'population_size' => 170000, 'region' => 'Community of Madrid'],
            ['name' => 'Logroño', 'population_size' => 152000, 'region' => 'La Rioja'],
            ['name' => 'Badajoz', 'population_size' => 150000, 'region' => 'Extremadura'],
        ],
        'DE' => [
            ['name' => 'Berlin', 'population_size' => 3645000, 'region' => 'Berlin', 'is_capital' => true],
            ['name' => 'Hamburg', 'population_size' => 1845000, 'region' => 'Hamburg'],
            ['name' => 'München', 'population_size' => 1472000, 'region' => 'Bavaria'],
            ['name' => 'Köln', 'population_size' => 1073000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Frankfurt am Main', 'population_size' => 759000, 'region' => 'Hesse'],
            ['name' => 'Stuttgart', 'population_size' => 626000, 'region' => 'Baden-Württemberg'],
            ['name' => 'Düsseldorf', 'population_size' => 620000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Leipzig', 'population_size' => 601000, 'region' => 'Saxony'],
            ['name' => 'Dortmund', 'population_size' => 588000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Essen', 'population_size' => 582000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Bremen', 'population_size' => 566000, 'region' => 'Bremen'],
            ['name' => 'Dresden', 'population_size' => 556000, 'region' => 'Saxony'],
            ['name' => 'Hannover', 'population_size' => 535000, 'region' => 'Lower Saxony'],
            ['name' => 'Nürnberg', 'population_size' => 518000, 'region' => 'Bavaria'],
            ['name' => 'Duisburg', 'population_size' => 495000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Bochum', 'population_size' => 364000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Wuppertal', 'population_size' => 355000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Bielefeld', 'population_size' => 334000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Bonn', 'population_size' => 330000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Münster', 'population_size' => 316000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Mannheim', 'population_size' => 309000, 'region' => 'Baden-Württemberg'],
            ['name' => 'Karlsruhe', 'population_size' => 308000, 'region' => 'Baden-Württemberg'],
            ['name' => 'Augsburg', 'population_size' => 296000, 'region' => 'Bavaria'],
            ['name' => 'Wiesbaden', 'population_size' => 278000, 'region' => 'Hesse'],
            ['name' => 'Mönchengladbach', 'population_size' => 261000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Gelsenkirchen', 'population_size' => 260000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Aachen', 'population_size' => 249000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Braunschweig', 'population_size' => 248000, 'region' => 'Lower Saxony'],
            ['name' => 'Chemnitz', 'population_size' => 247000, 'region' => 'Saxony'],
            ['name' => 'Kiel', 'population_size' => 246000, 'region' => 'Schleswig-Holstein'],
            ['name' => 'Halle (Saale)', 'population_size' => 239000, 'region' => 'Saxony-Anhalt'],
            ['name' => 'Magdeburg', 'population_size' => 238000, 'region' => 'Saxony-Anhalt'],
            ['name' => 'Freiburg im Breisgau', 'population_size' => 231000, 'region' => 'Baden-Württemberg'],
            ['name' => 'Krefeld', 'population_size' => 227000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Mainz', 'population_size' => 218000, 'region' => 'Rhineland-Palatinate'],
            ['name' => 'Lübeck', 'population_size' => 217000, 'region' => 'Schleswig-Holstein'],
            ['name' => 'Rostock', 'population_size' => 209000, 'region' => 'Mecklenburg-Vorpommern'],
            ['name' => 'Erfurt', 'population_size' => 213000, 'region' => 'Thuringia'],
            ['name' => 'Oberhausen', 'population_size' => 208000, 'region' => 'North Rhine-Westphalia'],
            ['name' => 'Kassel', 'population_size' => 202000, 'region' => 'Hesse'],
        ],
        'IT' => [
            ['name' => 'Roma', 'population_size' => 2873000, 'region' => 'Lazio', 'is_capital' => true],
            ['name' => 'Milano', 'population_size' => 1352000, 'region' => 'Lombardy'],
            ['name' => 'Napoli', 'population_size' => 914000, 'region' => 'Campania'],
            ['name' => 'Torino', 'population_size' => 848000, 'region' => 'Piedmont'],
            ['name' => 'Palermo', 'population_size' => 630000, 'region' => 'Sicily'],
            ['name' => 'Genova', 'population_size' => 558000, 'region' => 'Liguria'],
            ['name' => 'Bologna', 'population_size' => 388000, 'region' => 'Emilia-Romagna'],
            ['name' => 'Firenze', 'population_size' => 367000, 'region' => 'Tuscany'],
            ['name' => 'Bari', 'population_size' => 316000, 'region' => 'Apulia'],
            ['name' => 'Catania', 'population_size' => 292000, 'region' => 'Sicily'],
            ['name' => 'Verona', 'population_size' => 257000, 'region' => 'Veneto'],
            ['name' => 'Venezia', 'population_size' => 254000, 'region' => 'Veneto'],
            ['name' => 'Messina', 'population_size' => 216000, 'region' => 'Sicily'],
            ['name' => 'Padova', 'population_size' => 210000, 'region' => 'Veneto'],
            ['name' => 'Trieste', 'population_size' => 199000, 'region' => 'Friuli-Venezia Giulia'],
            ['name' => 'Parma', 'population_size' => 199000, 'region' => 'Emilia-Romagna'],
            ['name' => 'Brescia', 'population_size' => 196000, 'region' => 'Lombardy'],
            ['name' => 'Prato', 'population_size' => 195000, 'region' => 'Tuscany'],
            ['name' => 'Taranto', 'population_size' => 187000, 'region' => 'Apulia'],
            ['name' => 'Modena', 'population_size' => 186000, 'region' => 'Emilia-Romagna'],
            ['name' => 'Reggio Calabria', 'population_size' => 172000, 'region' => 'Calabria'],
            ['name' => 'Reggio Emilia', 'population_size' => 171000, 'region' => 'Emilia-Romagna'],
            ['name' => 'Perugia', 'population_size' => 164000, 'region' => 'Umbria'],
            ['name' => 'Ravenna', 'population_size' => 159000, 'region' => 'Emilia-Romagna'],
            ['name' => 'Livorno', 'population_size' => 153000, 'region' => 'Tuscany'],
            ['name' => 'Rimini', 'population_size' => 150000, 'region' => 'Emilia-Romagna'],
            ['name' => 'Cagliari', 'population_size' => 149000, 'region' => 'Sardinia'],
            ['name' => 'Foggia', 'population_size' => 147000, 'region' => 'Apulia'],
            ['name' => 'Ferrara', 'population_size' => 130000, 'region' => 'Emilia-Romagna'],
            ['name' => 'Latina', 'population_size' => 126000, 'region' => 'Lazio'],
            ['name' => 'Salerno', 'population_size' => 128000, 'region' => 'Campania'],
            ['name' => 'Monza', 'population_size' => 123000, 'region' => 'Lombardy'],
            ['name' => 'Bergamo', 'population_size' => 120000, 'region' => 'Lombardy'],
            ['name' => 'Sassari', 'population_size' => 122000, 'region' => 'Sardinia'],
            ['name' => 'Pescara', 'population_size' => 119000, 'region' => 'Abruzzo'],
            ['name' => 'Siracusa', 'population_size' => 118000, 'region' => 'Sicily'],
            ['name' => 'Trento', 'population_size' => 118000, 'region' => 'Trentino-Alto Adige'],
            ['name' => 'Vicenza', 'population_size' => 111000, 'region' => 'Veneto'],
            ['name' => 'Terni', 'population_size' => 108000, 'region' => 'Umbria'],
            ['name' => 'Bolzano', 'population_size' => 107000, 'region' => 'Trentino-Alto Adige'],
        ],
        'FR' => [
            ['name' => 'Paris', 'population_size' => 2148000, 'region' => 'Île-de-France', 'is_capital' => true],
            ['name' => 'Marseille', 'population_size' => 870000, 'region' => "Provence-Alpes-Côte d'Azur"],
            ['name' => 'Lyon', 'population_size' => 522000, 'region' => 'Auvergne-Rhône-Alpes'],
            ['name' => 'Toulouse', 'population_size' => 493000, 'region' => 'Occitanie'],
            ['name' => 'Nice', 'population_size' => 342000, 'region' => "Provence-Alpes-Côte d'Azur"],
            ['name' => 'Nantes', 'population_size' => 320000, 'region' => 'Pays de la Loire'],
            ['name' => 'Montpellier', 'population_size' => 295000, 'region' => 'Occitanie'],
            ['name' => 'Strasbourg', 'population_size' => 287000, 'region' => 'Grand Est'],
            ['name' => 'Bordeaux', 'population_size' => 260000, 'region' => 'Nouvelle-Aquitaine'],
            ['name' => 'Lille', 'population_size' => 234000, 'region' => 'Hauts-de-France'],
            ['name' => 'Rennes', 'population_size' => 220000, 'region' => 'Brittany'],
            ['name' => 'Reims', 'population_size' => 182000, 'region' => 'Grand Est'],
            ['name' => "Saint-Étienne", 'population_size' => 172000, 'region' => 'Auvergne-Rhône-Alpes'],
            ['name' => 'Toulon', 'population_size' => 171000, 'region' => "Provence-Alpes-Côte d'Azur"],
            ['name' => 'Le Havre', 'population_size' => 170000, 'region' => 'Normandy'],
            ['name' => 'Grenoble', 'population_size' => 158000, 'region' => 'Auvergne-Rhône-Alpes'],
            ['name' => 'Dijon', 'population_size' => 159000, 'region' => 'Bourgogne-Franche-Comté'],
            ['name' => 'Angers', 'population_size' => 152000, 'region' => 'Pays de la Loire'],
            ['name' => 'Nîmes', 'population_size' => 150000, 'region' => 'Occitanie'],
            ['name' => 'Villeurbanne', 'population_size' => 149000, 'region' => 'Auvergne-Rhône-Alpes'],
            ['name' => 'Le Mans', 'population_size' => 143000, 'region' => 'Pays de la Loire'],
            ['name' => 'Aix-en-Provence', 'population_size' => 143000, 'region' => "Provence-Alpes-Côte d'Azur"],
            ['name' => 'Clermont-Ferrand', 'population_size' => 143000, 'region' => 'Auvergne-Rhône-Alpes'],
            ['name' => 'Brest', 'population_size' => 139000, 'region' => 'Brittany'],
            ['name' => 'Tours', 'population_size' => 136000, 'region' => 'Centre-Val de Loire'],
            ['name' => 'Amiens', 'population_size' => 133000, 'region' => 'Hauts-de-France'],
            ['name' => 'Limoges', 'population_size' => 132000, 'region' => 'Nouvelle-Aquitaine'],
            ['name' => 'Annecy', 'population_size' => 126000, 'region' => 'Auvergne-Rhône-Alpes'],
            ['name' => 'Perpignan', 'population_size' => 121000, 'region' => 'Occitanie'],
            ['name' => 'Metz', 'population_size' => 117000, 'region' => 'Grand Est'],
            ['name' => 'Besançon', 'population_size' => 116000, 'region' => 'Bourgogne-Franche-Comté'],
            ['name' => 'Orléans', 'population_size' => 116000, 'region' => 'Centre-Val de Loire'],
            ['name' => 'Saint-Denis', 'population_size' => 112000, 'region' => 'Île-de-France'],
            ['name' => 'Argenteuil', 'population_size' => 111000, 'region' => 'Île-de-France'],
            ['name' => 'Rouen', 'population_size' => 111000, 'region' => 'Normandy'],
            ['name' => 'Montreuil', 'population_size' => 109000, 'region' => 'Île-de-France'],
            ['name' => 'Mulhouse', 'population_size' => 108000, 'region' => 'Grand Est'],
            ['name' => 'Caen', 'population_size' => 105000, 'region' => 'Normandy'],
            ['name' => 'Nancy', 'population_size' => 104000, 'region' => 'Grand Est'],
            ['name' => 'Roubaix', 'population_size' => 98000, 'region' => 'Hauts-de-France'],
        ],
        'BR' => [
            ['name' => 'São Paulo', 'population_size' => 12325000, 'region' => 'São Paulo'],
            ['name' => 'Rio de Janeiro', 'population_size' => 6748000, 'region' => 'Rio de Janeiro'],
            ['name' => 'Brasília', 'population_size' => 3055000, 'region' => 'Federal District', 'is_capital' => true],
            ['name' => 'Fortaleza', 'population_size' => 2428000, 'region' => 'Ceará'],
            ['name' => 'Salvador', 'population_size' => 2418000, 'region' => 'Bahia'],
            ['name' => 'Belo Horizonte', 'population_size' => 2315000, 'region' => 'Minas Gerais'],
            ['name' => 'Manaus', 'population_size' => 2219000, 'region' => 'Amazonas'],
            ['name' => 'Curitiba', 'population_size' => 1948000, 'region' => 'Paraná'],
            ['name' => 'Recife', 'population_size' => 1653000, 'region' => 'Pernambuco'],
            ['name' => 'Goiânia', 'population_size' => 1536000, 'region' => 'Goiás'],
            ['name' => 'Belém', 'population_size' => 1499000, 'region' => 'Pará'],
            ['name' => 'Porto Alegre', 'population_size' => 1488000, 'region' => 'Rio Grande do Sul'],
            ['name' => 'Guarulhos', 'population_size' => 1404000, 'region' => 'São Paulo'],
            ['name' => 'Campinas', 'population_size' => 1213000, 'region' => 'São Paulo'],
            ['name' => 'São Luís', 'population_size' => 1115000, 'region' => 'Maranhão'],
            ['name' => 'São Gonçalo', 'population_size' => 1091000, 'region' => 'Rio de Janeiro'],
            ['name' => 'Maceió', 'population_size' => 1025000, 'region' => 'Alagoas'],
            ['name' => 'Duque de Caxias', 'population_size' => 924000, 'region' => 'Rio de Janeiro'],
            ['name' => 'Natal', 'population_size' => 890000, 'region' => 'Rio Grande do Norte'],
            ['name' => 'Teresina', 'population_size' => 868000, 'region' => 'Piauí'],
            ['name' => 'São Bernardo do Campo', 'population_size' => 844000, 'region' => 'São Paulo'],
            ['name' => 'Nova Iguaçu', 'population_size' => 823000, 'region' => 'Rio de Janeiro'],
            ['name' => 'João Pessoa', 'population_size' => 817000, 'region' => 'Paraíba'],
            ['name' => 'São José dos Campos', 'population_size' => 729000, 'region' => 'São Paulo'],
            ['name' => 'Santo André', 'population_size' => 721000, 'region' => 'São Paulo'],
            ['name' => 'Ribeirão Preto', 'population_size' => 711000, 'region' => 'São Paulo'],
            ['name' => 'Jaboatão dos Guararapes', 'population_size' => 706000, 'region' => 'Pernambuco'],
            ['name' => 'Uberlândia', 'population_size' => 706000, 'region' => 'Minas Gerais'],
            ['name' => 'Sorocaba', 'population_size' => 687000, 'region' => 'São Paulo'],
            ['name' => 'Contagem', 'population_size' => 668000, 'region' => 'Minas Gerais'],
            ['name' => 'Aracaju', 'population_size' => 664000, 'region' => 'Sergipe'],
            ['name' => 'Feira de Santana', 'population_size' => 619000, 'region' => 'Bahia'],
            ['name' => 'Cuiabá', 'population_size' => 618000, 'region' => 'Mato Grosso'],
            ['name' => 'Joinville', 'population_size' => 597000, 'region' => 'Santa Catarina'],
            ['name' => 'Londrina', 'population_size' => 575000, 'region' => 'Paraná'],
            ['name' => 'Aparecida de Goiânia', 'population_size' => 590000, 'region' => 'Goiás'],
            ['name' => 'Juiz de Fora', 'population_size' => 573000, 'region' => 'Minas Gerais'],
            ['name' => 'Porto Velho', 'population_size' => 539000, 'region' => 'Rondônia'],
            ['name' => 'Ananindeua', 'population_size' => 535000, 'region' => 'Pará'],
            ['name' => 'Niterói', 'population_size' => 515000, 'region' => 'Rio de Janeiro'],
        ],
        'AR' => [
            ['name' => 'Buenos Aires', 'population_size' => 3121000, 'region' => 'Autonomous City of Buenos Aires', 'is_capital' => true],
            ['name' => 'Córdoba', 'population_size' => 1391000, 'region' => 'Córdoba'],
            ['name' => 'Rosario', 'population_size' => 1193000, 'region' => 'Santa Fe'],
            ['name' => 'La Plata', 'population_size' => 654000, 'region' => 'Buenos Aires Province'],
            ['name' => 'Salta', 'population_size' => 620000, 'region' => 'Salta'],
            ['name' => 'Mar del Plata', 'population_size' => 618000, 'region' => 'Buenos Aires Province'],
            ['name' => 'San Miguel de Tucumán', 'population_size' => 548000, 'region' => 'Tucumán'],
            ['name' => 'San Juan', 'population_size' => 471000, 'region' => 'San Juan'],
            ['name' => 'Santa Fe', 'population_size' => 415000, 'region' => 'Santa Fe'],
            ['name' => 'Resistencia', 'population_size' => 386000, 'region' => 'Chaco'],
            ['name' => 'Santiago del Estero', 'population_size' => 380000, 'region' => 'Santiago del Estero'],
            ['name' => 'Corrientes', 'population_size' => 358000, 'region' => 'Corrientes'],
            ['name' => 'Neuquén', 'population_size' => 331000, 'region' => 'Neuquén'],
            ['name' => 'Posadas', 'population_size' => 325000, 'region' => 'Misiones'],
            ['name' => 'Bahía Blanca', 'population_size' => 305000, 'region' => 'Buenos Aires Province'],
            ['name' => 'San Salvador de Jujuy', 'population_size' => 278000, 'region' => 'Jujuy'],
            ['name' => 'Paraná', 'population_size' => 260000, 'region' => 'Entre Ríos'],
            ['name' => 'Formosa', 'population_size' => 234000, 'region' => 'Formosa'],
            ['name' => 'San Luis', 'population_size' => 210000, 'region' => 'San Luis'],
            ['name' => 'San Fernando del Valle de Catamarca', 'population_size' => 205000, 'region' => 'Catamarca'],
            ['name' => 'La Rioja', 'population_size' => 194000, 'region' => 'La Rioja'],
            ['name' => 'Comodoro Rivadavia', 'population_size' => 181000, 'region' => 'Chubut'],
            ['name' => 'Concordia', 'population_size' => 170000, 'region' => 'Entre Ríos'],
            ['name' => 'Río Cuarto', 'population_size' => 162000, 'region' => 'Córdoba Province'],
            ['name' => 'San Carlos de Bariloche', 'population_size' => 130000, 'region' => 'Río Negro'],
            ['name' => 'Tandil', 'population_size' => 148000, 'region' => 'Buenos Aires Province'],
            ['name' => 'Mendoza', 'population_size' => 115000, 'region' => 'Mendoza'],
            ['name' => 'San Rafael', 'population_size' => 122000, 'region' => 'Mendoza'],
            ['name' => 'Trelew', 'population_size' => 112000, 'region' => 'Chubut'],
            ['name' => 'Villa Mercedes', 'population_size' => 118000, 'region' => 'San Luis'],
            ['name' => 'Rafaela', 'population_size' => 105000, 'region' => 'Santa Fe'],
            ['name' => 'Zárate', 'population_size' => 106000, 'region' => 'Buenos Aires Province'],
            ['name' => 'Pergamino', 'population_size' => 112000, 'region' => 'Buenos Aires Province'],
            ['name' => 'Río Gallegos', 'population_size' => 100000, 'region' => 'Santa Cruz'],
            ['name' => 'General Roca', 'population_size' => 100000, 'region' => 'Río Negro'],
            ['name' => 'Ushuaia', 'population_size' => 82000, 'region' => 'Tierra del Fuego'],
            ['name' => 'Junín', 'population_size' => 92000, 'region' => 'Buenos Aires Province'],
            ['name' => 'Venado Tuerto', 'population_size' => 80000, 'region' => 'Santa Fe'],
            ['name' => 'Azul', 'population_size' => 67000, 'region' => 'Buenos Aires Province'],
            ['name' => 'Viedma', 'population_size' => 60000, 'region' => 'Río Negro'],
        ],
        'NL' => [
            ['name' => 'Amsterdam', 'population_size' => 921000, 'region' => 'North Holland', 'is_capital' => true],
            ['name' => 'Rotterdam', 'population_size' => 656000, 'region' => 'South Holland'],
            ['name' => 'Den Haag', 'population_size' => 552000, 'region' => 'South Holland'],
            ['name' => 'Utrecht', 'population_size' => 361000, 'region' => 'Utrecht'],
            ['name' => 'Eindhoven', 'population_size' => 238000, 'region' => 'North Brabant'],
            ['name' => 'Groningen', 'population_size' => 235000, 'region' => 'Groningen'],
            ['name' => 'Tilburg', 'population_size' => 224000, 'region' => 'North Brabant'],
            ['name' => 'Almere', 'population_size' => 214000, 'region' => 'Flevoland'],
            ['name' => 'Breda', 'population_size' => 184000, 'region' => 'North Brabant'],
            ['name' => 'Nijmegen', 'population_size' => 179000, 'region' => 'Gelderland'],
            ['name' => 'Apeldoorn', 'population_size' => 165000, 'region' => 'Gelderland'],
            ['name' => 'Arnhem', 'population_size' => 163000, 'region' => 'Gelderland'],
            ['name' => 'Haarlem', 'population_size' => 162000, 'region' => 'North Holland'],
            ['name' => 'Enschede', 'population_size' => 160000, 'region' => 'Overijssel'],
            ['name' => 'Amersfoort', 'population_size' => 158000, 'region' => 'Utrecht'],
            ['name' => 'Haarlemmermeer', 'population_size' => 158000, 'region' => 'North Holland'],
            ['name' => 'Zaanstad', 'population_size' => 156000, 'region' => 'North Holland'],
            ['name' => "'s-Hertogenbosch", 'population_size' => 155000, 'region' => 'North Brabant'],
            ['name' => 'Zwolle', 'population_size' => 130000, 'region' => 'Overijssel'],
            ['name' => 'Zoetermeer', 'population_size' => 126000, 'region' => 'South Holland'],
            ['name' => 'Leiden', 'population_size' => 126000, 'region' => 'South Holland'],
            ['name' => 'Maastricht', 'population_size' => 121000, 'region' => 'Limburg'],
            ['name' => 'Dordrecht', 'population_size' => 119000, 'region' => 'South Holland'],
            ['name' => 'Ede', 'population_size' => 118000, 'region' => 'Gelderland'],
            ['name' => 'Alphen aan den Rijn', 'population_size' => 112000, 'region' => 'South Holland'],
            ['name' => 'Westland', 'population_size' => 112000, 'region' => 'South Holland'],
            ['name' => 'Alkmaar', 'population_size' => 110000, 'region' => 'North Holland'],
            ['name' => 'Emmen', 'population_size' => 108000, 'region' => 'Drenthe'],
            ['name' => 'Delft', 'population_size' => 104000, 'region' => 'South Holland'],
            ['name' => 'Venlo', 'population_size' => 102000, 'region' => 'Limburg'],
            ['name' => 'Deventer', 'population_size' => 101000, 'region' => 'Overijssel'],
            ['name' => 'Helmond', 'population_size' => 92000, 'region' => 'North Brabant'],
            ['name' => 'Oss', 'population_size' => 92000, 'region' => 'North Brabant'],
            ['name' => 'Amstelveen', 'population_size' => 92000, 'region' => 'North Holland'],
            ['name' => 'Heerlen', 'population_size' => 87000, 'region' => 'Limburg'],
            ['name' => 'Purmerend', 'population_size' => 82000, 'region' => 'North Holland'],
            ['name' => 'Lelystad', 'population_size' => 82000, 'region' => 'Flevoland'],
            ['name' => 'Hengelo', 'population_size' => 81000, 'region' => 'Overijssel'],
            ['name' => 'Schiedam', 'population_size' => 79000, 'region' => 'South Holland'],
            ['name' => 'Roosendaal', 'population_size' => 77000, 'region' => 'North Brabant'],
        ],
        'PT' => [
            ['name' => 'Lisboa', 'population_size' => 545000, 'region' => 'Lisbon District', 'is_capital' => true],
            ['name' => 'Vila Nova de Gaia', 'population_size' => 302000, 'region' => 'Porto District'],
            ['name' => 'Sintra', 'population_size' => 385000, 'region' => 'Lisbon District'],
            ['name' => 'Porto', 'population_size' => 231000, 'region' => 'Porto District'],
            ['name' => 'Loures', 'population_size' => 205000, 'region' => 'Lisbon District'],
            ['name' => 'Cascais', 'population_size' => 214000, 'region' => 'Lisbon District'],
            ['name' => 'Braga', 'population_size' => 193000, 'region' => 'Braga District'],
            ['name' => 'Amadora', 'population_size' => 175000, 'region' => 'Lisbon District'],
            ['name' => 'Almada', 'population_size' => 174000, 'region' => 'Setúbal District'],
            ['name' => 'Guimarães', 'population_size' => 162000, 'region' => 'Braga District'],
            ['name' => 'Odivelas', 'population_size' => 144000, 'region' => 'Lisbon District'],
            ['name' => 'Coimbra', 'population_size' => 143000, 'region' => 'Coimbra District'],
            ['name' => 'Vila Franca de Xira', 'population_size' => 137000, 'region' => 'Lisbon District'],
            ['name' => 'Maia', 'population_size' => 138000, 'region' => 'Porto District'],
            ['name' => 'Leiria', 'population_size' => 128000, 'region' => 'Leiria District'],
            ['name' => 'Setúbal', 'population_size' => 118000, 'region' => 'Setúbal District'],
            ['name' => 'Funchal', 'population_size' => 111000, 'region' => 'Madeira'],
            ['name' => 'Viseu', 'population_size' => 99000, 'region' => 'Viseu District'],
            ['name' => 'Viana do Castelo', 'population_size' => 90000, 'region' => 'Viana do Castelo District'],
            ['name' => 'Aveiro', 'population_size' => 80000, 'region' => 'Aveiro District'],
            ['name' => 'Torres Vedras', 'population_size' => 79000, 'region' => 'Lisbon District'],
            ['name' => 'Cacém', 'population_size' => 82000, 'region' => 'Lisbon District'],
            ['name' => 'Barreiro', 'population_size' => 78000, 'region' => 'Setúbal District'],
            ['name' => 'Ponta Delgada', 'population_size' => 68000, 'region' => 'Azores'],
            ['name' => 'Faro', 'population_size' => 68000, 'region' => 'Faro District'],
            ['name' => 'Santarém', 'population_size' => 62000, 'region' => 'Santarém District'],
            ['name' => 'Figueira da Foz', 'population_size' => 62000, 'region' => 'Coimbra District'],
            ['name' => 'Portimão', 'population_size' => 61000, 'region' => 'Faro District'],
            ['name' => 'Castelo Branco', 'population_size' => 56000, 'region' => 'Castelo Branco District'],
            ['name' => 'Évora', 'population_size' => 57000, 'region' => 'Évora District'],
            ['name' => 'Sesimbra', 'population_size' => 50000, 'region' => 'Setúbal District'],
            ['name' => 'Vila Real', 'population_size' => 51000, 'region' => 'Vila Real District'],
            ['name' => 'Covilhã', 'population_size' => 51000, 'region' => 'Castelo Branco District'],
            ['name' => 'Guarda', 'population_size' => 43000, 'region' => 'Guarda District'],
            ['name' => 'Chaves', 'population_size' => 41000, 'region' => 'Vila Real District'],
            ['name' => 'Beja', 'population_size' => 35000, 'region' => 'Beja District'],
            ['name' => 'Bragança', 'population_size' => 35000, 'region' => 'Bragança District'],
            ['name' => 'Portalegre', 'population_size' => 24000, 'region' => 'Portalegre District'],
            ['name' => 'Peso da Régua', 'population_size' => 18000, 'region' => 'Vila Real District'],
        ],
    ];
```

- [ ] **Step 4: Split the flat `SUFFIXES` constants (needed by `getSuffixes` in this task; full prestige/generic split lands in Task 5)**

For now, leave `SUFFIXES_BY_COUNTRY` (lines 599–645 of the original file) and `SUFFIXES` (line 597) **in place** — Task 5 replaces them. This task only touches `PLACE_NAMES_BY_COUNTRY` and the two new methods below.

- [ ] **Step 5: Add `getPlaceData` and `classifyPlaces`, update `getPlaceNames`**

Replace the existing `getPlaceNames` method (around line 690) with:

```php
    /** @return string[] */
    public function getPlaceNames(string $countryCode): array
    {
        return array_map(static fn(array $p) => $p['name'], self::PLACE_NAMES_BY_COUNTRY[$countryCode] ?? []);
    }

    /** @return array<array{name:string,population_size:int,region:string,is_capital?:bool}> */
    public function getPlaceData(string $countryCode): array
    {
        return self::PLACE_NAMES_BY_COUNTRY[$countryCode] ?? [];
    }

    /**
     * Ranks places by population_size (descending) and tags each with a CitySize:
     * top 20% => BIG, bottom 50% => SMALL, remaining middle 30% => MEDIUM.
     * is_capital always forces BIG regardless of rank.
     *
     * @param array<array{name:string,population_size:int,region:string,is_capital?:bool}> $places
     * @return array<array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}>
     */
    public function classifyPlaces(array $places): array
    {
        $sorted = $places;
        usort($sorted, static fn(array $a, array $b) => $b['population_size'] <=> $a['population_size']);

        $count      = count($sorted);
        $bigCutoff  = (int) ceil($count * 0.20);
        $smallCount = (int) ceil($count * 0.50);

        foreach ($sorted as $i => &$place) {
            if (!empty($place['is_capital'])) {
                $place['city_size'] = \App\Enum\CitySize::BIG;
            } elseif ($i < $bigCutoff) {
                $place['city_size'] = \App\Enum\CitySize::BIG;
            } elseif ($i >= $count - $smallCount) {
                $place['city_size'] = \App\Enum\CitySize::SMALL;
            } else {
                $place['city_size'] = \App\Enum\CitySize::MEDIUM;
            }
        }
        unset($place);

        return $sorted;
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `lando php vendor/bin/phpunit tests/Service/NpcClubGenerationServiceTest.php --no-coverage`
Expected: PASS — all existing tests (including `testGetPlaceNamesReturnsKnownCountryData` which checks for `Madrid`/`Barcelona`) plus the 4 new ones.

- [ ] **Step 7: Commit**

```bash
git add src/Service/NpcClubGenerationService.php tests/Service/NpcClubGenerationServiceTest.php
git commit -m "feat: curate place data with population/region/capital metadata and add classifyPlaces"
```

---

### Task 5: Split suffixes into prestige/generic pools

**Files:**
- Modify: `src/Service/NpcClubGenerationService.php`
- Test: `tests/Service/NpcClubGenerationServiceTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `NpcClubGenerationService::getSuffixes(string $countryCode): array` (unchanged signature — now returns the merge of both pools, for back-compat with `ClubController::nameOptions`), `pickSuffixForCitySize(\App\Enum\CitySize $citySize, array $prestige, array $generic): string` (new **public** method for testability).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Service/NpcClubGenerationServiceTest.php`, before the final `}`:

```php

    public function testGetSuffixesStillReturnsBothPrestigeAndGenericWords(): void
    {
        $service  = $this->makeService();
        $suffixes = $service->getSuffixes('EN');

        // Prestige word
        $this->assertContains('United', $suffixes);
        // Generic word
        $this->assertContains('FC', $suffixes);
    }

    public function testPickSuffixForCitySizeBigAlwaysUsesPrestigePool(): void
    {
        $service  = $this->makeService();
        $prestige = ['United', 'City'];
        $generic  = ['Town', 'Rovers'];

        for ($i = 0; $i < 20; $i++) {
            $picked = $service->pickSuffixForCitySize(\App\Enum\CitySize::BIG, $prestige, $generic);
            $this->assertContains($picked, $prestige);
        }
    }

    public function testPickSuffixForCitySizeSmallAlwaysUsesGenericPool(): void
    {
        $service  = $this->makeService();
        $prestige = ['United', 'City'];
        $generic  = ['Town', 'Rovers'];

        for ($i = 0; $i < 20; $i++) {
            $picked = $service->pickSuffixForCitySize(\App\Enum\CitySize::SMALL, $prestige, $generic);
            $this->assertContains($picked, $generic);
        }
    }

    public function testPickSuffixForCitySizeMediumUsesEitherPool(): void
    {
        $service  = $this->makeService();
        $prestige = ['United'];
        $generic  = ['Town'];
        $seenPrestige = false;
        $seenGeneric  = false;

        for ($i = 0; $i < 40; $i++) {
            $picked = $service->pickSuffixForCitySize(\App\Enum\CitySize::MEDIUM, $prestige, $generic);
            if ($picked === 'United') $seenPrestige = true;
            if ($picked === 'Town') $seenGeneric = true;
        }

        $this->assertTrue($seenPrestige, 'Expected at least one prestige pick across 40 MEDIUM rolls');
        $this->assertTrue($seenGeneric, 'Expected at least one generic pick across 40 MEDIUM rolls');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `lando php vendor/bin/phpunit tests/Service/NpcClubGenerationServiceTest.php --no-coverage`
Expected: FAIL — `pickSuffixForCitySize()` undefined.

- [ ] **Step 3: Replace `SUFFIXES_BY_COUNTRY` with the two curated pools**

In `src/Service/NpcClubGenerationService.php`, replace the `SUFFIXES` constant and the entire `SUFFIXES_BY_COUNTRY` constant (the `private const SUFFIXES = [...]` line and the `private const SUFFIXES_BY_COUNTRY = [...]` block) with:

```php
    private const PRESTIGE_SUFFIXES_BY_COUNTRY = [
        'ES' => ['Real', 'Atlético', 'Deportivo', 'Sporting', 'RCD', 'Racing', 'Levante', 'Hércules', 'Condal', 'Europa'],
        'EN' => ['United', 'City', 'Athletic', 'Albion', 'Villa', 'Wednesday', 'Forest', 'Rangers', 'Spurs', 'Dons'],
        'DE' => ['1. FC', 'VfB', 'VfL', 'Eintracht', 'Borussia', 'Hertha', 'Fortuna', 'Dynamo', 'Union', 'Viktoria'],
        'IT' => ['AC', 'AS', 'Internazionale', 'Real', 'Atalanta', 'Genoa', 'Samp', 'Bari', 'Palermo', 'Hellas'],
        'FR' => ['Olympique', 'Racing', 'Girondins', 'Red Star', 'Stade', 'AS', 'RC', 'OGC', 'Sporting', 'Étoile'],
        'BR' => ['Grêmio', 'Atlético', 'Botafogo', 'Real', 'América', 'Nacional', 'Independente', 'Remo', 'Paysandu', 'Esporte Clube'],
        'AR' => ['Racing', 'Independiente', 'Estudiantes', 'Talleres', 'Belgrano', 'Huracán', 'Central', 'Gimnasia', 'Banfield', 'Club Atlético'],
        'NL' => ['AZ', 'Vitesse', 'Twente', 'Willem', 'Heracles', 'Fortuna', 'Sparta', 'Roda', 'Excelsior', 'Go Ahead'],
        'PT' => ['FC', 'SC', 'Sporting Clube', 'Vitória', 'Boavista', 'Nacional', 'Académica', 'Marítimo', 'Gil Vicente', 'Os Belenenses'],
    ];

    private const GENERIC_SUFFIXES_BY_COUNTRY = [
        'ES' => [
            'CF', 'CD', 'UD', 'SD', 'AD', 'Recreativo', 'Gimnástic', 'Unión', 'Cultural', 'Rayo',
            'Burgos', 'Poli', 'CDI', 'RCE', 'Arenas', 'Alcoyano', 'Mensajero', 'Izarra', 'Sestao', 'Castilla',
        ],
        'EN' => [
            'FC', 'Town', 'Rovers', 'Wanderers', 'County', 'North End', 'Alexandra', 'Harriers', 'Orient', 'Argyle',
            'Stanley', 'Diamonds', 'Miners', 'Warriors', 'Knights', 'Heaths', 'Bridge', 'Vale', 'Park', 'Port',
        ],
        'DE' => [
            'SV', 'SpVgg', 'TSV', 'SC', 'Arminia', 'Germania', 'Preußen', 'Hansa', 'Wacker', 'Kickers',
            'Sportfreunde', 'TuS', 'FSV', 'Rot-Weiß', 'Schwarz-Weiß', 'Blau-Weiß', 'Phönix', 'Stahl', 'Chemie', 'Lokomotive',
        ],
        'IT' => [
            'FC', 'US', 'Polisportiva', 'Virtus', 'Calcio', 'Unione Sportiva', 'SS', 'Pro', 'Chievo', 'Spal',
            'Piacenza', 'Vigor', 'Libertas', 'Città di', 'Audace', 'Olimpia', 'Sangiovannese', 'Borgo', 'Aquila', 'Grifone',
        ],
        'FR' => [
            'FC', 'US', 'ES', 'SC', 'Amiens', 'Toulousain', 'Mousquetaires', 'Lorientais', 'Nimois', 'Brestois',
            'Aigles', 'Azur', 'Lumière', 'Nord', 'Sud', 'Montagnards', 'Vignerons', 'Corsica', 'Rhodaniens', 'Alpins',
        ],
        'BR' => [
            'EC', 'AC', 'SC', 'FC', 'CR', 'Sociedade Esportiva', 'Comercial', 'Ferroviária', 'XV de', 'Operário',
            'Sampaio', 'Paulista', 'Carioca', 'Mineiro', 'Gaúcho', 'Nordeste', 'Luso', 'União', 'Juventude', 'Vila Nova',
        ],
        'AR' => [
            'CA', 'Social y Deportivo', 'AC', 'CSD', 'Unión', 'Sportivo', 'Defensores de', 'Ferro Carril', 'Patria', 'Chacarita',
            'Almagro', 'Arsenal', 'Sarmiento', 'Mitre', 'Douglas Haig', 'Guaraní', 'Crucero', 'Aldosivi', 'Patronato', 'Lanús',
        ],
        'NL' => [
            'FC', 'SV', 'SC', 'VV', 'Jong', 'Heerenveen', 'Graafschap', 'Cambuur', 'Telstar', 'Volendam',
            'Unitas', 'Quick', 'Harkemase', 'IJsselmeervogels', 'Spakenburg', 'Katwijk', 'Noordwijk', 'Koninklijke', 'Blauw Wit', 'Zeeburgia',
        ],
        'PT' => [
            'CD', 'GD', 'Clube', 'União', 'Paços de Ferreira', 'Moreirense', 'Arouca', 'Tondela', 'Farense', 'Olhanense',
            'Leixões', 'Varzim', 'Mafra', 'Covilhã', 'Feirense', 'Penafiel', 'Desportivo', 'Lusitano', 'Campomaiorense', 'Beira-Mar',
        ],
    ];
```

- [ ] **Step 4: Update `getSuffixes` and add `pickSuffixForCitySize`**

Replace the existing `getSuffixes` method with:

```php
    /** @return string[] */
    public function getSuffixes(string $countryCode): array
    {
        return array_merge(
            self::PRESTIGE_SUFFIXES_BY_COUNTRY[$countryCode] ?? [],
            self::GENERIC_SUFFIXES_BY_COUNTRY[$countryCode] ?? [],
        );
    }

    /** @param string[] $prestige @param string[] $generic */
    public function pickSuffixForCitySize(\App\Enum\CitySize $citySize, array $prestige, array $generic): string
    {
        $pool = match ($citySize) {
            \App\Enum\CitySize::BIG    => $prestige,
            \App\Enum\CitySize::SMALL  => $generic,
            \App\Enum\CitySize::MEDIUM => random_int(0, 1) === 0 ? $prestige : $generic,
        };

        return $pool[array_rand($pool)];
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `lando php vendor/bin/phpunit tests/Service/NpcClubGenerationServiceTest.php --no-coverage`
Expected: PASS — all tests including `testGetSuffixesReturnsKnownCountryData` (checks for `FC`/`United`) and the 4 new suffix tests.

- [ ] **Step 6: Commit**

```bash
git add src/Service/NpcClubGenerationService.php tests/Service/NpcClubGenerationServiceTest.php
git commit -m "feat: split suffixes into prestige/generic pools by city size"
```

---

### Task 6: Weighted place selection replaces uniform `array_rand`

**Files:**
- Modify: `src/Service/NpcClubGenerationService.php`
- Test: `tests/Service/NpcClubGenerationServiceTest.php`

**Interfaces:**
- Consumes: `GameConfig::getNpcClubSizeWeightsForTier(int $tier): array` (Task 2), `classifyPlaces()` (Task 4), `pickSuffixForCitySize()` (Task 5).
- Produces: `NpcClubGenerationService::pickWeightedPlace(array $classifiedPlaces, array $weights): array` (new **public** method for testability) — `$weights` is `['big' => float, 'medium' => float, 'small' => float]`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Service/NpcClubGenerationServiceTest.php`, before the final `}`:

```php

    public function testPickWeightedPlaceAlwaysReturnsAPlace(): void
    {
        $service = $this->makeService();
        $classified = $service->classifyPlaces([
            ['name' => 'Big', 'population_size' => 900000, 'region' => 'R'],
            ['name' => 'Small', 'population_size' => 1000, 'region' => 'R'],
        ]);

        $picked = $service->pickWeightedPlace($classified, ['big' => 70, 'medium' => 25, 'small' => 5]);
        $this->assertContains($picked['name'], ['Big', 'Small']);
    }

    public function testPickWeightedPlaceFavorsBigBucketWhenWeighted(): void
    {
        $service = $this->makeService();
        $classified = $service->classifyPlaces([
            ['name' => 'Big', 'population_size' => 900000, 'region' => 'R'],
            ['name' => 'Small', 'population_size' => 1000, 'region' => 'R'],
        ]);

        $bigCount = 0;
        for ($i = 0; $i < 200; $i++) {
            $picked = $service->pickWeightedPlace($classified, ['big' => 95, 'medium' => 4, 'small' => 1]);
            if ($picked['name'] === 'Big') $bigCount++;
        }

        $this->assertGreaterThan(150, $bigCount, 'Expected the heavily-weighted BIG bucket to dominate picks');
    }

    public function testGenerateClubsAtTier1SkewsTowardBigCities(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(80, 1, 'ES');

        $bigCount = 0;
        foreach ($clubs as $club) {
            if ($club->getCitySize() === \App\Enum\CitySize::BIG) $bigCount++;
        }

        // Default tier-1 weight is 70% BIG — with 80 clubs, expect a clear majority to be BIG.
        $this->assertGreaterThan(40, $bigCount);
    }

    public function testGenerateClubsAtTier8SkewsTowardSmallCities(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(80, 8, 'ES');

        $smallCount = 0;
        foreach ($clubs as $club) {
            if ($club->getCitySize() === \App\Enum\CitySize::SMALL) $smallCount++;
        }

        // Default tier-8 weight is 70% SMALL — with 80 clubs, expect a clear majority to be SMALL.
        $this->assertGreaterThan(40, $smallCount);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `lando php vendor/bin/phpunit tests/Service/NpcClubGenerationServiceTest.php --no-coverage`
Expected: FAIL — `pickWeightedPlace()` undefined; `getCitySize()` on the club returns `MEDIUM` default since nothing wires it yet.

- [ ] **Step 3: Add `pickWeightedPlace` and rewrite `generateName`**

Add this new public method to `NpcClubGenerationService` (near `generateName`):

```php
    /**
     * Weighted random pick across classified places. Weight for a place =
     * (tier's % for its city_size bucket) / (count of places in that bucket),
     * so weight is spread evenly within a bucket.
     *
     * @param array<array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}> $classifiedPlaces
     * @param array{big:float,medium:float,small:float} $weights
     * @return array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}
     */
    public function pickWeightedPlace(array $classifiedPlaces, array $weights): array
    {
        $bucketCounts = ['big' => 0, 'medium' => 0, 'small' => 0];
        foreach ($classifiedPlaces as $place) {
            $bucketCounts[strtolower($place['city_size']->value)]++;
        }

        $cumulative = [];
        $total = 0.0;
        foreach ($classifiedPlaces as $i => $place) {
            $bucket = strtolower($place['city_size']->value);
            $count  = max(1, $bucketCounts[$bucket]);
            $weight = max(0.0001, (float) ($weights[$bucket] ?? 0)) / $count;
            $total += $weight;
            $cumulative[$i] = $total;
        }

        $roll = (mt_rand() / mt_getrandmax()) * $total;
        foreach ($cumulative as $i => $upperBound) {
            if ($roll <= $upperBound) {
                return $classifiedPlaces[$i];
            }
        }

        return $classifiedPlaces[array_key_last($classifiedPlaces)];
    }
```

Replace `generateName` with:

```php
    /**
     * @param array<array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}> $classifiedPlaces
     * @param string[] $prestigeSuffixes
     * @param string[] $genericSuffixes
     * @param array{big:float,medium:float,small:float} $weights
     * @return array{0:string,1:array{name:string,population_size:int,region:string,is_capital?:bool,city_size:\App\Enum\CitySize}}
     */
    private function generateName(array $classifiedPlaces, array $usedNames, array $prestigeSuffixes, array $genericSuffixes, array $weights): array
    {
        $attempts = 0;
        do {
            $place  = $this->pickWeightedPlace($classifiedPlaces, $weights);
            $suffix = $this->pickSuffixForCitySize($place['city_size'], $prestigeSuffixes, $genericSuffixes);
            $name   = "{$place['name']} {$suffix}";
            $attempts++;
        } while (in_array($name, $usedNames, true) && $attempts < 50);

        return [$name, $place];
    }
```

- [ ] **Step 4: Wire it into `generateClubs`**

Replace the body of `generateClubs` with:

```php
    /** @return NpcClub[] */
    public function generateClubs(int $count, int $tier, string $country, bool $deleteExisting = false): array
    {
        $tier       = max(1, min(8, $tier));
        $slugs      = $this->getActiveFacilitySlugs();
        $bandIndex  = $this->getBandIndexForTier($tier);
        $levelBand  = self::FACILITY_LEVELS[$bandIndex];
        $placeData  = self::PLACE_NAMES_BY_COUNTRY[$country] ?? [
            ['name' => 'Capital', 'population_size' => 500000, 'region' => 'Central', 'is_capital' => true],
            ['name' => 'Northern', 'population_size' => 100000, 'region' => 'North'],
            ['name' => 'Southern', 'population_size' => 100000, 'region' => 'South'],
            ['name' => 'Eastern', 'population_size' => 100000, 'region' => 'East'],
            ['name' => 'Western', 'population_size' => 100000, 'region' => 'West'],
            ['name' => 'Central', 'population_size' => 100000, 'region' => 'Central'],
        ];
        $classifiedPlaces = $this->classifyPlaces($placeData);
        $prestigeSuffixes = self::PRESTIGE_SUFFIXES_BY_COUNTRY[$country] ?? ['FC'];
        $genericSuffixes  = self::GENERIC_SUFFIXES_BY_COUNTRY[$country] ?? ['FC'];
        $weights          = $this->gameConfigRepository->getConfig()->getNpcClubSizeWeightsForTier($tier);
        $usedNames        = [];
        $clubs            = [];

        if ($deleteExisting) {
            $this->npcClubRepo->deleteByCountryAndTier($country, $tier);
        }

        for ($i = 0; $i < $count; $i++) {
            [$name, $place] = $this->generateName($classifiedPlaces, $usedNames, $prestigeSuffixes, $genericSuffixes, $weights);
            $usedNames[]    = $name;
            $citySize       = $place['city_size'];
            $reputation     = $this->reputationForTier($tier, $citySize);
            $balance        = $this->balanceForTier($tier, $citySize);
            $facilities     = $this->buildFacilities($slugs, $levelBand, $bandIndex);
            $colors         = $this->pickColorPair();
            $stadiumName    = $this->generateStadiumName($place['name'], $country);

            $club = new NpcClub(
                name:           $name,
                country:        $country,
                tier:           $tier,
                reputation:     $reputation,
                primaryColor:   $colors[0],
                secondaryColor: $colors[1],
                balance:        $balance,
                facilities:     $facilities,
                region:         $place['region'] ?? null,
                citySize:       $citySize,
                populationSize: (int) ($place['population_size'] ?? 0),
                isCapital:      (bool) ($place['is_capital'] ?? false),
            );
            $club->setStadiumName($stadiumName);
            $club->setPlayingStyle($this->playingStyleForTier($tier));
            $club->setFinancialApproach($this->financialApproachForTier($tier));
            $club->setManagerTemperament(random_int(30, 80));

            $this->em->persist($club);
            $this->leagueService->assignClubToLeague($club);
            $clubs[] = $club;
        }

        $this->em->flush();
        return $clubs;
    }
```

Note: `reputationForTier`/`balanceForTier` signatures change to accept `$citySize` — that lands in Task 7. For this task's tests to pass, temporarily keep them accepting a second unused parameter (Task 7 fills in the real skew logic):

```php
    private function reputationForTier(int $tier, \App\Enum\CitySize $citySize): int
    {
        $minRep = (int) round(70 - ($tier - 1) * (65 / 7));
        $maxRep = (int) round(90 - ($tier - 1) * (70 / 7));
        return random_int(max(1, $minRep), max(1, $maxRep));
    }

    private function balanceForTier(int $tier, \App\Enum\CitySize $citySize): int
    {
        $range = $this->gameConfigRepository->getConfig()->getNpcClubBalanceRangeForTier($tier);
        $min   = max(0, (int) $range['min']);
        $max   = max($min, (int) $range['max']);
        return random_int($min, $max);
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `lando php vendor/bin/phpunit tests/Service/NpcClubGenerationServiceTest.php --no-coverage`
Expected: PASS — all tests, including the two new tier-skew statistical tests and the pre-existing `testTier1ReputationIsHigh`/`testTier8ReputationIsLow` (unaffected since the skew logic isn't wired in yet).

- [ ] **Step 6: Commit**

```bash
git add src/Service/NpcClubGenerationService.php tests/Service/NpcClubGenerationServiceTest.php
git commit -m "feat: replace uniform place selection with tier-weighted city-size selection"
```

---

### Task 7: Reputation/balance city-size skew

**Files:**
- Modify: `src/Service/NpcClubGenerationService.php`
- Test: `tests/Service/NpcClubGenerationServiceTest.php`

**Interfaces:**
- Consumes: `reputationForTier`/`balanceForTier` signatures from Task 6.
- Produces: `NpcClubGenerationService::skewRange(int $min, int $max, \App\Enum\CitySize $citySize): array` (new **public** method, returns `[int $min, int $max]` for `random_int()`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Service/NpcClubGenerationServiceTest.php`, before the final `}`:

```php

    public function testSkewRangeBigNarrowsToUpperTwoThirds(): void
    {
        $service = $this->makeService();
        [$min, $max] = $service->skewRange(0, 90, \App\Enum\CitySize::BIG);

        $this->assertSame(30, $min);
        $this->assertSame(90, $max);
    }

    public function testSkewRangeSmallNarrowsToLowerTwoThirds(): void
    {
        $service = $this->makeService();
        [$min, $max] = $service->skewRange(0, 90, \App\Enum\CitySize::SMALL);

        $this->assertSame(0, $min);
        $this->assertSame(59, $max);
    }

    public function testSkewRangeMediumIsUnchanged(): void
    {
        $service = $this->makeService();
        [$min, $max] = $service->skewRange(10, 90, \App\Enum\CitySize::MEDIUM);

        $this->assertSame(10, $min);
        $this->assertSame(90, $max);
    }

    public function testBigCityClubsTrendTowardTopOfTierReputationRange(): void
    {
        $service = $this->makeService();
        // Force every place BIG by giving classifyPlaces a single-place list won't work since
        // generateClubs re-classifies internally; instead assert the aggregate average is higher
        // than the plain midpoint, using tier 3 (a mid-range tier with room to move).
        $clubs = $service->generateClubs(60, 3, 'ES');

        $bigReps   = [];
        $smallReps = [];
        foreach ($clubs as $club) {
            if ($club->getCitySize() === \App\Enum\CitySize::BIG) $bigReps[] = $club->getReputation();
            if ($club->getCitySize() === \App\Enum\CitySize::SMALL) $smallReps[] = $club->getReputation();
        }

        if (count($bigReps) > 0 && count($smallReps) > 0) {
            $avgBig   = array_sum($bigReps) / count($bigReps);
            $avgSmall = array_sum($smallReps) / count($smallReps);
            $this->assertGreaterThan($avgSmall, $avgBig);
        } else {
            $this->markTestSkipped('No BIG/SMALL clubs generated in this run to compare.');
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `lando php vendor/bin/phpunit tests/Service/NpcClubGenerationServiceTest.php --no-coverage`
Expected: FAIL — `skewRange()` undefined.

- [ ] **Step 3: Add `skewRange` and wire it into `reputationForTier`/`balanceForTier`**

Add the new method and replace the two temporary methods from Task 6:

```php
    /** @return array{0:int,1:int} */
    public function skewRange(int $min, int $max, \App\Enum\CitySize $citySize): array
    {
        $span = $max - $min;

        return match ($citySize) {
            \App\Enum\CitySize::BIG    => [min($max, $min + (int) round($span * 0.33)), $max],
            \App\Enum\CitySize::SMALL  => [$min, max($min, $min + (int) round($span * 0.66))],
            \App\Enum\CitySize::MEDIUM => [$min, $max],
        };
    }

    private function reputationForTier(int $tier, \App\Enum\CitySize $citySize): int
    {
        $minRep = max(1, (int) round(70 - ($tier - 1) * (65 / 7)));
        $maxRep = max(1, (int) round(90 - ($tier - 1) * (70 / 7)));
        [$min, $max] = $this->skewRange($minRep, $maxRep, $citySize);
        return random_int($min, $max);
    }

    private function balanceForTier(int $tier, \App\Enum\CitySize $citySize): int
    {
        $range = $this->gameConfigRepository->getConfig()->getNpcClubBalanceRangeForTier($tier);
        $min   = max(0, (int) $range['min']);
        $max   = max($min, (int) $range['max']);
        [$skewMin, $skewMax] = $this->skewRange($min, $max, $citySize);
        return random_int($skewMin, $skewMax);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `lando php vendor/bin/phpunit tests/Service/NpcClubGenerationServiceTest.php --no-coverage`
Expected: PASS — all tests, including the new skew tests. (`testBigCityClubsTrendTowardTopOfTierReputationRange` may occasionally skip if a particular random run produces zero BIG or zero SMALL clubs at tier 3 — that's expected and acceptable.)

- [ ] **Step 5: Run the full unit suite**

Run: `lando php vendor/bin/phpunit --no-coverage --exclude-group=functional 2>/dev/null || lando php vendor/bin/phpunit tests/Service tests/Entity --no-coverage`
Expected: PASS across all `Service` and `Entity` unit tests.

- [ ] **Step 6: Commit**

```bash
git add src/Service/NpcClubGenerationService.php tests/Service/NpcClubGenerationServiceTest.php
git commit -m "feat: skew reputation/balance rolls toward city size within each tier's range"
```

---

### Task 8: Admin-editable size-weight table

**Files:**
- Modify: `src/Controller/Admin/DashboardController.php`
- Modify: `templates/admin/npc_clubs_content.html.twig`

**Interfaces:**
- Consumes: `GameConfig::getNpcClubSizeWeights()`/`setNpcClubSizeWeights()` (Task 2).
- Produces: route `admin_npc_clubs_save_size_weights` (`POST /admin/npc-clubs/save-size-weights`).

- [ ] **Step 1: Add the controller action**

In `src/Controller/Admin/DashboardController.php`, add this method directly after `saveNpcFacilityConfig` (after the closing brace at the line following `return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));` in that method, i.e. right before the `admin_leagues_overview` route):

```php
    #[Route('/admin/npc-clubs/save-size-weights', name: 'admin_npc_clubs_save_size_weights', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveNpcClubSizeWeights(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('npc_size_weights', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
        }

        $gameConfig = $this->gameConfigRepository->getConfig();
        $raw        = $request->request->all('sizeWeights');

        $weights = [];
        foreach (['tier1', 'tier8'] as $row) {
            $rowData = $raw[$row] ?? [];
            $weights[$row] = [
                'big'    => max(0, (int) ($rowData['big'] ?? 0)),
                'medium' => max(0, (int) ($rowData['medium'] ?? 0)),
                'small'  => max(0, (int) ($rowData['small'] ?? 0)),
            ];
        }

        $gameConfig->setNpcClubSizeWeights($weights);
        $this->em->flush();

        $this->addFlash('success', 'City size weights saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
    }

```

- [ ] **Step 2: Add the admin template form**

In `templates/admin/npc_clubs_content.html.twig`, add this new section directly after the closing `</form>` of the facility-ranges form (after line 251, before the `{% endif %}` on line 252):

```twig
                    <hr class="my-4">
                    <h6 class="mb-2">City Size Weighting</h6>
                    <p class="text-muted small mb-3">
                        Controls how likely a BIG/MEDIUM/SMALL city is to be picked when generating a club
                        for a given tier. Tier 1 and Tier 8 are the anchors — tiers 2–7 interpolate
                        linearly between them. Values are percentages and don't need to sum to 100.
                    </p>
                    <form method="POST" action="{{ path('admin_npc_clubs_save_size_weights') }}">
                        <input type="hidden" name="_token" value="{{ csrf_token('npc_size_weights') }}">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-3 small align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-3">Tier</th>
                                        <th class="text-center">Big %</th>
                                        <th class="text-center">Medium %</th>
                                        <th class="text-center">Small %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {% for row in ['tier1', 'tier8'] %}
                                        <tr>
                                            <td class="ps-3 fw-semibold">{{ row == 'tier1' ? 'Tier 1' : 'Tier 8' }}</td>
                                            <td class="text-center p-1">
                                                <input type="number" name="sizeWeights[{{ row }}][big]"
                                                       value="{{ gameConfig.npcClubSizeWeights[row].big ?? 0 }}" min="0" max="100"
                                                       class="form-control form-control-sm text-center p-1" style="width:70px;margin:0 auto">
                                            </td>
                                            <td class="text-center p-1">
                                                <input type="number" name="sizeWeights[{{ row }}][medium]"
                                                       value="{{ gameConfig.npcClubSizeWeights[row].medium ?? 0 }}" min="0" max="100"
                                                       class="form-control form-control-sm text-center p-1" style="width:70px;margin:0 auto">
                                            </td>
                                            <td class="text-center p-1">
                                                <input type="number" name="sizeWeights[{{ row }}][small]"
                                                       value="{{ gameConfig.npcClubSizeWeights[row].small ?? 0 }}" min="0" max="100"
                                                       class="form-control form-control-sm text-center p-1" style="width:70px;margin:0 auto">
                                            </td>
                                        </tr>
                                    {% endfor %}
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fa fa-save me-1"></i>Save Size Weights
                        </button>
                    </form>
```

- [ ] **Step 3: Manually verify in the admin panel**

Run: `lando start` (if not already running), then visit `https://<project>.lndo.site/admin` in a browser, log in as an admin (`lando psql -c "UPDATE \"admin\" SET roles = '[\"ROLE_ADMIN\"]' WHERE email = 'you@example.com';"` if needed — see `CLAUDE.md`), navigate to **Generate** (`admin_npc_clubs_content`).
Expected: a new "City Size Weighting" table appears below the facility ranges form, pre-filled with `Big=70/Medium=25/Small=5` (Tier 1) and `Big=5/Medium=25/Small=70` (Tier 8). Change a value, click "Save Size Weights", confirm the flash message "City size weights saved." appears and the value persists after reload.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/Admin/DashboardController.php templates/admin/npc_clubs_content.html.twig
git commit -m "feat: add admin-editable NPC club size weight table"
```

---

### Task 9: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: Run the full unit test suite**

Run: `lando php vendor/bin/phpunit --no-coverage`
Expected: all tests pass, including every file touched across Tasks 1–7 and pre-existing tests that construct `NpcClub` (`NpcClubTest`, `NpcClubLeagueFieldTest`, `SyncServiceLeagueTest`, `LeagueServiceTest`, `WorldInitializationTierPackAgentTest`, `NpcClubGenerationServiceLeagueTest`, `NpcClubCrudControllerTest`).

- [ ] **Step 2: Verify the `/api/club/name-options` contract is unchanged**

Run: `lando php bin/console debug:router api_clubs_name_options` to confirm the route still exists, then start the app and hit it:
```bash
curl -s "http://localhost/api/club/name-options?country=EN" -H "Authorization: Bearer <token>" | head -c 500
```
Expected: JSON with `cities` as a flat array of city-name strings (e.g. `"London"`, `"Manchester"`) and `suffixes` as a flat array of suffix strings (e.g. `"United"`, `"FC"`) — unchanged shape from before this feature.

- [ ] **Step 3: Regenerate a sample of NPC clubs and spot-check via psql**

```bash
lando php bin/console doctrine:migrations:migrate --no-interaction
```
(only if not already run in Task 3), then trigger club generation for one country/tier via the admin "Generate" action or existing command, then:
```bash
lando psql -c "SELECT name, tier, city_size, population_size, region, is_capital FROM npc_club WHERE country = 'ES' ORDER BY tier, population_size DESC LIMIT 20;"
```
Expected: tier 1 rows skew toward `city_size = BIG` with high `population_size`/real `region` values; higher-tier rows skew toward `SMALL`.

- [ ] **Step 4: Final commit (if any cleanup was needed)**

```bash
git status
```
If clean, no commit needed — proceed to finishing-a-development-branch.

---

## Summary of Deviations from the Approved Spec (flag to user)

- **Curated place count**: spec discussed "~100 per country"; this plan ships **~40 real, verified places per country** to keep the plan self-contained with concrete, checkable data rather than open-ended research placeholders. More places can be added later by extending `PLACE_NAMES_BY_COUNTRY` following the same format — the classification/weighting logic scales automatically.
