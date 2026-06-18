# Player Generation Pipeline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the inline player generation logic in `MarketPoolService` with a dedicated `PlayerGenerationService` that runs a sequential, potential-anchored pipeline via a `PlayerBlueprint` DTO.

**Architecture:** `PlayerBlueprint` (PHP 8.4 readonly DTO) accumulates state through five sequential private methods. `PlayerGenerationService` returns a fully-populated `Player` entity without persisting it. `MarketPoolService` retains responsibility for agent assignment, guardian generation, wage calculation, and persistence.

**Tech Stack:** PHP 8.4, Symfony 8, PHPUnit

---

## File Map

| Action | File | Responsibility |
|---|---|---|
| Create | `src/Dto/PlayerBlueprint.php` | Immutable pipeline state accumulator |
| Create | `src/Service/PlayerGenerationService.php` | Pipeline orchestrator |
| Create | `tests/Dto/PlayerBlueprintTest.php` | DTO contract tests |
| Create | `tests/Service/PlayerGenerationServiceTest.php` | Pipeline behaviour tests |
| Modify | `src/Service/MarketPoolService.php` | Delegate to `PlayerGenerationService`, delete old inline logic |

---

## Task 1: `PlayerBlueprint` DTO

**Files:**
- Create: `src/Dto/PlayerBlueprint.php`
- Create: `tests/Dto/PlayerBlueprintTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Dto/PlayerBlueprintTest.php
namespace App\Tests\Dto;

use App\Dto\PlayerBlueprint;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use PHPUnit\Framework\TestCase;

class PlayerBlueprintTest extends TestCase
{
    public function testCanConstructWithAnchorsOnly(): void
    {
        $dob = new \DateTimeImmutable('2005-06-15');
        $bp  = new PlayerBlueprint(
            firstName:   'Test',
            lastName:    'Player',
            nationality: 'English',
            age:         19,
            dateOfBirth: $dob,
            height:      180,
            weight:      75,
            position:    PlayerPosition::MIDFIELDER,
            potential:   70,
            source:      RecruitmentSource::SCOUTING_NETWORK,
        );

        $this->assertSame('Test', $bp->firstName);
        $this->assertSame('Player', $bp->lastName);
        $this->assertSame('English', $bp->nationality);
        $this->assertSame(19, $bp->age);
        $this->assertSame($dob, $bp->dateOfBirth);
        $this->assertSame(180, $bp->height);
        $this->assertSame(75, $bp->weight);
        $this->assertSame(PlayerPosition::MIDFIELDER, $bp->position);
        $this->assertSame(70, $bp->potential);
        $this->assertSame(0.0, $bp->abilityTarget);
        $this->assertFalse($bp->isProdigy);
        $this->assertSame(0, $bp->determination);
        $this->assertSame(0, $bp->pace);
        $this->assertSame(0, $bp->currentAbility);
    }

    public function testCanEnrichWithNamedArgumentSpread(): void
    {
        $bp       = $this->makeBlueprint();
        $enriched = new PlayerBlueprint(...(array) $bp, abilityTarget: 0.55, isProdigy: false);

        $this->assertSame(0.55, $enriched->abilityTarget);
        $this->assertSame($bp->firstName, $enriched->firstName);
        $this->assertSame($bp->potential, $enriched->potential);
    }

    private function makeBlueprint(): PlayerBlueprint
    {
        return new PlayerBlueprint(
            firstName:   'A',
            lastName:    'B',
            nationality: 'Spanish',
            age:         20,
            dateOfBirth: new \DateTimeImmutable('2004-01-01'),
            height:      175,
            weight:      70,
            position:    PlayerPosition::ATTACKER,
            potential:   80,
            source:      RecruitmentSource::SCOUTING_NETWORK,
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
lando php vendor/bin/phpunit tests/Dto/PlayerBlueprintTest.php
```
Expected: FAIL — `Class "App\Dto\PlayerBlueprint" not found`

- [ ] **Step 3: Create the DTO**

