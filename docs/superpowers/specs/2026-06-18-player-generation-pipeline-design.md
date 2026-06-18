# Player Generation Pipeline — Design Spec
**Date:** 2026-06-18
**Status:** Approved

## Overview

Replace the inline player generation logic in `MarketPoolService` with a dedicated `PlayerGenerationService` that implements a sequential, data-driven pipeline. A readonly `PlayerBlueprint` DTO accumulates state through each step; the final step converts it to a `Player` entity.

---

## Files

| File | Change |
|---|---|
| `src/Dto/PlayerBlueprint.php` | New — readonly DTO, accumulates pipeline state |
| `src/Service/PlayerGenerationService.php` | New — orchestrates the pipeline |
| `src/Service/MarketPoolService.php` | Changed — `generatePlayers()` delegates to `PlayerGenerationService`; old inline logic deleted |

No entity changes, no migrations, no new routes.

---

## PlayerBlueprint DTO

Readonly PHP 8.4 class. Each pipeline step returns a new instance with additional fields populated using named argument spread. Defaults of `0` / `0.0` / `false` signal "not yet computed."

```php
readonly class PlayerBlueprint
{
    public function __construct(
        // Anchors
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

        // Ability target (Step 2)
        public float $abilityTarget = 0.0,
        public bool  $isProdigy     = false,

        // Personality (Step 3)
        public int $determination   = 0,
        public int $professionalism  = 0,
        public int $ambition        = 0,
        public int $loyalty         = 0,
        public int $adaptability    = 0,
        public int $pressure        = 0,
        public int $temperament     = 0,
        public int $consistency     = 0,

        // Attributes (Step 4)
        public int $pace            = 0,
        public int $technical       = 0,
        public int $vision          = 0,
        public int $power           = 0,
        public int $stamina         = 0,
        public int $heart           = 0,
        public int $currentAbility  = 0,
    ) {}
}
```

---

## Pipeline

### Public API

```php
public function generate(PlayerPosition $position, RecruitmentSource $source): Player
```

`PlayerPosition` and `RecruitmentSource` are passed in by the caller — `MarketPoolService` owns the distribution weights (GK 8% / DEF 30% / MID 38% / ATT 24%).

### Step 1 — `buildAnchors()` → PlayerBlueprint

Generates all biographical and physical values before any calculations run.

| Field | Logic |
|---|---|
| Age | `random_int(16, 33)` |
| DOB | `today - age years - random_int(0, 364) days` |
| Potential | `random_int(1, 100)` |
| Height | `random_int(163, 203)` cm — GKs receive a +3–8 cm upward bias |
| Weight | `random_int(60, 97)` kg — correlated upward bias for taller heights |
| Name | Drawn from internal name pool constants (by nationality) |
| Nationality | Drawn from nationality pool |

Name and nationality pools are extracted from `MarketPoolService` as private constants on `PlayerGenerationService`.

### Step 2 — `buildAbilityTarget()` → PlayerBlueprint

Determines how close to potential the player currently is, based on age.

```
Age 16–21  → abilityTarget ∈ [0.30, 0.60]
Age 22–25  → abilityTarget ∈ [0.60, 0.85]
Age 26+    → abilityTarget ∈ [0.85, 1.00]

Prodigy exception (ages 18–23, 5% chance):
  → overrides bracket → abilityTarget ∈ [0.85, 0.95]
  → isProdigy = true
```

`abilityTarget` is a random float within the bracket (using `lcg_value()` or equivalent).

**Attribute ceiling** used in Step 4: `$cap = max(1, (int) floor($abilityTarget * $potential))`

### Step 3 — `buildPersonality()` → PlayerBlueprint

Potential sets the min–max percentage range for all 8 traits on the 1–20 scale.

```
maxPct = potential / 100
minPct = max(0, potential - 30) / 100

trait = (int) ceil(random_float(minPct, maxPct) * 20)
```

All 8 traits use `ceil()` rounding. Each trait is independently randomised within the same range.

**Examples:**
- Potential 70 → traits in `[ceil(8.0), ceil(14.0)]` = `[8, 14]`
- Potential 95 → traits in `[ceil(13.0), ceil(19.0)]` = `[13, 19]`
- Potential 30 → traits in `[ceil(0.0), ceil(6.0)]` = `[1, 6]`

### Step 4 — `buildAttributes()` → PlayerBlueprint

`$cap = max(1, (int) floor($bp->abilityTarget * $bp->potential))`

#### Pace, Technical, Vision — position-weighted random within [1, $cap]

| Position | Pace | Technical | Vision |
|---|---|---|---|
| GK | Low (bottom 30%) | Medium (middle 40%) | High (top 40%) |
| DEF | Medium-high (top 50%) | Low-medium (bottom 40%) | Low (bottom 30%) |
| MID | Medium (middle 40%) | High (top 50%) | High (top 50%) |
| ATT | High (top 60%) | Medium-high (top 50%) | Medium-high (top 50%) |

Weights are applied as biased sub-ranges within `[1, $cap]`. E.g. ATT Pace "top 60%" draws from `[floor($cap * 0.40), $cap]`.

#### Power — physical anchor + personality uplift, capped at $cap

```
physicalBase   = (normalise(height, 163, 203) + normalise(weight, 60, 97)) / 2   → 0.0–1.0
personalityMod = (determination + professionalism) / 40                           → 0.0–1.0
powerRaw       = (physicalBase × 0.6 + personalityMod × 0.4) × 100
power          = min($cap, max(1, (int) ceil($powerRaw)))
```

Higher weight and height → higher base. Determination and professionalism lift the ceiling.

#### Stamina — lean/fit bias + mental fortitude, capped at $cap

```
physicalBase   = ((1 - normalise(weight, 60, 97)) × 0.5 + normalise(height, 163, 203) × 0.5)  → 0.0–1.0
personalityMod = (determination + pressure + temperament) / 60                                  → 0.0–1.0
staminaRaw     = (physicalBase × 0.5 + personalityMod × 0.5) × 100
stamina        = min($cap, max(1, (int) ceil($staminaRaw)))
```

Lighter players skew higher; mental resilience traits (determination, pressure, temperament) provide uplift.

#### Heart — mental resilience aggregate, capped at $cap

```
heart = min($cap, max(1, (int) round(($loyalty + $determination + $pressure) / 60 * 100)))
```

Max personality sum = 60 (three traits × 20); scales linearly to 100.

#### Current Ability — computed last, no external cap

```
currentAbility = (int) round(($pace + $technical + $vision + $power + $stamina + $heart) / 6)
```

---

## Integration

`MarketPoolService::generatePlayers()` keeps position distribution, pool sizing, and persistence. It delegates player construction to `PlayerGenerationService`:

```php
// MarketPoolService
public function __construct(
    private readonly PlayerGenerationService $playerGen,
    // ... existing deps
) {}

private function createPlayer(PlayerPosition $position): Player
{
    return $this->playerGen->generate($position, RecruitmentSource::SCOUTING_NETWORK);
}
```

The existing inline player generation logic is deleted once the new service is verified correct.

---

## Helper: `normalise()`

```php
private function normalise(int $value, int $min, int $max): float
{
    return ($value - $min) / ($max - $min);
}
```

Used by Power and Stamina calculations. Clamps implicitly via the known input ranges.

---

## Out of Scope

- Guardian generation — remains in `MarketPoolService`
- Agent assignment — remains in `MarketPoolService`
- Contract value generation — remains in `MarketPoolService`
- Staff, scout, investor, sponsor generation — unchanged
