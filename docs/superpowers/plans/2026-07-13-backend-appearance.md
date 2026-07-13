# Backend-Owned Appearance (Avatar) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every pool entity (`Player`, `Staff`, `Scout`, `Agent`) a backend-generated, admin-editable `appearance` object that is returned on all player/staff/market payloads in a shape that is a drop-in replacement for the frontend's existing `Appearance`.

**Architecture:** A single `json` column per entity stores the appearance. A `AppearanceGeneratorService` (faithful PHP port of the frontend `generateAppearance()`) produces it deterministically from `(id, role, age)`. A Doctrine `prePersist` subscriber auto-fills appearance for any of the four entity types persisted without one, so every creation path is covered centrally. Snapshot builders and market serializers emit `getAppearance()` verbatim. The admin edit form uses a custom `AppearanceType` with per-attribute dropdowns and a live SVG preview that reuses the real frontend compositor (copied into `public/`).

**Tech Stack:** Symfony 8 / PHP 8.4, Doctrine ORM 3 + Migrations, PostgreSQL 16, EasyAdmin v5, PHPUnit. All PHP commands run inside Lando (`lando php ...`).

## Global Constraints

- All PHP/console/composer commands run inside Lando: `lando php bin/console ...`, `lando php vendor/bin/phpunit ...`.
- PostgreSQL only — migrations use Postgres syntax (no `AUTO_INCREMENT`, no `ENGINE=InnoDB`) or the Doctrine Schema API.
- Never commit to `master`. Create branch `feat/backend-appearance` before Task 1.
- The emitted `appearance` object MUST match the frontend `Appearance` type field-for-field (`wunderkind-app/src/types/player.ts:74-101`). Stored/emitted keys are exactly: `skinTone, hairStyle, hairColor, accessory, kitTrim, facialHair, faceShape, eyeShape, noseType, jerseyVariant`. The three dead fields (`expression, earSize, eyebrowStyle`) are omitted.
- Enum string values must match the frontend value strings exactly (e.g. `hairStyle` value `messy`, `hairColor` value `dark_brown`, `skinTone` hex `#dfaa80`).
- Test suite has ~38 pre-existing unrelated failures; only judge tasks by the tests they add.
- Commit after every task.

---

## File Structure

**Create:**
- `src/Enum/Appearance/AppearanceRole.php` — PLAYER/COACH/SCOUT/AGENT
- `src/Enum/Appearance/SkinTone.php`, `HairStyle.php`, `HairColor.php`, `AvatarAccessory.php`, `FacialHair.php`, `FaceShape.php`, `EyeShape.php`, `NoseType.php`
- `src/Service/Appearance/SeededRng.php` — deterministic LCG PRNG
- `src/Service/Appearance/AppearanceGeneratorService.php` — the port
- `src/EventSubscriber/AppearanceLifecycleSubscriber.php` — prePersist auto-fill
- `src/Command/BackfillAppearancesCommand.php` — backfill existing rows
- `src/Form/Type/AppearanceType.php` — admin compound form + data mapper
- `templates/admin/field/appearance.html.twig` — form theme with live preview
- `public/admin/avatar-compositor.js` — copied frontend compositor (plain JS)
- `migrations/VersionYYYYMMDDHHMMSS.php` — 4 nullable json columns
- Tests under `tests/Enum/`, `tests/Service/Appearance/`, `tests/Entity/`, `tests/Form/`, `tests/Command/`

**Modify:**
- `src/Entity/Player.php`, `Staff.php`, `Scout.php`, `Agent.php` — add `appearance` column + getter/setter
- `src/Service/WorldInitializationService.php` — `buildPlayerSnapshot`, `buildStaffSnapshot`
- `src/Service/MarketDataService.php` — `serializeCoach`, `serializeScout`, `serializeAgent`
- `src/Controller/Admin/PlayerCrudController.php`, `StaffCrudController.php`, `ScoutCrudController.php` — add appearance field

---

### Task 1: Appearance enums

**Files:**
- Create: `src/Enum/Appearance/AppearanceRole.php`, `SkinTone.php`, `HairStyle.php`, `HairColor.php`, `AvatarAccessory.php`, `FacialHair.php`, `FaceShape.php`, `EyeShape.php`, `NoseType.php`
- Test: `tests/Enum/AppearanceEnumsTest.php`

**Interfaces:**
- Produces: nine backed string enums in namespace `App\Enum\Appearance`. `AppearanceRole` cases `PLAYER/COACH/SCOUT/AGENT`. Value strings match the table below.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace App\Tests\Enum;

use App\Enum\Appearance\AppearanceRole;
use App\Enum\Appearance\SkinTone;
use App\Enum\Appearance\HairStyle;
use App\Enum\Appearance\HairColor;
use App\Enum\Appearance\AvatarAccessory;
use App\Enum\Appearance\FacialHair;
use App\Enum\Appearance\FaceShape;
use App\Enum\Appearance\EyeShape;
use App\Enum\Appearance\NoseType;
use PHPUnit\Framework\TestCase;

class AppearanceEnumsTest extends TestCase
{
    public function testFrontendValueStrings(): void
    {
        $this->assertSame('messy', HairStyle::MESSY->value);
        $this->assertSame('dark_brown', HairColor::DARK_BROWN->value);
        $this->assertSame('#dfaa80', SkinTone::MEDIUM->value);
        $this->assertSame('french_smile', FacialHair::FRENCH_SMILE->value);
        $this->assertSame('neck_tattoo', AvatarAccessory::NECK_TATTOO->value);
        $this->assertSame('downside_large', NoseType::DOWNSIDE_LARGE->value);
        $this->assertSame('square', FaceShape::SQUARE->value);
        $this->assertSame('round', EyeShape::ROUND->value);
        $this->assertSame('SCOUT', AppearanceRole::SCOUT->value);
    }