```php
<?php
// src/Dto/PlayerBlueprint.php
namespace App\Dto;

use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;

readonly class PlayerBlueprint
{
    public function __construct(
        // ── Anchors ───────────────────────────────────────────────────────────
        public string             $firstName,
        public string             $lastName,
        public string             $nationality,
        public int                $age,
        public \DateTimeImmutable $dateOfBirth,
        public int                $height,
        public int                $weight,
        public PlayerPosition     $position,
        public int                $potential,
        public RecruitmentSource  $source,

        // ── Step 2: ability target ────────────────────────────────────────────
        public float $abilityTarget  = 0.0,
        public bool  $isProdigy      = false,

        // ── Step 3: personality (1–20) ────────────────────────────────────────
        public int $determination    = 0,
        public int $professionalism  = 0,
        public int $ambition         = 0,
        public int $loyalty          = 0,
        public int $adaptability     = 0,
        public int $pressure         = 0,
        public int $temperament      = 0,
        public int $consistency      = 0,

        // ── Step 4: attributes (1–100) ────────────────────────────────────────
        public int $pace             = 0,
        public int $technical        = 0,
        public int $vision           = 0,
        public int $power            = 0,
        public int $stamina          = 0,
        public int $heart            = 0,
        public int $currentAbility   = 0,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
lando php vendor/bin/phpunit tests/Dto/PlayerBlueprintTest.php
```
Expected: OK (2 tests, 14 assertions)

- [ ] **Step 5: Commit**

```bash
git add src/Dto/PlayerBlueprint.php tests/Dto/PlayerBlueprintTest.php
git commit -m "feat: add PlayerBlueprint readonly DTO"
```

---

## Task 2: Service skeleton + `buildEntity()`

Sets up the service with stub private methods so `generate()` is callable throughout TDD. `buildEntity()` is fully implemented here since it's pure mapping.

**Files:**
- Create: `src/Service/PlayerGenerationService.php`
- Create: `tests/Service/PlayerGenerationServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Service/PlayerGenerationServiceTest.php
namespace App\Tests\Service;

use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Service\NameGeneratorService;
use App\Service\PlayerGenerationService;
use PHPUnit\Framework\TestCase;

class PlayerGenerationServiceTest extends TestCase
{
    private function makeService(): PlayerGenerationService
    {
        $nameGen = $this->createMock(NameGeneratorService::class);
        $nameGen->method('getRandomNationality')->willReturn('English');
        $nameGen->method('generatePlayerName')->willReturn([
            'firstName' => 'Test',
            'lastName'  => 'Player',
        ]);
        return new PlayerGenerationService($nameGen);
    }

    public function testGenerateReturnsPlayerInstance(): void
    {
        $player = $this->makeService()->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
        $this->assertInstanceOf(Player::class, $player);
    }

    public function testGeneratedPlayerHasCorrectPosition(): void
    {
        $player = $this->makeService()->generate(PlayerPosition::GOALKEEPER, RecruitmentSource::SCOUTING_NETWORK);
        $this->assertSame(PlayerPosition::GOALKEEPER, $player->getPosition());
    }

    public function testGeneratedPlayerHasCorrectRecruitmentSource(): void
    {
        $player = $this->makeService()->generate(PlayerPosition::ATTACKER, RecruitmentSource::AGENT_OFFER);
        $this->assertSame(RecruitmentSource::AGENT_OFFER, $player->getRecruitmentSource());
    }

    public function testGeneratedPlayerHasNameFromNameGenerator(): void
    {
        $player = $this->makeService()->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
        $this->assertSame('Test', $player->getFirstName());
        $this->assertSame('Player', $player->getLastName());
        $this->assertSame('English', $player->getNationality());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
lando php vendor/bin/phpunit tests/Service/PlayerGenerationServiceTest.php
```
Expected: FAIL — `Class "App\Service\PlayerGenerationService" not found`

- [ ] **Step 3: Create the service with stubs and full `buildEntity()`**

