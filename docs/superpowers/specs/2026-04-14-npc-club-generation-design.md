# NPC Club Generation — Backend Design Spec

**Scope:** Spec A of the Club Sim expansion. Backend only.
**Excludes:** Country entity, League/Competition structure, frontend changes — those are Spec B.

---

## Goal

Add NPC clubs as persistent backend entities. Extend the player pool to include senior players. Add a consume endpoint so the frontend can remove claimed entities from the pool. Add new staff roles and Stadium as a facility category.

## Architecture

The backend is a **producer, not a tracker**. It generates entities, serves them via the market API, and forgets them when the frontend claims them. NPC clubs are the one exception — they persist as metadata (name, country, tier, facilities config) but carry no player or staff relationships. Their squads are assembled by the frontend at game-start by pulling from the pool.

---

## Data Model

### New Entity: `NpcClub`

| Field | Type | Notes |
|---|---|---|
| `id` | UUID | |
| `name` | varchar(100) | e.g. "Córdoba Athletic" |
| `country` | varchar(2) | ISO code — links to Country in Spec B |
| `tier` | smallint | 1 (top) → 8 (bottom) |
| `reputation` | smallint 0–100 | scales with tier at generation |
| `primaryColor` | varchar(7) | hex e.g. `#c0392b` |
| `secondaryColor` | varchar(7) | hex |
| `stadiumName` | varchar(100) | nullable — proper name e.g. "Estadio El Cid" |
| `balance` | int | starting budget in pence, scales with tier |
| `facilities` | JSON | flat map of facility slug → level (see below) |
| `createdAt` | datetime | |

No FK relationships to `Player` or `Staff`. The club is pure metadata.

**`facilities` JSON shape — flat, consistent with AMP club format:**
```json
{
  "training_pitch": 6,
  "strength_suite": 3,
  "physio_clinic": 2,
  "north_stand": 4,
  "south_stand": 3,
  "megastore": 1
}
```

Facility levels preset at generation time by tier:

| Tier | Training | Stadium stands | Medical/Scouting |
|---|---|---|---|
| 1–2 | 7–9 | 4–5 | 3–5 |
| 3–4 | 5–6 | 3–4 | 2–3 |
| 5–6 | 3–4 | 2–3 | 1–2 |
| 7–8 | 1–2 | 0–1 | 0–1 |

---

### `StaffRole` Enum — New Values

- `MANAGER` — runs the first team; AMP chairman must hire one
- `DIRECTOR_OF_FOOTBALL` — strategic/recruitment oversight
- `FACILITY_MANAGER` — manages facilities

---

### `FacilityTemplate.category` Enum — New Value

- `Stadium` added alongside `Training`, `Medical`, `Scouting`

Stadium sub-components (North Stand, South Stand, Megastore, etc.) are each their own `FacilityTemplate` record with `category: Stadium`. No schema changes to `FacilityTemplate`.

---

### `PoolConfig` — New Senior Player Fields

| Field | Default | Notes |
|---|---|---|
| `seniorPlayerAgeMin` | 17 | |
| `seniorPlayerAgeMax` | 35 | |
| `seniorPlayerAbilityMin` | 20 | |
| `seniorPlayerAbilityMax` | 90 | |
| `seniorPlayerPoolTarget` | 200 | replenishment target |

Senior players use the same position weights as youth players.

---

## Services

### `NpcClubGenerationService`

```php
public function generateClubs(int $count, int $tier, string $country): array
```

- Reads available `FacilityTemplate` slugs from DB; assigns levels by tier band (see table above)
- Reputation: tier 1 → 70–90, tier 8 → 5–20 (interpolated)
- Balance: tier 1 → high starting budget, tier 8 → minimal
- **Naming — hybrid approach:**
  - `PLACE_NAMES_BY_COUNTRY` — hardcoded array per ISO code, e.g. `'ES' => ['Sevilla', 'Córdoba', 'Granada', 'Murcia', ...]`
  - `SUFFIXES` — universal list: `['FC', 'CF', 'Athletic', 'United', 'City', 'Rovers', 'Town', 'SC', 'Deportivo']`
  - Generated name = `"{place} {suffix}"` — both arrays are easily editable in the service
- Colors: random from a small curated palette at generation time

---

### `MarketPoolService` — Extension

New method:

```php
public function generateSeniorPlayers(int $count): array
```

- Uses new `PoolConfig` `senior*` params for age and ability ranges
- Same position weighting as youth player generation
- No guardians generated (senior players have no guardians)
- Agent assignment chance unchanged (uses existing `playerAgentChancePercent`)

`replenishPool()` extended to check `seniorPlayerPoolTarget` alongside existing targets.

---

## API

### `POST /api/market/consume`

**Auth:** JWT (existing firewall)

**Request body:**
```json
{
  "playerIds": ["uuid1", "uuid2"],
  "staffIds":  ["uuid3"],
  "scoutIds":  []
}
```

**Behaviour:** Hard-deletes each entity by ID. Unknown IDs silently ignored (idempotent — safe to retry on network failure).

**Response:**
```json
{ "deleted": { "players": 2, "staff": 1, "scouts": 0 } }
```

Frontend calls this immediately after:
- AMP signs a player, hires staff, or recruits a scout
- Frontend instantiates NPC club squads at game-start (bulk call with all pulled player IDs)

---

## Admin UI

### `NpcClubCrudController`

Standard EasyAdmin CRUD — same pattern as `PlayerCrudController`, `StaffCrudController`.

- **List:** name, country, tier, reputation, balance
- **Edit:** all fields; `facilities` rendered as JSON textarea
- **Create:** manual club creation

### New admin page — `/admin/npc-clubs/generate`

Two action cards (same pattern as Pool Config page):

**Generate Clubs**
- Inputs: country (dropdown), tier (1–8), count
- Calls `NpcClubGenerationService::generateClubs()`
- Flash: "Generated 8 clubs for Spain — Tier 4"

**Replenish Senior Pool**
- Triggers senior player generation up to `seniorPlayerPoolTarget`
- Flash: "Generated 47 senior players — pool now at 200"

### Existing pages — minor additions

- **Pool Config page:** add the new `senior*` fields to the existing form
- **FacilityTemplate CRUD:** no changes — admin creates Stadium-category templates as normal once the enum value exists

### Sidebar

```
Clubs & Leagues          ← new section
  NPC Clubs              ← CRUD (NpcClubCrudController)
  Generate               ← quick actions page
```

---

## Out of Scope (Spec B)

- Country entity
- League / Competition entity
- Club → League assignment
- League snapshot in sync response
- Frontend changes (squad assembly, consume endpoint calls, club display)
