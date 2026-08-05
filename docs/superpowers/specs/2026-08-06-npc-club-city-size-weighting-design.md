# NPC Club City-Size Weighting — Design

**Date:** 2026-08-06
**Status:** Approved (design phase)

## Goal

Today `NpcClubGenerationService::PLACE_NAMES_BY_COUNTRY` is a flat `string[]` per
country, and club generation picks a place with uniform `array_rand()` — a
place name has zero relationship to the tier, reputation, or suffix a club
ends up with. This makes "big" cities (London, Madrid, São Paulo) exactly as
likely to spawn a tier-8 pub team as a tier-1 giant, and gives no sense that
club prestige tracks city size.

This change restructures the place-name data to carry population/region/
capital metadata, derives a `BIG`/`MEDIUM`/`SMALL` city-size classification
per place, and uses that classification to bias which tier a place is likely
to be picked for, which suffix pool its name is drawn from, and where in the
tier's reputation/balance range it lands. The weight table driving the
tier bias is admin-configurable, following the existing `GameConfig`
JSON-column pattern.

## Data Model

### `App\Enum\CitySize` (new)

Backed string enum: `BIG`, `MEDIUM`, `SMALL`.

### `NpcClubGenerationService::PLACE_NAMES_BY_COUNTRY` (restructured)

Changes from flat `string[]` to an array of associative arrays per country:

```php
private const PLACE_NAMES_BY_COUNTRY = [
    'EN' => [
        ['name' => 'London', 'population_size' => 8982000, 'region' => 'Greater London', 'is_capital' => true],
        ['name' => 'Manchester', 'population_size' => 553000, 'region' => 'North West'],
        // ~100 curated entries per country
    ],
    // ES, DE, IT, FR, BR, AR, NL, PT — same shape
];
```

`is_capital` is omitted (defaults to `false`) except on the actual capital
entry for that country.

**Curation scope**: each of the 9 countries (ES, EN, DE, IT, FR, BR, AR, NL,
PT) is trimmed/expanded to ~100 real, hand-researched places with accurate
population, region, and capital flag. Current lists range from ~30 entries
(EN suffix-style short lists aside) up to 600+ (BR, AR) — the long tail of
obscure, hard-to-verify small towns is dropped in favor of a curated,
verifiable set. This is a one-time manual research effort done during
implementation, not a script.

### `SUFFIXES_BY_COUNTRY` (split into two prestige pools)

```php
private const PRESTIGE_SUFFIXES_BY_COUNTRY = ['EN' => ['United', 'City', 'Athletic', ...], ...];
private const GENERIC_SUFFIXES_BY_COUNTRY  = ['EN' => ['Town', 'Rovers', 'Wanderers', 'Poli', ...], ...];
```

Every suffix currently in `SUFFIXES_BY_COUNTRY` is reused — split by hand into
whichever pool reads as "prestige/big-club" vs. "generic/lower-league" for
that country. No new suffixes are invented. `SUFFIXES_BY_COUNTRY` itself is
removed once both pools cover its old contents.

### `NpcClub` entity (new columns)

Set once at generation time, not recomputed later:

- `region` (`string`, length 100)
- `citySize` (enum `CitySize`)
- `populationSize` (`int`)
- `isCapital` (`bool`, default `false`)

### Size classification rule

Within each country's place list, rank by `population_size` descending:
- Top 20% → `BIG`
- Bottom 50% → `SMALL`
- Middle 30% → `MEDIUM`

`is_capital = true` always forces `BIG`, regardless of population rank.

## Generation Algorithm

### Weighted city selection (replaces uniform `array_rand`)

`generateName()` currently does `$placeNames[array_rand($placeNames)]`. This
becomes a weighted pick:

1. A weight table (see Admin Config below) gives `[big%, medium%, small%]`
   for tier 1 and tier 8. Tiers 2–7 linearly interpolate between those two
   anchor rows (same interpolation style as `reputationForTier()`).