```php
<?php
// src/Service/PlayerGenerationService.php
namespace App\Service;

use App\Dto\PlayerBlueprint;
use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;

class PlayerGenerationService
{
    // Position-attribute fraction ranges: [minFraction, maxFraction] of the ability cap
    private const POSITION_ATTRIBUTE_FRACTIONS = [
        'GK'  => ['pace' => [0.0, 0.30], 'technical' => [0.30, 0.70], 'vision' => [0.60, 1.0]],
        'DEF' => ['pace' => [0.50, 1.0],  'technical' => [0.0,  0.40], 'vision' => [0.0,  0.30]],
        'MID' => ['pace' => [0.30, 0.70], 'technical' => [0.50, 1.0],  'vision' => [0.50, 1.0]],
        'ATT' => ['pace' => [0.40, 1.0],  'technical' => [0.50, 1.0],  'vision' => [0.50, 1.0]],
    ];

    public function __construct(
        private readonly NameGeneratorService $nameGenerator,
    ) {}

    public function generate(PlayerPosition $position, RecruitmentSource $source, ?string $nationality = null): Player
    {
        $blueprint = $this->buildAnchors($position, $source, $nationality);
        $blueprint = $this->buildAbilityTarget($blueprint);
        $blueprint = $this->buildPersonality($blueprint);
        $blueprint = $this->buildAttributes($blueprint);
        return $this->buildEntity($blueprint);
    }

    // ── Stub implementations (replaced in subsequent tasks) ──────────────────

    private function buildAnchors(PlayerPosition $position, RecruitmentSource $source, ?string $nationality): PlayerBlueprint
    {
        $nat = $nationality ?? $this->nameGenerator->getRandomNationality();
        ['firstName' => $firstName, 'lastName' => $lastName] = $this->nameGenerator->generatePlayerName($nat);

        return new PlayerBlueprint(
            firstName:   $firstName,
            lastName:    $lastName,
            nationality: $nat,
            age:         20,
            dateOfBirth: new \DateTimeImmutable('2004-01-01'),
            height:      175,
            weight:      70,
            position:    $position,
            potential:   0,
            source:      $source,
        );
    }

    private function buildAbilityTarget(PlayerBlueprint $bp): PlayerBlueprint
    {
        return new PlayerBlueprint(...(array) $bp, abilityTarget: 0.5, isProdigy: false);
    }

    private function buildPersonality(PlayerBlueprint $bp): PlayerBlueprint
    {
        return new PlayerBlueprint(...(array) $bp,
            determination:  1, professionalism: 1, ambition:    1, loyalty:  1,
            adaptability:   1, pressure:        1, temperament: 1, consistency: 1,
        );
    }

    private function buildAttributes(PlayerBlueprint $bp): PlayerBlueprint
    {
        return new PlayerBlueprint(...(array) $bp,
            pace: 1, technical: 1, vision: 1, power: 1, stamina: 1, heart: 1, currentAbility: 1,
        );
    }

    // ── Fully implemented: pure mapping, no randomness ───────────────────────

    private function buildEntity(PlayerBlueprint $bp): Player
    {
        $player = new Player(
            firstName:         $bp->firstName,
            lastName:          $bp->lastName,
            dateOfBirth:       $bp->dateOfBirth,
            nationality:       $bp->nationality,
            position:          $bp->position,
            recruitmentSource: $bp->source,
            potential:         $bp->potential,
            currentAbility:    $bp->currentAbility,
            club:              null,
        );

        $player->setHeight($bp->height);
        $player->setWeight($bp->weight);
        $player->setPace($bp->pace);
        $player->setTechnical($bp->technical);
        $player->setVision($bp->vision);
        $player->setPower($bp->power);
        $player->setStamina($bp->stamina);
        $player->setHeart($bp->heart);

        $p = $player->getPersonality();
        $p->setDetermination($bp->determination);
        $p->setProfessionalism($bp->professionalism);
        $p->setAmbition($bp->ambition);
        $p->setLoyalty($bp->loyalty);
        $p->setAdaptability($bp->adaptability);
        $p->setPressure($bp->pressure);
        $p->setTemperament($bp->temperament);
        $p->setConsistency($bp->consistency);

        return $player;
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function normalise(int $value, int $min, int $max): float
    {
        return ($value - $min) / ($max - $min);
    }

    private function randFloat(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }

    private function randTrait(float $minPct, float $maxPct): int
    {
        $pct = $this->randFloat($minPct, $maxPct);
        return max(1, min(20, (int) ceil(20.0 * $pct)));
    }

    private function randInCap(int $cap, float $minFrac, float $maxFrac): int
    {
        $lo = max(1, (int) floor($cap * $minFrac));
        $hi = max($lo, (int) ceil($cap * $maxFrac));
        return random_int($lo, $hi);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
lando php vendor/bin/phpunit tests/Service/PlayerGenerationServiceTest.php
```
Expected: OK (4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Service/PlayerGenerationService.php tests/Service/PlayerGenerationServiceTest.php
git commit -m "feat: add PlayerGenerationService skeleton with stub pipeline and full buildEntity()"
```

---

## Task 3: Implement `buildAnchors()`

**Files:**
- Modify: `src/Service/PlayerGenerationService.php`
- Modify: `tests/Service/PlayerGenerationServiceTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/Service/PlayerGenerationServiceTest.php`:

```php
    public function testPotentialIsWithinRange(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $this->assertGreaterThanOrEqual(1, $player->getPotential());
            $this->assertLessThanOrEqual(100, $player->getPotential());
        }
    }

    public function testAgeIsWithinRange(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::DEFENDER, RecruitmentSource::SCOUTING_NETWORK);
            $age    = (int) $player->getDateOfBirth()->diff(new \DateTimeImmutable())->y;
            $this->assertGreaterThanOrEqual(16, $age);
            $this->assertLessThanOrEqual(33, $age);
        }
    }

    public function testHeightIsWithinRange(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $this->assertGreaterThanOrEqual(163, $player->getHeight());
            $this->assertLessThanOrEqual(211, $player->getHeight());
        }
    }

    public function testGoalkeeperReceivesHeightBias(): void
    {
        $svc     = $this->makeService();
        $gkHeights  = [];
        $midHeights = [];
        for ($i = 0; $i < 50; $i++) {
            $gkHeights[]  = $svc->generate(PlayerPosition::GOALKEEPER, RecruitmentSource::SCOUTING_NETWORK)->getHeight();
            $midHeights[] = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK)->getHeight();
        }
        $this->assertGreaterThan(array_sum($midHeights) / count($midHeights),
                                 array_sum($gkHeights)  / count($gkHeights),
                                 'GK average height should exceed MID average height');
    }

    public function testWeightIsWithinRange(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::GOALKEEPER, RecruitmentSource::SCOUTING_NETWORK);
            $this->assertGreaterThanOrEqual(60, $player->getWeight());
            $this->assertLessThanOrEqual(97, $player->getWeight());
        }
    }

    public function testForcedNationalityIsRespected(): void
    {
        $nameGen = $this->createMock(NameGeneratorService::class);
        $nameGen->method('getRandomNationality')->willReturn('English');
        $nameGen->method('generatePlayerName')->willReturn(['firstName' => 'Carlos', 'lastName' => 'Ruiz']);
        $svc = new PlayerGenerationService($nameGen);

        $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK, 'Spanish');
        $this->assertSame('Spanish', $player->getNationality());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