    public function testCaseCounts(): void
    {
        $this->assertCount(6, SkinTone::cases());
        $this->assertCount(7, HairStyle::cases());
        $this->assertCount(5, HairColor::cases());
        $this->assertCount(7, AvatarAccessory::cases());
        $this->assertCount(7, FacialHair::cases());
        $this->assertCount(3, FaceShape::cases());
        $this->assertCount(2, EyeShape::cases());
        $this->assertCount(5, NoseType::cases());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lando php vendor/bin/phpunit tests/Enum/AppearanceEnumsTest.php --no-coverage`
Expected: FAIL — "Class ... not found".

- [ ] **Step 3: Create the enums**

`src/Enum/Appearance/AppearanceRole.php`:
```php
<?php
namespace App\Enum\Appearance;

enum AppearanceRole: string
{
    case PLAYER = 'PLAYER';
    case COACH  = 'COACH';
    case SCOUT  = 'SCOUT';
    case AGENT  = 'AGENT';
}
```

`src/Enum/Appearance/SkinTone.php`:
```php
<?php
namespace App\Enum\Appearance;

enum SkinTone: string
{
    case VERY_LIGHT = '#f5dcc8';
    case LIGHT      = '#e8c49a';
    case MEDIUM     = '#dfaa80';
    case TAN        = '#c47d4a';
    case BROWN      = '#8b4c1e';
    case DARK       = '#5c2d0a';
}
```

`src/Enum/Appearance/HairStyle.php`:
```php
<?php
namespace App\Enum\Appearance;

enum HairStyle: string
{
    case BALD    = 'bald';
    case CLASSIC = 'classic';
    case MESSY   = 'messy';
    case ROUND   = 'round';
    case SMART   = 'smart';
    case SPIKE   = 'spike';
    case USUAL   = 'usual';
}
```

`src/Enum/Appearance/HairColor.php`:
```php
<?php
namespace App\Enum\Appearance;

enum HairColor: string
{
    case BLACK       = 'black';
    case DARK_BROWN  = 'dark_brown';
    case BROWN       = 'brown';
    case LIGHT_BROWN = 'light_brown';
    case BLONDE      = 'blonde';
}
```

`src/Enum/Appearance/AvatarAccessory.php`:
```php
<?php
namespace App\Enum\Appearance;

enum AvatarAccessory: string
{
    case GLASSES     = 'glasses';
    case SUNGLASSES  = 'sunglasses';
    case WHISTLE     = 'whistle';
    case HEADSET     = 'headset';
    case BEANIE      = 'beanie';
    case FACE_TATTOO = 'face_tattoo';
    case NECK_TATTOO = 'neck_tattoo';
}
```

`src/Enum/Appearance/FacialHair.php`:
```php
<?php
namespace App\Enum\Appearance;

enum FacialHair: string
{
    case NONE         = 'none';
    case STUBBLE      = 'stubble';
    case MOUSTACHE    = 'moustache';
    case GOATEE       = 'goatee';
    case BEARD        = 'beard';
    case FENCH_2      = 'fench_2';
    case FRENCH_SMILE = 'french_smile';
}
```

`src/Enum/Appearance/FaceShape.php`:
```php
<?php
namespace App\Enum\Appearance;

enum FaceShape: string
{
    case OVAL   = 'oval';
    case ROUND  = 'round';
    case SQUARE = 'square';
}
```

`src/Enum/Appearance/EyeShape.php`:
```php
<?php
namespace App\Enum\Appearance;

enum EyeShape: string
{
    case NARROW = 'narrow';
    case ROUND  = 'round';
}
```

`src/Enum/Appearance/NoseType.php`:
```php
<?php
namespace App\Enum\Appearance;

enum NoseType: string
{
    case NORMAL         = 'normal';
    case SMALL          = 'small';
    case DOWNSIDE_LARGE = 'downside_large';
    case MEDIUM         = 'medium';
    case UPSIDE_LARGE   = 'upside_large';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `lando php vendor/bin/phpunit tests/Enum/AppearanceEnumsTest.php --no-coverage`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Enum/Appearance tests/Enum/AppearanceEnumsTest.php
git commit -m "feat: appearance enums (single source of truth for generation + admin)"
```

---

### Task 2: AppearanceGeneratorService (port of generateAppearance)

**Files:**
- Create: `src/Service/Appearance/SeededRng.php`, `src/Service/Appearance/AppearanceGeneratorService.php`
- Test: `tests/Service/Appearance/AppearanceGeneratorServiceTest.php`

**Interfaces:**
- Consumes: enums from Task 1.
- Produces:
  - `SeededRng::__construct(int $seed)`, `next(): float` (in `[0,1)`), `pick(array $arr): mixed`, `chance(float $p): bool`.
  - `AppearanceGeneratorService::generate(string $id, AppearanceRole $role, int $age): array` — returns the 10-key appearance array. Keys/values are frontend-shaped. `accessory` is `string|null`. `jerseyVariant` is `int` 1–3. `faceShape` always `'oval'`, `eyeShape` always `'narrow'` (generator defaults; still emitted so the object is complete).

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace App\Tests\Service\Appearance;

use App\Enum\Appearance\AppearanceRole;
use App\Service\Appearance\AppearanceGeneratorService;
use PHPUnit\Framework\TestCase;

class AppearanceGeneratorServiceTest extends TestCase
{
    private AppearanceGeneratorService $svc;

    protected function setUp(): void
    {
        $this->svc = new AppearanceGeneratorService();
    }

    public function testDeterministic(): void
    {
        $a = $this->svc->generate('abc-123', AppearanceRole::PLAYER, 18);
        $b = $this->svc->generate('abc-123', AppearanceRole::PLAYER, 18);
        $this->assertSame($a, $b);
    }

    public function testDifferentIdsDiffer(): void
    {
        $a = $this->svc->generate('id-one', AppearanceRole::PLAYER, 18);
        $b = $this->svc->generate('id-two', AppearanceRole::PLAYER, 18);
        $this->assertNotSame($a, $b);
    }

    public function testShapeAndKeys(): void
    {
        $a = $this->svc->generate('shape-id', AppearanceRole::PLAYER, 20);
        $this->assertSame(
            ['skinTone','hairStyle','hairColor','accessory','kitTrim','facialHair','faceShape','eyeShape','noseType','jerseyVariant'],
            array_keys($a),
        );
        $this->assertSame('oval', $a['faceShape']);
        $this->assertSame('narrow', $a['eyeShape']);
        $this->assertIsInt($a['jerseyVariant']);
        $this->assertGreaterThanOrEqual(1, $a['jerseyVariant']);
        $this->assertLessThanOrEqual(3, $a['jerseyVariant']);
    }

    public function testPlayerNeverHasFacialHairOrStaffAccessories(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $a = $this->svc->generate("player-$i", AppearanceRole::PLAYER, 22);
            $this->assertSame('none', $a['facialHair']);
            $this->assertNotContains($a['accessory'], ['whistle', 'headset', 'beanie']);
        }
    }

    public function testValuesAreInAllowedSets(): void
    {
        $skins = ['#f5dcc8','#e8c49a','#dfaa80','#c47d4a','#8b4c1e','#5c2d0a'];
        $playerTrims = ['#f5c842','#e8852a','#3a8fd4','#d94040','#2eab5a','#9b59b6'];
        for ($i = 0; $i < 100; $i++) {
            $a = $this->svc->generate("v-$i", AppearanceRole::PLAYER, 21);
            $this->assertContains($a['skinTone'], $skins);
            $this->assertContains($a['kitTrim'], $playerTrims);
        }
    }

    public function testStaffCanHaveFacialHairAndMutedTrim(): void
    {
        $staffTrims = ['#4a5568','#2d3748','#374151','#1e3a5f'];
        $sawFacialHair = false;
        for ($i = 0; $i < 100; $i++) {
            $a = $this->svc->generate("coach-$i", AppearanceRole::COACH, 50);
            $this->assertContains($a['kitTrim'], $staffTrims);
            if ($a['facialHair'] !== 'none') { $sawFacialHair = true; }
        }
        $this->assertTrue($sawFacialHair);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lando php vendor/bin/phpunit tests/Service/Appearance/AppearanceGeneratorServiceTest.php --no-coverage`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `SeededRng`**

`src/Service/Appearance/SeededRng.php`:
```php
<?php
namespace App\Service\Appearance;

/**
 * Seeded LCG (Linear Congruential Generator) — faithful port of the frontend
 * SeededRng in wunderkind-app/src/engine/appearance.ts. All arithmetic is kept
 * to unsigned 32-bit via `& 0xFFFFFFFF` so output matches the JS `>>> 0` semantics.
 */
final class SeededRng
{
    private int $s;

    public function __construct(int $seed)
    {
        $this->s = $seed & 0xFFFFFFFF;
    }

    /** djb2-variant string hash → stable uint32 seed. */
    public static function hashId(string $id): int
    {
        $hash = 5381;
        $len  = strlen($id);
        for ($i = 0; $i < $len; $i++) {
            $hash = ((($hash << 5) + $hash) ^ ord($id[$i])) & 0xFFFFFFFF;
        }
        return $hash & 0xFFFFFFFF;
    }

    /** Float in [0, 1). */
    public function next(): float
    {
        $this->s = (($this->s * 1664525) + 1013904223) & 0xFFFFFFFF;
        return $this->s / 4294967296; // 0x100000000
    }

    public function pick(array $arr): mixed
    {
        return $arr[(int) floor($this->next() * count($arr))];
    }

    public function chance(float $probability): bool
    {
        return $this->next() < $probability;
    }
}
```

- [ ] **Step 4: Create `AppearanceGeneratorService`**

`src/Service/Appearance/AppearanceGeneratorService.php`:
```php
<?php
namespace App\Service\Appearance;

use App\Enum\Appearance\AppearanceRole;

/**
 * Faithful PHP port of generateAppearance() from
 * wunderkind-app/src/engine/appearance.ts. Deterministic from (id, role, age).
 * Emits the 10 rendered fields only (dead fields expression/earSize/eyebrowStyle
 * are omitted). faceShape/eyeShape are emitted as their frontend defaults.
 */
final class AppearanceGeneratorService
{
    private const SKIN_TONES = ['#f5dcc8', '#e8c49a', '#dfaa80', '#c47d4a', '#8b4c1e', '#5c2d0a'];
    private const HAIR_COLORS = ['blonde', 'light_brown', 'brown', 'dark_brown', 'black'];
    private const PLAYER_TRIMS = ['#f5c842', '#e8852a', '#3a8fd4', '#d94040', '#2eab5a', '#9b59b6'];
    private const STAFF_TRIMS = ['#4a5568', '#2d3748', '#374151', '#1e3a5f'];

    /** @return array<string, mixed> */
    public function generate(string $id, AppearanceRole $role, int $age): array
    {
        $rng = new SeededRng(SeededRng::hashId($id));

        // Skin tone
        $skinTone = $rng->pick(self::SKIN_TONES);

        // Hair style — older staff skews smart/bald
        if ($age > 45) {
            $hairStylePool = ['smart', 'smart', 'classic', 'bald', 'bald'];
        } elseif ($age > 35) {
            $hairStylePool = ['smart', 'classic', 'usual', 'round', 'bald'];
        } else {
            $hairStylePool = ['classic', 'messy', 'spike', 'usual', 'smart', 'round', 'bald'];
        }
        $hairStyle = $rng->pick($hairStylePool);

        // Hair color — older coaches/scouts skew darker
        if ($hairStyle === 'bald') {
            $hairColor = 'brown'; // irrelevant; won't render
        } elseif ($role === AppearanceRole::COACH && $age > 42 && $rng->chance(0.5)) {
            $hairColor = 'dark_brown';
        } elseif ($age > 38 && $rng->chance(0.3)) {
            $hairColor = 'dark_brown';
        } else {
            $hairColor = $rng->pick(self::HAIR_COLORS);
        }

        // NOTE: the JS reads one rng value for `expression` here (dead field). We
        // consume it too so downstream picks keep the same stream position.
        $rng->pick([0, 0, 1, 2]);

        // Role-specific accessory
        $accessory = null;
        if ($role === AppearanceRole::COACH) {
            if ($age > 40 && $rng->chance(0.38)) {
                $accessory = 'glasses';
            } elseif ($rng->chance(0.12)) {
                $accessory = 'beanie';
            } elseif ($rng->chance(0.08)) {
                $accessory = 'sunglasses';
            } elseif ($rng->chance(0.22)) {
                $accessory = 'whistle';
            }
        } elseif ($role === AppearanceRole::SCOUT) {
            $roll = $rng->next();
            if ($roll < 0.25) { $accessory = 'headset'; }
            elseif ($roll < 0.45) { $accessory = 'glasses'; }
        } elseif ($role === AppearanceRole::AGENT) {
            if ($rng->chance(0.30)) { $accessory = 'glasses'; }
        } elseif ($role === AppearanceRole::PLAYER && $age >= 20) {
            $roll = $rng->next();
            if ($roll < 0.06) { $accessory = 'face_tattoo'; }
            elseif ($roll < 0.12) { $accessory = 'neck_tattoo'; }
        }

        // Kit trim
        $kitTrim = $role === AppearanceRole::PLAYER
            ? $rng->pick(self::PLAYER_TRIMS)
            : $rng->pick(self::STAFF_TRIMS);

        // Facial hair — players always none
        $facialHair = 'none';
        if ($role !== AppearanceRole::PLAYER && $age >= 20) {
            if (!$rng->chance(0.40)) {
                $pool = $age > 45
                    ? ['stubble', 'stubble', 'beard', 'beard', 'moustache']
                    : ['stubble', 'stubble', 'moustache', 'goatee', 'beard', 'fench_2', 'french_smile'];
                $facialHair = $rng->pick($pool);
            }
        }

        // Nose and jersey
        $noseType      = $rng->pick(['normal', 'normal', 'small']);
        $jerseyVariant = (int) floor($rng->next() * 3) + 1;

        return [
            'skinTone'      => $skinTone,
            'hairStyle'     => $hairStyle,
            'hairColor'     => $hairColor,
            'accessory'     => $accessory,
            'kitTrim'       => $kitTrim,
            'facialHair'    => $facialHair,
            'faceShape'     => 'oval',
            'eyeShape'      => 'narrow',
            'noseType'      => $noseType,
            'jerseyVariant' => $jerseyVariant,
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `lando php vendor/bin/phpunit tests/Service/Appearance/AppearanceGeneratorServiceTest.php --no-coverage`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Service/Appearance tests/Service/Appearance
git commit -m "feat: AppearanceGeneratorService — deterministic PHP port of generateAppearance"
```

---

### Task 3: Add `appearance` column + accessors to the four entities + migration

**Files:**
- Modify: `src/Entity/Player.php`, `src/Entity/Staff.php`, `src/Entity/Scout.php`, `src/Entity/Agent.php`
- Create: `migrations/VersionYYYYMMDDHHMMSS.php` (generated)
- Test: `tests/Entity/AppearanceColumnTest.php`

**Interfaces:**
- Produces on each of the four entities: `getAppearance(): ?array` and `setAppearance(?array $appearance): void`, backed by `#[ORM\Column(type: 'json', nullable: true)] private ?array $appearance = null;`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace App\Tests\Entity;

use App\Entity\Player;
use App\Entity\Staff;
use App\Entity\Scout;
use App\Entity\Agent;
use PHPUnit\Framework\TestCase;

class AppearanceColumnTest extends TestCase
{
    public function testAllFourEntitiesRoundTripAppearance(): void
    {
        $appearance = [
            'skinTone' => '#dfaa80', 'hairStyle' => 'messy', 'hairColor' => 'dark_brown',
            'accessory' => null, 'kitTrim' => '#3a8fd4', 'facialHair' => 'none',
            'faceShape' => 'oval', 'eyeShape' => 'narrow', 'noseType' => 'normal', 'jerseyVariant' => 2,
        ];

        foreach ([new Player(), new Staff(), new Scout(), new Agent('A')] as $entity) {
            $this->assertNull($entity->getAppearance());
            $entity->setAppearance($appearance);
            $this->assertSame($appearance, $entity->getAppearance());
        }
    }
}
```

Note: confirm the `Agent` constructor signature before running (it takes a name string — `new Agent('A')`). If `Player`/`Staff`/`Scout` constructors require args in this codebase version, use the no-arg / default forms already shown in `src/Entity/*` (`new Player()`, `new Staff()`, `new Scout()` all have defaulted params).

- [ ] **Step 2: Run test to verify it fails**

Run: `lando php vendor/bin/phpunit tests/Entity/AppearanceColumnTest.php --no-coverage`
Expected: FAIL — "Call to undefined method ...::setAppearance()".

- [ ] **Step 3: Add the field + accessors to each entity**

In each of `src/Entity/Player.php`, `Staff.php`, `Scout.php`, `Agent.php`, add the property near the other columns:
```php
    /** Avatar appearance (frontend Appearance shape). Null until generated/backfilled. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $appearance = null;
```
and the accessors near the other getters/setters:
```php
    public function getAppearance(): ?array { return $this->appearance; }
    public function setAppearance(?array $appearance): void { $this->appearance = $appearance; }
```

- [ ] **Step 4: Run entity test to verify it passes**

Run: `lando php vendor/bin/phpunit tests/Entity/AppearanceColumnTest.php --no-coverage`
Expected: PASS (1 test).

- [ ] **Step 5: Generate the migration**

Run: `lando php bin/console doctrine:migrations:diff`
Then open the generated `migrations/VersionYYYYMMDDHHMMSS.php` and confirm `up()` contains four statements of the form:
```php
$this->addSql('ALTER TABLE player ADD appearance JSON DEFAULT NULL');
$this->addSql('ALTER TABLE staff ADD appearance JSON DEFAULT NULL');
$this->addSql('ALTER TABLE scout ADD appearance JSON DEFAULT NULL');
$this->addSql('ALTER TABLE agent ADD appearance JSON DEFAULT NULL');
```
and `down()` drops the four columns. Delete any unrelated statements the differ may have added from pre-existing schema drift (keep only the four ADD/DROP for `appearance`).

- [ ] **Step 6: Run the migration**

Run: `lando php bin/console doctrine:migrations:migrate --no-interaction`
Expected: "migrated" / no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Entity tests/Entity/AppearanceColumnTest.php migrations
git commit -m "feat: add nullable appearance json column to player/staff/scout/agent"
```

---

### Task 4: prePersist subscriber — auto-fill appearance on any creation path

**Files:**
- Create: `src/EventSubscriber/AppearanceLifecycleSubscriber.php`
- Test: `tests/Service/Appearance/AppearanceLifecycleSubscriberTest.php`

**Interfaces:**
- Consumes: `AppearanceGeneratorService::generate()`, entity `getAppearance()/setAppearance()`, entity `getId()` (returns `UuidV7`; cast with `(string)`), Player `getDateOfBirth()`, Staff/Scout/Agent `getDob()`.
- Produces: `AppearanceLifecycleSubscriber` implementing Doctrine's `prePersist` hook. Given a `Player|Staff|Scout|Agent` with null appearance, sets a generated appearance. Non-target entities and entities that already have an appearance are left untouched.
- Exposes for unit testing: `public function fill(object $entity): void` (the pure logic, no Doctrine event args).

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace App\Tests\Service\Appearance;

use App\Entity\Agent;
use App\Entity\Club;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\EventSubscriber\AppearanceLifecycleSubscriber;
use App\Service\Appearance\AppearanceGeneratorService;
use PHPUnit\Framework\TestCase;

class AppearanceLifecycleSubscriberTest extends TestCase
{
    private AppearanceLifecycleSubscriber $sub;

    protected function setUp(): void
    {
        $this->sub = new AppearanceLifecycleSubscriber(new AppearanceGeneratorService());
    }

    public function testFillsPlayerWithNullAppearance(): void
    {
        $player = new Player();
        $this->sub->fill($player);
        $this->assertNotNull($player->getAppearance());
        $this->assertSame('none', $player->getAppearance()['facialHair']); // player rule
    }

    public function testFillsStaffScoutAgent(): void
    {
        foreach ([new Staff(), new Scout('S'), new Agent('A')] as $e) {
            $this->sub->fill($e);
            $this->assertNotNull($e->getAppearance());
            $this->assertArrayHasKey('jerseyVariant', $e->getAppearance());
        }
    }

    public function testDoesNotOverwriteExisting(): void
    {
        $player = new Player();
        $player->setAppearance(['skinTone' => '#000000']);
        $this->sub->fill($player);
        $this->assertSame(['skinTone' => '#000000'], $player->getAppearance());
    }

    public function testIgnoresUnrelatedEntities(): void
    {
        $club = new Club();
        $this->sub->fill($club); // must not throw
        $this->assertTrue(true);
    }
}
```

Note: confirm `Scout` constructor takes a name (`new Scout('S')`) and `Agent` too (`new Agent('A')`); `Staff`/`Player` use defaulted no-arg constructors. Adjust the `new` calls to match the actual constructors in `src/Entity/`.

- [ ] **Step 2: Run test to verify it fails**

Run: `lando php vendor/bin/phpunit tests/Service/Appearance/AppearanceLifecycleSubscriberTest.php --no-coverage`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the subscriber**

`src/EventSubscriber/AppearanceLifecycleSubscriber.php`:
```php
<?php
namespace App\EventSubscriber;

use App\Entity\Agent;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\Appearance\AppearanceRole;
use App\Service\Appearance\AppearanceGeneratorService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Auto-fills a generated appearance for any Player/Staff/Scout/Agent persisted
 * without one. Centralises appearance generation across every creation path
 * (services, commands, admin) so no construction site can forget it.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class AppearanceLifecycleSubscriber
{
    /** Fallback age for staff/scout/agent whose dob is null. */
    private const DEFAULT_STAFF_AGE = 40;

    public function __construct(
        private readonly AppearanceGeneratorService $generator,
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->fill($args->getObject());
    }

    /** Pure logic, no Doctrine args — unit-testable. */
    public function fill(object $entity): void
    {
        if (!$entity instanceof Player
            && !$entity instanceof Staff
            && !$entity instanceof Scout
            && !$entity instanceof Agent) {
            return;
        }
        if ($entity->getAppearance() !== null) {
            return;
        }

        [$role, $age] = $this->roleAndAge($entity);
        $entity->setAppearance(
            $this->generator->generate((string) $entity->getId(), $role, $age)
        );
    }

    /** @return array{0: AppearanceRole, 1: int} */
    private function roleAndAge(Player|Staff|Scout|Agent $entity): array
    {
        if ($entity instanceof Player) {
            return [AppearanceRole::PLAYER, $this->ageFromDob($entity->getDateOfBirth())];
        }
        if ($entity instanceof Staff) {
            return [AppearanceRole::COACH, $this->ageFromDob($entity->getDob())];
        }
        if ($entity instanceof Scout) {
            return [AppearanceRole::SCOUT, $this->ageFromDob($entity->getDob())];
        }
        return [AppearanceRole::AGENT, $this->ageFromDob($entity->getDob())];
    }

    private function ageFromDob(?\DateTimeImmutable $dob): int
    {
        if ($dob === null) {
            return self::DEFAULT_STAFF_AGE;
        }
        return (int) $dob->diff(new \DateTimeImmutable('now'))->y;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `lando php vendor/bin/phpunit tests/Service/Appearance/AppearanceLifecycleSubscriberTest.php --no-coverage`
Expected: PASS (4 tests).

- [ ] **Step 5: Verify auto-registration & end-to-end generation**

The `#[AsDoctrineListener]` attribute auto-registers the listener (Symfony autoconfigure). Verify a freshly generated pool player gets an appearance persisted:
```bash
lando php bin/console app:market:generate 2>/dev/null || true
lando psql -t -c "SELECT appearance IS NOT NULL FROM player LIMIT 1;"
```
Expected: `t` for newly created rows. (Pre-existing rows may still be null until Task 6 backfill.)

- [ ] **Step 6: Commit**

```bash
git add src/EventSubscriber tests/Service/Appearance/AppearanceLifecycleSubscriberTest.php
git commit -m "feat: prePersist subscriber auto-fills appearance for player/staff/scout/agent"
```

---

### Task 5: Emit appearance in snapshots and market serializers

**Files:**
- Modify: `src/Service/WorldInitializationService.php` (`buildPlayerSnapshot` ~line 371, `buildStaffSnapshot` ~line 412)
- Modify: `src/Service/MarketDataService.php` (`serializeCoach` ~37, `serializeAgent` ~55, `serializeScout` ~68)
- Test: `tests/Service/AppearanceSerializationTest.php`

**Interfaces:**
- Consumes: entity `getAppearance()`.
- Produces: each of the five array-builders includes key `'appearance' => $entity->getAppearance()` (value is `?array`, passed through verbatim).

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace App\Tests\Service;

use App\Entity\Player;
use App\Entity\Staff;
use App\Service\WorldInitializationService;
use PHPUnit\Framework\TestCase;

class AppearanceSerializationTest extends TestCase
{
    public function testBuildPlayerSnapshotIncludesAppearanceVerbatim(): void
    {
        $appearance = [
            'skinTone' => '#dfaa80', 'hairStyle' => 'messy', 'hairColor' => 'dark_brown',
            'accessory' => null, 'kitTrim' => '#3a8fd4', 'facialHair' => 'none',
            'faceShape' => 'oval', 'eyeShape' => 'narrow', 'noseType' => 'normal', 'jerseyVariant' => 2,
        ];
        $player = new Player('Test', 'Player');
        $player->setAppearance($appearance);

        // buildPlayerSnapshot is a pure mapper — call it on a service instance
        // whose collaborators are irrelevant to this method. Use reflection to
        // instantiate without the constructor if DI is heavy, or construct via
        // the container in a KernelTestCase. Simplest: assert the method output
        // through a partial — here we call the method directly on a mock-free
        // instance built by the container.
        $svc = static::buildService();
        $snap = $svc->buildPlayerSnapshot($player);

        $this->assertArrayHasKey('appearance', $snap);
        $this->assertSame($appearance, $snap['appearance']);
    }
}
```

Note on instantiation: `WorldInitializationService` has constructor dependencies. If `buildPlayerSnapshot`/`buildStaffSnapshot` don't touch those deps (they are pure mappers over the entity), instantiate the service in a `KernelTestCase` via `self::getContainer()->get(WorldInitializationService::class)` and drop the `static::buildService()` placeholder — replace the class base with `KernelTestCase` and implement `buildService()` as `return self::getContainer()->get(WorldInitializationService::class);`. Confirm the service is `public` or fetch it through a public alias; if not resolvable, mark the service `public: true` in `config/services.yaml` for tests, or use `ReflectionClass::newInstanceWithoutConstructor()` since the mapper reads only the passed entity.

- [ ] **Step 2: Run test to verify it fails**

Run: `lando php vendor/bin/phpunit tests/Service/AppearanceSerializationTest.php --no-coverage`
Expected: FAIL — `appearance` key missing.

- [ ] **Step 3: Add appearance to the two snapshot builders**

In `src/Service/WorldInitializationService.php`, inside the array returned by `buildPlayerSnapshot()`, add after `'personality' => [...]`:
```php
            'appearance' => $player->getAppearance(),
```
Inside `buildStaffSnapshot()`, add after `'specialisms' => ...`:
```php
            'appearance' => $staff->getAppearance(),
```

- [ ] **Step 4: Add appearance to the three market serializers**

In `src/Service/MarketDataService.php`:
- `serializeCoach()` — add `'appearance' => $s->getAppearance(),`
- `serializeAgent()` — add `'appearance' => $a->getAppearance(),`
- `serializeScout()` — add `'appearance' => $s->getAppearance(),`

- [ ] **Step 5: Run test to verify it passes**

Run: `lando php vendor/bin/phpunit tests/Service/AppearanceSerializationTest.php --no-coverage`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Service/WorldInitializationService.php src/Service/MarketDataService.php tests/Service/AppearanceSerializationTest.php
git commit -m "feat: emit appearance in player/staff snapshots and market serializers"
```

---

### Task 6: Backfill command for existing rows

**Files:**
- Create: `src/Command/BackfillAppearancesCommand.php`
- Test: `tests/Command/BackfillAppearancesCommandTest.php`

**Interfaces:**
- Consumes: `AppearanceLifecycleSubscriber::fill()` (reuses the exact role/age mapping so backfill and auto-fill are identical), `EntityManagerInterface`.
- Produces: console command `app:backfill-appearances` that iterates all Player/Staff/Scout/Agent rows with null appearance, calls `fill()` on each, and flushes in batches.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace App\Tests\Command;

use App\Command\BackfillAppearancesCommand;
use PHPUnit\Framework\TestCase;

class BackfillAppearancesCommandTest extends TestCase
{
    public function testCommandNameIsConfigured(): void
    {
        $ref = new \ReflectionClass(BackfillAppearancesCommand::class);
        $attr = $ref->getAttributes(\Symfony\Component\Console\Attribute\AsCommand::class);
        $this->assertNotEmpty($attr);
        $this->assertSame('app:backfill-appearances', $attr[0]->newInstance()->name);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lando php vendor/bin/phpunit tests/Command/BackfillAppearancesCommandTest.php --no-coverage`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the command**

`src/Command/BackfillAppearancesCommand.php`:
```php
<?php
namespace App\Command;

use App\Entity\Agent;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\EventSubscriber\AppearanceLifecycleSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:backfill-appearances', description: 'Generate appearance for existing pool rows that lack one')]
final class BackfillAppearancesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppearanceLifecycleSubscriber $filler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ([Player::class, Staff::class, Scout::class, Agent::class] as $class) {
            $rows = $this->em->getRepository($class)->findBy(['appearance' => null]);
            $io->text(sprintf('%s: %d row(s) to backfill', $class, count($rows)));
            $n = 0;
            foreach ($rows as $entity) {
                $this->filler->fill($entity);
                if (++$n % 200 === 0) {
                    $this->em->flush();
                }
            }
            $this->em->flush();
        }

        $io->success('Appearance backfill complete.');
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run unit test to verify it passes**

Run: `lando php vendor/bin/phpunit tests/Command/BackfillAppearancesCommandTest.php --no-coverage`
Expected: PASS.

- [ ] **Step 5: Run the backfill for real and verify**

```bash
lando php bin/console app:backfill-appearances
lando psql -t -c "SELECT count(*) FROM player WHERE appearance IS NULL;"
```
Expected: success message, and `0` null appearances remaining across player (repeat spot-check for staff/scout/agent if desired).

- [ ] **Step 6: Commit**

```bash
git add src/Command/BackfillAppearancesCommand.php tests/Command/BackfillAppearancesCommandTest.php
git commit -m "feat: app:backfill-appearances command for existing pool rows"
```

---

### Task 7: `AppearanceType` custom form + data mapper

**Files:**
- Create: `src/Form/Type/AppearanceType.php`
- Test: `tests/Form/AppearanceTypeTest.php`

**Interfaces:**
- Consumes: enums from Task 1.
- Produces: `AppearanceType` (compound form, `data_class => null`) that maps a `?array` appearance to child fields and back. Child names equal the appearance keys. `accessory` maps `''` (empty choice) ↔ `null`. `jerseyVariant` cast to `int`. Missing/null model → children fall back to sensible defaults (`skinTone #e8c49a`, `hairStyle classic`, `hairColor brown`, `facialHair none`, `faceShape oval`, `eyeShape narrow`, `noseType normal`, `jerseyVariant 1`, `kitTrim #3a8fd4`, `accessory null`).

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace App\Tests\Form;

use App\Form\Type\AppearanceType;
use Symfony\Component\Form\Test\TypeTestCase;

class AppearanceTypeTest extends TypeTestCase
{
    public function testSubmitMapsToAppearanceArray(): void
    {
        $form = $this->factory->create(AppearanceType::class);
        $form->submit([
            'skinTone' => '#dfaa80', 'hairStyle' => 'messy', 'hairColor' => 'dark_brown',
            'accessory' => '', 'kitTrim' => '#3a8fd4', 'facialHair' => 'none',
            'faceShape' => 'oval', 'eyeShape' => 'narrow', 'noseType' => 'normal', 'jerseyVariant' => '2',
        ]);

        $this->assertTrue($form->isSynchronized());
        $data = $form->getData();
        $this->assertNull($data['accessory']);          // '' → null
        $this->assertSame(2, $data['jerseyVariant']);   // '2' → int
        $this->assertSame('messy', $data['hairStyle']);
    }

    public function testPrefillFromModel(): void
    {
        $model = [
            'skinTone' => '#5c2d0a', 'hairStyle' => 'bald', 'hairColor' => 'brown',
            'accessory' => 'glasses', 'kitTrim' => '#d94040', 'facialHair' => 'beard',
            'faceShape' => 'square', 'eyeShape' => 'round', 'noseType' => 'small', 'jerseyVariant' => 3,
        ];
        $form = $this->factory->create(AppearanceType::class, $model);
        $this->assertSame('bald', $form->get('hairStyle')->getData());
        $this->assertSame('glasses', $form->get('accessory')->getData());
        $this->assertSame(3, $form->get('jerseyVariant')->getData());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lando php vendor/bin/phpunit tests/Form/AppearanceTypeTest.php --no-coverage`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the form type**

`src/Form/Type/AppearanceType.php`:
```php
<?php
namespace App\Form\Type;

use App\Enum\Appearance\AvatarAccessory;
use App\Enum\Appearance\EyeShape;
use App\Enum\Appearance\FaceShape;
use App\Enum\Appearance\FacialHair;
use App\Enum\Appearance\HairColor;
use App\Enum\Appearance\HairStyle;
use App\Enum\Appearance\NoseType;
use App\Enum\Appearance\SkinTone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Compound form for editing an Appearance array. Renders per-attribute dropdowns
 * plus a live SVG preview (see templates/admin/field/appearance.html.twig).
 */
final class AppearanceType extends AbstractType implements DataMapperInterface
{
    private const DEFAULTS = [
        'skinTone' => '#e8c49a', 'hairStyle' => 'classic', 'hairColor' => 'brown',
        'accessory' => null, 'kitTrim' => '#3a8fd4', 'facialHair' => 'none',
        'faceShape' => 'oval', 'eyeShape' => 'narrow', 'noseType' => 'normal', 'jerseyVariant' => 1,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('skinTone', ChoiceType::class, [
                'choices' => $this->enumChoices(SkinTone::cases()),
            ])
            ->add('hairStyle', ChoiceType::class, [
                'choices' => $this->enumChoices(HairStyle::cases()),
            ])
            ->add('hairColor', ChoiceType::class, [
                'choices' => $this->enumChoices(HairColor::cases()),
            ])
            ->add('accessory', ChoiceType::class, [
                'required'    => false,
                'placeholder' => 'None',
                'choices'     => $this->enumChoices(AvatarAccessory::cases()),
            ])
            ->add('facialHair', ChoiceType::class, [
                'choices' => $this->enumChoices(FacialHair::cases()),
            ])
            ->add('faceShape', ChoiceType::class, [
                'choices' => $this->enumChoices(FaceShape::cases()),
            ])
            ->add('eyeShape', ChoiceType::class, [
                'choices' => $this->enumChoices(EyeShape::cases()),
            ])
            ->add('noseType', ChoiceType::class, [
                'choices' => $this->enumChoices(NoseType::cases()),
            ])
            ->add('kitTrim', TextType::class)
            ->add('jerseyVariant', IntegerType::class)
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }

    /** @param \BackedEnum[] $cases @return array<string,string> label=>value */
    private function enumChoices(array $cases): array
    {
        $out = [];
        foreach ($cases as $c) {
            $out[ucwords(str_replace('_', ' ', (string) $c->value))] = $c->value;
        }
        return $out;
    }

    /** model array → child forms */
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        $data = is_array($viewData) ? $viewData : [];
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);
        foreach ($forms as $name => $form) {
            $form->setData($data[$name] ?? self::DEFAULTS[$name] ?? null);
        }
    }

    /** child forms → model array */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);
        $result = [];
        foreach ($forms as $name => $form) {
            $result[$name] = $form->getData();
        }
        // Normalise: empty accessory → null; jerseyVariant → int
        $result['accessory']     = $result['accessory'] !== '' && $result['accessory'] !== null ? $result['accessory'] : null;
        $result['jerseyVariant'] = (int) ($result['jerseyVariant'] ?? 1);
        $viewData = $result;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `lando php vendor/bin/phpunit tests/Form/AppearanceTypeTest.php --no-coverage`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Form/Type/AppearanceType.php tests/Form/AppearanceTypeTest.php
git commit -m "feat: AppearanceType admin form with data mapper"
```

---

### Task 8: Admin live preview — compositor asset, form theme, CRUD wiring

This task is browser-verified (Twig + JS), not unit-tested.

**Files:**
- Create: `public/admin/avatar-compositor.js` (adapted copy of the frontend compositor + layers)
- Create: `templates/admin/field/appearance.html.twig`
- Modify: `src/Controller/Admin/PlayerCrudController.php`, `StaffCrudController.php`, `ScoutCrudController.php`

**Interfaces:**
- Consumes: `AppearanceType` (Task 7).
- Produces: an "Appearance" field in the Player/Staff/Scout edit forms whose widget renders the dropdowns plus a live SVG preview.

- [ ] **Step 1: Build the compositor JS asset**

Create `public/admin/avatar-compositor.js` by copying and de-TypeScripting two frontend files:
1. From `wunderkind-app/src/assets/avatarSvgLayers.ts`: copy the exported layer objects (`FACE_LAYERS`, `EYE_LAYERS`, `HAIR_LAYERS`, `MOUTH_LAYERS`, `NOSE_LAYERS`, `BEARD_LAYERS`, `ACCESSORY_LAYERS`, `JERSEY_LAYERS`, `JERSEY_KIT_PLACEHOLDERS`). Remove `export const` → `const`.
2. From `wunderkind-app/src/utils/avatarCompositor.ts`: copy the whole module body. Remove the `import { ... } from '@/assets/avatarSvgLayers'` and `import type ...` lines (types are erased at runtime). Change `export function composeAvatarSvg` → `function composeAvatarSvg`.
3. At the end of the file, expose it globally:
```js
window.composeAvatarSvg = composeAvatarSvg;
```
The compositor is pure string manipulation with no React-Native imports, so it runs unchanged in the browser. Keep both pieces in this single file so the browser loads one script.

- [ ] **Step 2: Create the form theme template**

`templates/admin/field/appearance.html.twig`:
```twig
{# Custom widget for AppearanceType: dropdowns + live SVG preview. #}
<div class="appearance-editor" data-appearance-editor>
    <div class="row">
        <div class="col-md-8">
            <div class="row">
                {% for child in form.children %}
                    <div class="col-md-6 mb-2">
                        {{ form_row(child) }}
                    </div>
                {% endfor %}
            </div>
        </div>
        <div class="col-md-4 text-center">
            <div data-appearance-preview
                 style="width:160px;height:160px;margin:0 auto;background:#fff;border:3px solid #333;"></div>
            <small class="text-muted">Live preview</small>
        </div>
    </div>
</div>

<script src="{{ asset('admin/avatar-compositor.js') }}"></script>
<script>
(function () {
    var root = document.currentScript.closest('[data-appearance-editor]')
        || document.querySelector('[data-appearance-editor]');
    if (!root) return;
    var preview = root.querySelector('[data-appearance-preview]');

    function val(name) {
        var el = root.querySelector('[id$="_' + name + '"]');
        return el ? el.value : '';
    }
    function render() {
        if (typeof window.composeAvatarSvg !== 'function') return;
        var appearance = {
            skinTone: val('skinTone'), hairStyle: val('hairStyle'), hairColor: val('hairColor'),
            accessory: val('accessory') || null, kitTrim: val('kitTrim'), facialHair: val('facialHair'),
            faceShape: val('faceShape'), eyeShape: val('eyeShape'), noseType: val('noseType'),
            jerseyVariant: parseInt(val('jerseyVariant') || '1', 10)
        };
        var kitHex = appearance.kitTrim || '#3a8fd4';
        preview.innerHTML = window.composeAvatarSvg(appearance, 70, kitHex, 160);
    }
    root.addEventListener('change', render);
    root.addEventListener('input', render);
    render();
})();
</script>
```

- [ ] **Step 3: Wire the field into the three CRUD controllers**

In `src/Controller/Admin/PlayerCrudController.php`, add imports at the top:
```php
use App\Form\Type\AppearanceType;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
```
and add to `configureFields()` (inside a new fieldset so it groups nicely on the edit form):
```php
        yield FormField::addFieldset('Appearance', 'fa fa-user-circle')->hideOnIndex();
        yield Field::new('appearance')
            ->setFormType(AppearanceType::class)
            ->setFormTypeOption('mapped', true)
            ->setTemplatePath('admin/field/appearance.html.twig')
            ->onlyOnForms()
            ->setCustomOption('rendered_by_form_theme', false);
```
If EasyAdmin ignores `setTemplatePath` for form widgets (it applies to detail/index rendering), instead register the widget via a form theme: create `templates/admin/form/appearance_theme.html.twig` with a block `{% block app_form_type_appearance_widget %}...{% endblock %}` containing the Step 2 markup, and add `->addFormThemes('admin/form/appearance_theme.html.twig')` in `configureCrud()`. Use whichever mechanism renders the custom widget — verify in Step 5. (EasyAdmin v5: the form-theme block approach is the reliable path for custom compound widgets.)

Repeat the `yield ... Field::new('appearance')...` addition in `StaffCrudController::configureFields()` and `ScoutCrudController::configureFields()`. Add `->addFormThemes(...)` to each controller's `configureCrud()` if using the form-theme approach.

- [ ] **Step 4: Clear cache**

Run: `lando php bin/console cache:clear`
Expected: no errors.

- [ ] **Step 5: Verify in the browser**

Open the admin (dev server / preview) and navigate to a player edit page, e.g.
`https://wunderkind-backend.lndo.site/admin/player/<id>/edit`.
Verify:
- An "Appearance" fieldset renders with dropdowns for skin tone, hair style/color, accessory, facial hair, face/eye/nose shape, kit trim, jersey variant.
- The preview box shows a composed avatar SVG.
- Changing any dropdown updates the preview live (no reload).
- Clicking "Save changes" persists — reopen the page and confirm the selected values are retained.
- Confirm the saved value flows out: `lando psql -t -c "SELECT appearance FROM player WHERE id = '<id>';"` shows the edited JSON.

Repeat a quick check on a Staff and a Scout edit page.

- [ ] **Step 6: Commit**

```bash
git add public/admin/avatar-compositor.js templates/admin src/Controller/Admin/PlayerCrudController.php src/Controller/Admin/StaffCrudController.php src/Controller/Admin/ScoutCrudController.php
git commit -m "feat: admin appearance editor with live avatar preview"
```

---

### Task 9: Full-suite check, docs, PR

**Files:**
- Modify: `CLAUDE.md` (optional — note the appearance system), memory index if desired.

- [ ] **Step 1: Run the full test suite**

Run: `lando php vendor/bin/phpunit --no-coverage`
Expected: all newly added tests pass; only the ~38 known pre-existing unrelated failures remain (compare against a baseline `git stash` run if unsure).

- [ ] **Step 2: Push and open PR**

```bash
git push -u origin HEAD
gh pr create --title "feat: backend-owned player/staff appearance (avatar)" --body "$(cat <<'EOF'
## Summary
- Backend now owns avatar appearance for Player/Staff/Scout/Agent (port of frontend generateAppearance)
- Nullable `appearance` json column per entity; prePersist subscriber auto-fills on any creation path
- Appearance emitted verbatim in player/staff snapshots (worldpack cache, tier/starter packs, market assign) and market serializers — drop-in for the frontend Appearance shape
- Admin edit form: per-attribute dropdowns + live SVG preview reusing the real frontend compositor
- `app:backfill-appearances` command for existing rows

## Frontend transition
Read `payload.appearance` instead of calling `generateAppearance()`. No mapper/compositor changes; existing null-fallback keeps rollout safe both directions.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-Review Notes

- **Spec coverage:** storage (Task 3), enums (Task 1), generator port (Task 2), generation hook — realized as a centralized prePersist subscriber instead of per-site edits (Task 4), serialization across all five sites (Task 5), admin editor + live preview (Tasks 7–8), migration (Task 3) + backfill (Task 6), frontend-transition contract (Global Constraints + emitted shape in Tasks 2/5). Agent edit form intentionally out of scope per spec; Agent still gets generation (Task 4) + serialization (Task 5).
- **Design refinement vs spec:** the spec said "hook every construction site"; the plan uses one Doctrine prePersist subscriber for the same effect with less risk. Documented in Task 4.
- **Type consistency:** `generate(string,$AppearanceRole,int): array`, `fill(object): void`, `getAppearance(): ?array`/`setAppearance(?array)` used consistently across Tasks 2/4/5/6. Appearance keys identical everywhere (10-key list in Global Constraints).
- **Known soft spot:** Task 8 Step 3 offers two EasyAdmin wiring mechanisms because custom compound-widget rendering in EasyAdmin v5 is finicky; the form-theme block approach is called out as the reliable path and verified in Step 5.