2. For a `generateClubs($tier, ...)` call, each place gets a weight = (this
   tier's % for the place's `citySize`) ÷ (count of places in that bucket for
   the country) — so weight is spread evenly within a bucket.
3. A cumulative-weight roll replaces `array_rand` for the actual pick.

Places remain reusable across tiers and across separate `generateClubs()`
calls — no removal from the pool after a pick (matches current behavior).

### Suffix selection (depends on picked place's `citySize`)

- `BIG` → always draw from `PRESTIGE_SUFFIXES_BY_COUNTRY`
- `SMALL` → always draw from `GENERIC_SUFFIXES_BY_COUNTRY`
- `MEDIUM` → 50/50 coin flip between the two pools

### Reputation/balance bias

`reputationForTier()` and `balanceForTier()` currently do `random_int($min,
$max)` over the tier's full range. Add a size skew on top of the existing
range (tier remains the primary driver; size only biases within it):

- `BIG` → `random_int($min + round($span * 0.33), $max)` (upper two-thirds)
- `SMALL` → `random_int($min, $min + round($span * 0.66))` (lower two-thirds)
- `MEDIUM` → unchanged, full range

## Admin-Configurable Weight Table

Follows the existing `GameConfig` JSON-column + admin-form pattern already
used for `npcFacilityLevelRanges` (see
`DashboardController::npcClubsContent()` / `admin_npc_clubs_save_facility_config`).

### `GameConfig::npcClubSizeWeights` (new JSON column)

```php
[
    'tier1' => ['big' => 70, 'medium' => 25, 'small' => 5],
    'tier8' => ['big' => 5,  'medium' => 25, 'small' => 70],
]
```

Percentages need not sum to exactly 100 — `NpcClubGenerationService`
normalizes before use. Seeded to the defaults above.

### Admin route

New POST action `admin_npc_clubs_save_size_weights`
(`/admin/npc-clubs/save-size-weights`) on `DashboardController`, mirroring
`admin_npc_clubs_save_facility_config`: CSRF check → parse `tier1`/`tier8`
nested request fields → coerce to int → `GameConfig::setNpcClubSizeWeights()`
→ flush → flash message → redirect back through `admin_npc_clubs_content`
(per the `ea`-context redirect rule — custom POST actions must redirect via
`generateUrl('admin', ['routeName' => ...])`, never `redirectToRoute()`
directly).

### Admin template

`templates/admin/npc_clubs_content.html.twig` gets a new form section: two
rows (Tier 1, Tier 8), three number inputs each (Big/Medium/Small %), next to
the existing facility-config form.

`NpcClubGenerationService` reads
`GameConfigRepository::getConfig()->getNpcClubSizeWeights()` and linearly
interpolates tiers 2–7 from the two anchor rows — same pattern as
`getNpcFacilityLevelRangeForSlugAndBand`.

## Migration & Rollout

**Schema migration** (`doctrine:migrations:diff`):
- `npc_club`: add `region` (varchar 100, nullable), `city_size` (varchar,
  enum-backed, default `'MEDIUM'`), `population_size` (bigint, default `0`),
  `is_capital` (boolean, default `false`)
- `game_config`: add `npc_club_size_weights` (json), seeded with the default
  weight table above on the existing singleton row

**Existing `NpcClub` rows** predate this feature — there's no real place data
to backfill them from, so they get the column defaults above (`region =
null`, `city_size = MEDIUM`, `population_size = 0`, `is_capital = false`).
Only clubs generated after this ships carry real values. This is consistent
with how NPC clubs are already refreshed today via the `deleteExisting` flag
on `generateClubs()` — existing pools naturally cycle over time.

## Out of Scope

- Backfilling real city data onto already-generated `NpcClub` rows.
- Any change to which countries are supported (still ES, EN, DE, IT, FR, BR,
  AR, NL, PT).
- Any frontend/client changes to display `region`/`citySize` (the fields are
  added and populated on the backend; surfacing them to the client is a
  separate follow-up).