lando php vendor/bin/phpunit tests/Service/PlayerGenerationServiceTest.php
```
Expected: FAIL on potential (stub returns 0), age (stub returns fixed 2004 DOB = age 21, passes by accident — but height/weight stubs are wrong)

- [ ] **Step 3: Replace stub `buildAnchors()`**

Replace the `buildAnchors()` method in `src/Service/PlayerGenerationService.php`:

```php
    private function buildAnchors(PlayerPosition $position, RecruitmentSource $source, ?string $nationality): PlayerBlueprint
    {
        $nat = $nationality ?? $this->nameGenerator->getRandomNationality();
        ['firstName' => $firstName, 'lastName' => $lastName] = $this->nameGenerator->generatePlayerName($nat);

        $age       = random_int(16, 33);
        $potential = random_int(1, 100);

        // Height: base range 163–203 cm; GKs receive an additional 3–8 cm upward bias
        $baseHeight = random_int(163, 203);
        $height     = ($position === PlayerPosition::GOALKEEPER)
            ? min(211, $baseHeight + random_int(3, 8))
            : $baseHeight;

        // Weight: base 60–82 kg, correlated upward with height (up to +15 kg)
        $baseWeight  = random_int(60, 82);
        $heightBonus = (int) floor($this->normalise($height, 163, 211) * 15);
        $weight      = min(97, $baseWeight + $heightBonus);

        $year = (int) date('Y') - $age;
        $dob  = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, random_int(1, 12), random_int(1, 28)));

        return new PlayerBlueprint(
            firstName:   $firstName,
            lastName:    $lastName,
            nationality: $nat,
            age:         $age,
            dateOfBirth: $dob,
            height:      $height,
            weight:      $weight,
            position:    $position,
            potential:   $potential,
            source:      $source,
        );
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
lando php vendor/bin/phpunit tests/Service/PlayerGenerationServiceTest.php
```
Expected: OK (all tests pass)

- [ ] **Step 5: Commit**

```bash
git add src/Service/PlayerGenerationService.php tests/Service/PlayerGenerationServiceTest.php
git commit -m "feat: implement buildAnchors() with age, potential, position-biased height/weight"
```

---

## Task 4: Implement `buildAbilityTarget()`

**Files:**
- Modify: `src/Service/PlayerGenerationService.php`
- Modify: `tests/Service/PlayerGenerationServiceTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/Service/PlayerGenerationServiceTest.php`:

```php
    public function testCurrentAbilityNeverExceedsPotential(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 50; $i++) {
            $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $this->assertLessThanOrEqual(
                $player->getPotential(),
                $player->getCurrentAbility(),
                "currentAbility {$player->getCurrentAbility()} must not exceed potential {$player->getPotential()}"
            );
        }
    }

    public function testYoungPlayersHaveLowerAbilityRatio(): void
    {
        // Players aged 16–21 should average <= 60% of their potential for currentAbility
        // (ability target caps at 60% for youth bracket)
        $svc   = $this->makeService();
        $ratios = [];
        for ($i = 0; $i < 100; $i++) {
            $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $age    = (int) $player->getDateOfBirth()->diff(new \DateTimeImmutable())->y;
            if ($age >= 16 && $age <= 21 && $player->getPotential() > 0) {
                $ratios[] = $player->getCurrentAbility() / $player->getPotential();
            }
        }
        if (count($ratios) >= 5) {
            $avgRatio = array_sum($ratios) / count($ratios);
            $this->assertLessThanOrEqual(0.65, $avgRatio,
                'Youth players (16–21) should average <= 65% ability/potential ratio');
        } else {
            $this->markTestSkipped('Too few youth players generated; re-run for a statistically meaningful sample.');
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
lando php vendor/bin/phpunit tests/Service/PlayerGenerationServiceTest.php
```
Expected: `testCurrentAbilityNeverExceedsPotential` FAIL (stub potential=0, currentAbility=1 → 1 > 0)

- [ ] **Step 3: Replace stub `buildAbilityTarget()`**

Replace the `buildAbilityTarget()` method in `src/Service/PlayerGenerationService.php`:

```php
    private function buildAbilityTarget(PlayerBlueprint $bp): PlayerBlueprint
    {
        $age       = $bp->age;
        $isProdigy = false;

        if ($age >= 18 && $age <= 23 && random_int(1, 100) <= 5) {
            $isProdigy     = true;
            $abilityTarget = $this->randFloat(0.85, 0.95);
        } elseif ($age <= 21) {
            $abilityTarget = $this->randFloat(0.30, 0.60);
        } elseif ($age <= 25) {
            $abilityTarget = $this->randFloat(0.60, 0.85);
        } else {
            $abilityTarget = $this->randFloat(0.85, 1.00);
        }

        return new PlayerBlueprint(...(array) $bp, abilityTarget: $abilityTarget, isProdigy: $isProdigy);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
lando php vendor/bin/phpunit tests/Service/PlayerGenerationServiceTest.php
```
Expected: OK (all tests — note `testCurrentAbilityNeverExceedsPotential` will properly pass once `buildAttributes()` is implemented in Task 6; for now the stub returns currentAbility=1 which satisfies the constraint)

- [ ] **Step 5: Commit**

```bash
git add src/Service/PlayerGenerationService.php tests/Service/PlayerGenerationServiceTest.php
git commit -m "feat: implement buildAbilityTarget() with age brackets and 5% prodigy exception"
```

---

## Task 5: Implement `buildPersonality()`

**Files:**
- Modify: `src/Service/PlayerGenerationService.php`
- Modify: `tests/Service/PlayerGenerationServiceTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/Service/PlayerGenerationServiceTest.php`:

```php
    public function testAllPersonalityTraitsAreWithin1To20(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $p      = $player->getPersonality();
            foreach ([
                $p->getDetermination(), $p->getProfessionalism(), $p->getAmbition(),
                $p->getLoyalty(), $p->getAdaptability(), $p->getPressure(),
                $p->getTemperament(), $p->getConsistency(),
            ] as $trait) {
                $this->assertGreaterThanOrEqual(1, $trait);
                $this->assertLessThanOrEqual(20, $trait);
            }
        }
    }

    public function testPersonalityTraitsCeilingRespectsPotential(): void
    {
        // Each trait must be <= ceil(20 * potential/100)
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $p      = $player->getPersonality();
            $maxTrait = (int) ceil(20 * $player->getPotential() / 100);
            foreach ([
                $p->getDetermination(), $p->getProfessionalism(), $p->getAmbition(),
                $p->getLoyalty(), $p->getAdaptability(), $p->getPressure(),
                $p->getTemperament(), $p->getConsistency(),
            ] as $trait) {
                $this->assertLessThanOrEqual(
                    $maxTrait, $trait,
                    "Trait {$trait} must not exceed ceil(20 * {$player->getPotential()} / 100) = {$maxTrait}"
                );
            }
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
lando php vendor/bin/phpunit tests/Service/PlayerGenerationServiceTest.php
```
Expected: `testPersonalityTraitsCeilingRespectsPotential` FAIL (stub returns all traits=1 which satisfies the range, but once potential is 0 from stub... actually with Task 3 implemented, potential is 1-100 and traits stub=1 which is always <= ceiling. The test may pass with stubs, but once real buildPersonality runs, it enforces the ceiling.) Accept this and move to implementation.

- [ ] **Step 3: Replace stub `buildPersonality()`**

Replace the `buildPersonality()` method in `src/Service/PlayerGenerationService.php`:

```php
    private function buildPersonality(PlayerBlueprint $bp): PlayerBlueprint
    {
        $maxPct = $bp->potential / 100.0;
        $minPct = max(0.0, ($bp->potential - 30) / 100.0);

        return new PlayerBlueprint(...(array) $bp,
            determination:  $this->randTrait($minPct, $maxPct),
            professionalism: $this->randTrait($minPct, $maxPct),
            ambition:       $this->randTrait($minPct, $maxPct),
            loyalty:        $this->randTrait($minPct, $maxPct),
            adaptability:   $this->randTrait($minPct, $maxPct),
            pressure:       $this->randTrait($minPct, $maxPct),
            temperament:    $this->randTrait($minPct, $maxPct),
            consistency:    $this->randTrait($minPct, $maxPct),
        );
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
lando php vendor/bin/phpunit tests/Service/PlayerGenerationServiceTest.php
```
Expected: OK (all tests)

- [ ] **Step 5: Commit**

```bash
git add src/Service/PlayerGenerationService.php tests/Service/PlayerGenerationServiceTest.php
git commit -m "feat: implement buildPersonality() with potential-anchored 1-20 trait ranges"
```

---

## Task 6: Implement `buildAttributes()`

**Files:**
- Modify: `src/Service/PlayerGenerationService.php`
- Modify: `tests/Service/PlayerGenerationServiceTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/Service/PlayerGenerationServiceTest.php`:

```php
    public function testCurrentAbilityIsAverageOfSixAttributes(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player   = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $expected = (int) round((
                $player->getPace() + $player->getTechnical() + $player->getVision() +
                $player->getPower() + $player->getStamina() + $player->getHeart()
            ) / 6);
            $this->assertSame($expected, $player->getCurrentAbility(),
                'currentAbility must equal round(sum of 6 attributes / 6)');
        }
    }

    public function testAllAttributesAreAtLeastOne(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::ATTACKER, RecruitmentSource::SCOUTING_NETWORK);
            $this->assertGreaterThanOrEqual(1, $player->getPace());
            $this->assertGreaterThanOrEqual(1, $player->getTechnical());
            $this->assertGreaterThanOrEqual(1, $player->getVision());
            $this->assertGreaterThanOrEqual(1, $player->getPower());
            $this->assertGreaterThanOrEqual(1, $player->getStamina());
            $this->assertGreaterThanOrEqual(1, $player->getHeart());
        }
    }

    public function testAllAttributesAreAtMost100(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::GOALKEEPER, RecruitmentSource::SCOUTING_NETWORK);
            $this->assertLessThanOrEqual(100, $player->getPace());
            $this->assertLessThanOrEqual(100, $player->getTechnical());
            $this->assertLessThanOrEqual(100, $player->getVision());
            $this->assertLessThanOrEqual(100, $player->getPower());
            $this->assertLessThanOrEqual(100, $player->getStamina());
            $this->assertLessThanOrEqual(100, $player->getHeart());
        }
    }

    public function testAttackerPaceAveragesHigherThanGkPace(): void
    {
        $svc     = $this->makeService();
        $attPace = [];
        $gkPace  = [];
        for ($i = 0; $i < 60; $i++) {
            $attPace[] = $svc->generate(PlayerPosition::ATTACKER,   RecruitmentSource::SCOUTING_NETWORK)->getPace();
            $gkPace[]  = $svc->generate(PlayerPosition::GOALKEEPER, RecruitmentSource::SCOUTING_NETWORK)->getPace();
        }
        $this->assertGreaterThan(
            array_sum($gkPace) / count($gkPace),
            array_sum($attPace) / count($attPace),
            'ATT average pace should exceed GK average pace'
        );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
lando php vendor/bin/phpunit tests/Service/PlayerGenerationServiceTest.php
```
Expected: `testCurrentAbilityIsAverageOfSixAttributes` FAIL (stub sets all attrs=1, currentAbility=1 — actually passes with stubs); `testAttackerPaceAveragesHigherThanGkPace` FAIL (stub returns pace=1 for all positions; both averages equal 1.0, assertGreaterThan fails).

- [ ] **Step 3: Replace stub `buildAttributes()`**

Replace the `buildAttributes()` method in `src/Service/PlayerGenerationService.php`:

```php
    private function buildAttributes(PlayerBlueprint $bp): PlayerBlueprint
    {
        $cap    = max(1, (int) floor($bp->abilityTarget * $bp->potential));
        $posKey = $bp->position->value;
        $fracs  = self::POSITION_ATTRIBUTE_FRACTIONS[$posKey] ?? self::POSITION_ATTRIBUTE_FRACTIONS['MID'];

        $pace      = $this->randInCap($cap, $fracs['pace'][0], $fracs['pace'][1]);
        $technical = $this->randInCap($cap, $fracs['technical'][0], $fracs['technical'][1]);
        $vision    = $this->randInCap($cap, $fracs['vision'][0], $fracs['vision'][1]);

        // Power: physical anchor (height + weight) + personality uplift (determination + professionalism)
        $physBase   = ($this->normalise($bp->height, 163, 211) + $this->normalise($bp->weight, 60, 97)) / 2.0;
        $physMod    = ($bp->determination + $bp->professionalism) / 40.0;
        $power      = min($cap, max(1, (int) ceil(($physBase * 0.6 + $physMod * 0.4) * 100)));

        // Stamina: lean/fit bias + mental fortitude (determination + pressure + temperament)
        $stamPhys   = ((1.0 - $this->normalise($bp->weight, 60, 97)) * 0.5 + $this->normalise($bp->height, 163, 211) * 0.5);
        $stamMod    = ($bp->determination + $bp->pressure + $bp->temperament) / 60.0;
        $stamina    = min($cap, max(1, (int) ceil(($stamPhys * 0.5 + $stamMod * 0.5) * 100)));

        // Heart: loyalty + determination + pressure scaled to 100, capped
        $heart = min($cap, max(1, (int) round(($bp->loyalty + $bp->determination + $bp->pressure) / 60.0 * 100)));

        $currentAbility = (int) round(($pace + $technical + $vision + $power + $stamina + $heart) / 6);

        return new PlayerBlueprint(...(array) $bp,
            pace:           $pace,
            technical:      $technical,
            vision:         $vision,
            power:          $power,
            stamina:        $stamina,
            heart:          $heart,
            currentAbility: $currentAbility,
        );
    }
```

- [ ] **Step 4: Run the full test suite**

```bash
lando php vendor/bin/phpunit tests/Dto/PlayerBlueprintTest.php tests/Service/PlayerGenerationServiceTest.php
```
Expected: OK (all tests pass)

- [ ] **Step 5: Commit**

```bash
git add src/Service/PlayerGenerationService.php tests/Service/PlayerGenerationServiceTest.php
git commit -m "feat: implement buildAttributes() with position weighting, derived Power/Stamina/Heart"
```

---

## Task 7: Wire `MarketPoolService` and delete old inline logic

**Files:**
- Modify: `src/Service/MarketPoolService.php`

- [ ] **Step 1: Inject `PlayerGenerationService` into `MarketPoolService`**

In `src/Service/MarketPoolService.php`, add the dependency to the constructor (Symfony autowires it automatically):

```php
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly PlayerRepository        $playerRepo,
        private readonly StaffRepository         $staffRepo,
        private readonly ScoutRepository         $scoutRepo,
        private readonly AgentRepository         $agentRepo,
        private readonly SponsorRepository       $sponsorRepo,
        private readonly InvestorRepository      $investorRepo,
        private readonly NameGeneratorService    $nameGenerator,
        private readonly PoolConfigRepository    $poolConfigRepo,
        private readonly \App\Repository\GameConfigRepository $gameConfigRepo,
        private readonly PlayerGenerationService $playerGen,   // ← add this
    ) {}
