# Admin: Starter Config League Ability Ranges Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement a dynamic configuration matrix in the backend `StarterConfig` to manage player ability ranges per country/league tier, and update the frontend types to reflect this.

**Architecture:** Use a JSON column in the `StarterConfig` entity to store the matrix. The backend EasyAdmin form will dynamically generate inputs based on the countries and tiers present in the database. The frontend `StarterConfig` type is updated to allow consumption of this new data.

**Tech Stack:** PHP 8.4 (Symfony), EasyAdmin 5, TypeScript (React Native)

---

### Task 1: Frontend Type Update

**Files:**
- Modify: `src/types/api.ts`

- [ ] **Step 1: Update the `StarterConfig` interface**

Add the `leagueAbilityRanges` field to the interface to support the new backend payload.

```typescript
export interface StarterConfig {
  startingBalance: number;       // pence
  starterPlayerCount: number;
  starterCoachCount: number;
  starterScoutCount: number;
  starterSponsorTier: string;    // 'SMALL' | 'MEDIUM' | 'LARGE'
  /** Default club tier for new academies e.g. 'local' | 'regional' | 'national' | 'elite' */
  starterClubTier: string;
  /** ClubCountryCode values available in the country picker. Defaults to ['EN'] if absent. */
  enabledCountries?: string[];
  /** 
   * Global ability ranges per country and league tier.
   * Format: { "EN": { "1": { "min": 75, "max": 100 } } }
   */
  leagueAbilityRanges?: Record<string, Record<number, { min: number; max: number }>>;
}
```

- [ ] **Step 2: Commit changes**

```bash
git add src/types/api.ts
git commit -m "types: add leagueAbilityRanges to StarterConfig"
```

---

### Task 2: Backend Database & Entity (Backend Repo)

**Files:**
- Create: `migrations/VersionXXX.php` (generate via `php bin/console make:migration`)
- Modify: `src/Entity/StarterConfig.php`

- [ ] **Step 1: Add the JSON field to the `StarterConfig` entity**

```php
#[ORM\Column(type: 'json')]
private array $leagueAbilityRanges = [];

public function getLeagueAbilityRanges(): array
{
    return $this->leagueAbilityRanges;
}

public function setLeagueAbilityRanges(array $ranges): self
{
    $this->leagueAbilityRanges = $ranges;
    return $this;
}
```

- [ ] **Step 2: Generate and run the migration**

Run: `php bin/console make:migration`
Expected: Migration file created with `ALTER TABLE starter_config ADD league_ability_ranges JSON NOT NULL DEFAULT '[]'` (or similar syntax for your DB).

Run: `php bin/console doctrine:migrations:migrate`
Expected: Success.

---

### Task 3: Backend Admin Form (Backend Repo)

**Files:**
- Modify: `src/Controller/Admin/DashboardController.php`
- Modify: `templates/admin/starter_config.html.twig`

- [ ] **Step 1: Inject `LeagueRepository` and pass dynamic tiers to the template**

In `adminStarterConfig()`, query the database for all distinct countries and their tiers.

```php
// DashboardController.php
$leagues = $this->leagueRepository->findAll();
$dynamicLeagueTiers = [];
foreach ($leagues as $league) {
    $dynamicLeagueTiers[$league->getCountry()][] = $league->getTier();
}
// Ensure tiers are unique and sorted
foreach ($dynamicLeagueTiers as &$tiers) {
    $tiers = array_unique($tiers);
    sort($tiers);
}

return $this->render('admin/starter_config.html.twig', [
    'config' => $config,
    'dynamicLeagueTiers' => $dynamicLeagueTiers,
]);
```

- [ ] **Step 2: Update the template to render the range inputs**

In `templates/admin/starter_config.html.twig`, add the dynamic section.

```twig
<div class="card mb-3">
  <div class="card-header">League Player Ability Ranges</div>
  <div class="card-body">
    {% for country, tiers in dynamicLeagueTiers %}
      <h6 class="mt-3">{{ country }} Leagues</h6>
      <div class="row">
      {% for tier in tiers %}
        <div class="col-md-3 mb-2">
           <label class="small fw-bold">Tier {{ tier }}</label>
           <div class="input-group input-group-sm">
             <input type="number" name="leagueRanges[{{ country }}][{{ tier }}][min]" 
                    class="form-control" placeholder="Min" 
                    value="{{ config.leagueAbilityRanges[country][tier]['min'] ?? '' }}"
                    min="1" max="100">
             <input type="number" name="leagueRanges[{{ country }}][{{ tier }}][max]" 
                    class="form-control" placeholder="Max"
                    value="{{ config.leagueAbilityRanges[country][tier]['max'] ?? '' }}"
                    min="1" max="100">
           </div>
        </div>
      {% endfor %}
      </div>
    {% endfor %}
  </div>
</div>
```

- [ ] **Step 3: Update the save handler to persist the JSON matrix**

In `adminStarterConfigSave()`, read the `leagueRanges` from the request and save to the entity.

```php
// DashboardController.php
$ranges = $request->request->all('leagueRanges') ?: [];
// Basic validation: ensure values are numeric
foreach ($ranges as $country => &$countryTiers) {
    foreach ($countryTiers as $tier => &$bounds) {
        $bounds['min'] = (int) $bounds['min'];
        $bounds['max'] = (int) $bounds['max'];
    }
}
$config->setLeagueAbilityRanges($ranges);
```

---

### Task 4: Player Generation Logic (Backend Repo)

**Files:**
- Modify: `src/Service/WorldInitializationService.php` (or relevant generation service)

- [ ] **Step 1: Use the configured ranges during player generation**

When creating players for an NPC club, fetch the range from `StarterConfig`.

```php
$config = $this->starterConfigRepository->findOneBy([]);
$ranges = $config->getLeagueAbilityRanges();
$country = $club->getCountry();
$tier = $club->getTier();

$min = $ranges[$country][$tier]['min'] ?? 10; // Sensible default
$max = $ranges[$country][$tier]['max'] ?? 50; // Sensible default

// Pass $min and $max to the player generation method
$this->playerGenerator->generateForClub($club, $min, $max);
```

- [ ] **Step 2: Update `StarterConfigController` to include the ranges in the API**

Ensure the JSON response for `/api/starter-config` includes the new field.

```php
return $this->json([
    // ...
    'leagueAbilityRanges' => $config->getLeagueAbilityRanges(),
]);
```