```

- [ ] **Step 2: Replace the player generation block inside `generatePlayers()`**

Locate the `for` loop in `generatePlayers()`. Replace everything from `$potential = ...` down to `$player->setHeight(...)` / `$player->setWeight(...)` / personality block with a single `generate()` call. Keep agent assignment, guardian generation, wage calculation, and persistence unchanged.

The loop body becomes:

```php
        for ($i = 0; $i < $count; $i++) {
            $position = $this->weightedPosition($cfg);
            $player   = $this->playerGen->generate($position, $source, $nationality);

            $player->setStatus(PlayerStatus::ACTIVE);

            $gc          = $this->gameConfigRepo->getConfig();
            $multipliers = $this->getWageMultiplier($player->getCurrentAbility());
            $baseWage    = $player->getCurrentAbility() * random_int($gc->getContractValueRandMin(), $gc->getContractValueRandMax());
            $player->setContractValue((int) ($baseWage * $multipliers['player']));

            if (!empty($agents) && random_int(1, 100) <= $cfg->getPlayerAgentChancePercent()) {
                $player->setAgent($agents[array_rand($agents)]);
            }

            // Guardian generation
            $lastName = $player->getLastName();
            $nat      = $player->getNationality();

            $roll = random_int(1, 100);
            if ($roll <= 80) {
                $genderPair = ['male', 'female'];
            } elseif ($roll <= 90) {
                $genderPair = [random_int(0, 1) === 0 ? 'male' : 'female'];
            } else {
                $sameGender = random_int(0, 1) === 0 ? 'male' : 'female';
                $genderPair = [$sameGender, $sameGender];
            }

            $guardianLastName = $lastName !== ''
                ? $lastName
                : $this->nameGenerator->generateLastName($nat);

            foreach ($genderPair as $guardianGender) {
                $guardianFirstName = $this->nameGenerator->generateFirstName($nat);
                $guardian          = new Guardian($guardianFirstName, $guardianLastName, $player, $guardianGender);
                $guardian->setDateOfBirth($this->dobFromAge(random_int(30, 55)));
                $guardian->setDemandLevel(random_int(1, 10));
                $guardian->setLoyaltyToClub(random_int(30, 80));
                $this->em->persist($guardian);
            }

            $this->em->persist($player);
            $players[] = $player;

            if ($i > 0 && $i % 50 === 0) {
                $this->em->flush();
                $this->em->clear(Player::class);
                $agents = $this->agentRepo->findAll();
            }
        }
```

- [ ] **Step 3: Run all service tests**

```bash
lando php vendor/bin/phpunit tests/
```
Expected: OK — no test failures introduced by the wiring change

- [ ] **Step 4: Smoke-test player generation via console**

```bash
lando php bin/console app:warm-pool --players=5
```
Expected: Command completes without error; 5 new players visible in admin at `/admin?crudAction=index&crudControllerFqcn=App%5CController%5CAdmin%5CPlayerCrudController`

- [ ] **Step 5: Commit**

```bash
git add src/Service/MarketPoolService.php
git commit -m "feat: wire MarketPoolService to delegate player generation to PlayerGenerationService"
```

- [ ] **Step 6: Open PR**

```bash
git push -u origin HEAD
gh pr create \
  --title "feat: player generation pipeline (PlayerGenerationService)" \
  --body "$(cat <<'EOF'
## Summary
- Adds `PlayerBlueprint` readonly DTO as immutable pipeline state accumulator
- Adds `PlayerGenerationService` with sequential steps: anchors → ability target → personality → attributes → entity
- Ability target is age-bracketed (youth 30–60%, developing 60–85%, prime 85–100%) with 5% prodigy exception
- Personality traits are potential-anchored (1–20 scale, ceil-rounded) 
- Power/Stamina derived from height/weight + personality traits; Heart from loyalty/determination/pressure
- MarketPoolService delegates to new service; old inline logic removed
- All tests passing

## Test plan
- [ ] `lando php vendor/bin/phpunit tests/Dto/PlayerBlueprintTest.php` — passes
- [ ] `lando php vendor/bin/phpunit tests/Service/PlayerGenerationServiceTest.php` — passes
- [ ] `lando php bin/console app:warm-pool --players=10` — generates 10 players without error
- [ ] `/admin` → Players — verify CA, PA, height, weight, personality values look correct

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```
