# Event template configuration guide

How `GameEventTemplate` rows are shaped, and which parts of them the client actually acts on.

Edit templates at **Admin → Game Event Templates**; every field below has the same reference
inline on the edit page. Keep the two in step — the admin help lives in
`src/Controller/Admin/GameEventTemplateCrudController.php`.

> **The backend does not interpret any of this.** `impacts`, `firingConditions`,
> `chainedEvents` and `severity` are stored as JSON and passed through
> `GET /api/events/templates` verbatim. Everything that gives them meaning is client-side, so
> "valid" here means "some engine reads it", not "the schema allows it". Several
> plausible-looking keys are inert; they are marked below.

## Which engine reads a template

The `category` decides this, and it decides which `impacts` shape works.

| Category | Client engine | `impacts` shape |
|---|---|---|
| `player`, `facility`, `staff`, `finance`, `NPC_INTERACTION`, `GUARDIAN`, `MATCH` | `SimulationService` | Either shape |
| `player_reputation`, `player_milestone`, `player_morale`, `player_form` | `PlayerEventEngine` | **`stat_changes` only** |
| `press` | `PressEngine` | Either shape |

## `impacts`

### Canonical shape — works everywhere

```json
{
  "stat_changes": [
    { "target": "player_1", "field": "morale", "operator": "subtract", "value": 8 }
  ]
}
```

| Key | Values | Notes |
|---|---|---|
| `target` | `player_1`, `player_2`, `squad_wide`, `pair` | `squad_wide` hits every active player. `pair` is only valid with `field: "relationship"`. On staff-subject morale templates, `staff.morale` and `squad.morale` are passed through as targets. |
| `field` | `morale`, `condition`, `overallRating`, …<br>`personality.<trait>`<br>`relationship` | Any numeric player field (clamped 0–100), a personality trait (clamped 1–20), or the pair bond. |
| `operator` | `add`, `subtract`, `set` | Anything else is a no-op. |
| `value` | positive integer | Use `subtract` to reduce. |

### Legacy flat shape — not read by `PlayerEventEngine`

```json
[{ "target": "player_1.morale", "delta": -8 }]
```

| Target | Notes |
|---|---|
| `player_N.morale` | Clamped 0–100. |
| `player_N.personality.<trait>` | Clamped 1–20. |
| `squad.morale` | Every active player. |
| `club.reputation` | Club reputation delta. |
| `pair.relationship` | Bond between `player_1` and `player_2`. |

**The slot number is required.** A bare `player.morale` resolves against an entity map whose
only player keys are `player_1`, `player_2`, … — it matches nothing and the impact is dropped
without an error. This silently disabled seven shipped templates before it was found.

### Optional sibling keys

| Key | Shape |
|---|---|
| `relationships` | `[{"type": "friendship"\|"rivalry", "player_1_ref": "player_1", "player_2_ref": "player_2", "intensity": 10}]` |
| `choices` | `[{"emoji": "👏", "label": "Back them", "stat_changes": [ … ], "manager_shift": {"temperament": 1, "discipline": 0, "ambition": 0}}]` |
| `selection_logic` | `{"target_type": "player"\|"facility"\|"staff"\|"squad_wide", "count": 2, "filter": {"position": "MID", "min_age": 16, "max_age": 21, "max_level": 3}}` |

### Not implemented

| Key | Status |
|---|---|
| `duration_config` | The client's `createActiveEffect` has no call sites — multi-week effects do nothing. |
| `selection_logic.filter.active_only` | Declared but never read; inactive players are always excluded anyway. |
| `player_N.injuredWeeks` | No engine applies it. Injuries are not settable from events. |

### Personality traits

Exactly eight exist, all on a **1–20** scale:

`determination`, `professionalism`, `ambition`, `loyalty`, `adaptability`, `pressure`,
`temperament`, `consistency`

Any other name is silently dropped. `teamwork`, `leadership`, `confidence`, `ego`, `bravery`
and `maturity` are **not** traits — older seed data used them and none of it had any effect.
`app:events:repair` remaps them (`ego→ambition`, `teamwork→adaptability`,
`leadership→determination`, `maturity→professionalism`, `confidence→pressure`,
`bravery→pressure`).

## `firingConditions`

Leave it **null** for an unconditional event that joins the weekly random roll.

> Once set, the template is removed from the random roll and only fires if a dedicated
> evaluator claims it. **A shape that matches none of the dialects below makes the event
> permanently dead** — it never appears, and nothing reports why.

| Category | Keys |
|---|---|
| `press` | `type` ∈ `consecutiveLosses`, `consecutiveWins`, `unbeatenRun`, `leaguePosition`, `relegationZone`, `goalDifference`; `threshold` |
| `MATCH` | `type` ∈ `heavyDefeat`, `statementWin`, `cleanSheet`, `redCard`, `hatTrick`, `standoutRating`, `collapseRating`; `threshold` (`cleanSheet` ignores it) |
| `player_*` | `reputation_tier` (+ `reputation_direction`), `age_milestone`, `morale_threshold` (+ `morale_direction`), `consecutive_good_games`, `consecutive_poor_games`, `morale_below`, `morale_above` |
| `player_morale`, staff variant | `subject: "staff"`, `staff_role`, `morale_below`, `morale_above` |
| `NPC_INTERACTION` | `minSquadMorale`, `maxSquadMorale`, `minPairRelationship`, `maxPairRelationship`, `requiresCoLocation`, `actorTraitRequirements`, `subjectTraitRequirements` |

Two behaviours that look like bugs but are not:

- **The `player_*` keys are an if/else-if chain evaluated in the order listed — only the first
  one present is checked.** Combining `age_milestone` with `morale_below` silently ignores the
  morale gate. The staff and NPC keys *are* combined.
- **`morale_threshold` without a `morale_direction` never fires.**

Trait requirements are `{"trait": "ambition", "min": 12}` on the **1–20** scale. Older data
used 0–100; `app:events:repair` rescales it.

## `severity`

`minor` or `major`, or null.

**Only read on the `NPC_INTERACTION` path**, where `major` defers the impacts to a card the
manager must respond to and `minor` applies them automatically as a read-only inbox report.
On every other category it is ignored. `low`/`medium`/`high` were an unrelated vocabulary that
nothing read; `app:events:repair` migrates them.

## `chainedEvents`

```json
[{ "nextEventSlug": "npc_squad_banter", "boostMultiplier": 2.0, "windowWeeks": 3, "note": "admin-only" }]
```

Multiplies the target template's weight for the same player pair for `windowWeeks`. Consumed
on the **`NPC_INTERACTION`** path only — chains on any other category are ignored. `note` is
stripped by `GameEventTemplate::getChainedEventsWithoutNotes()` before the API sends it.

## `bodyTemplate` tokens

Substitution differs per engine, and an unmatched token reaches the player verbatim.

| Engine | Tokens |
|---|---|
| `SimulationService` | `{player_1}`…`{player_N}`, `{player}`, `{player_name}`, `{player2}`, `{facility_1}`…, `{facility}`, `{PA_Name}`, `{consecutive_good_games}`, `{consecutive_poor_games}` — both `{token}` and `{{token}}` |
| `PlayerEventEngine` | `{player_name}`, `{age}`, `{reputation_tier}`, `{consecutive_*_games}`; staff: `{staff_name}`, `{player_name}`, `{role}` |
| `PressEngine` | `{clubName}`, `{managerName}`, `{position}`, `{streak}` |
| `GuardianEngine` | `{guardian_name}`, `{player_name}` — first occurrence only |

`{staff}` is substituted nowhere. `{amount}` works only in `fan_shirt_sales_income`, which
`SeasonTransitionService` fills in by hand.

## API shape

`GET /api/events/templates` returns `{"templates": [...]}`, ten keys each, for templates with
`weight > 0`:

`slug`, `category`, `weight`, `title`, `bodyTemplate`, `impacts`, `firingConditions`,
`severity`, `chainedEvents`, `noInteract`

Cached for one hour.

## Commands

```bash
lando php bin/console app:seed-game-events            # skips existing slugs
lando php bin/console app:seed-game-events --update   # overwrite them with the seed definitions
lando php bin/console app:events:repair               # dry run
lando php bin/console app:events:repair --apply
```

`app:events:repair` is idempotent. It un-corrupts `impacts` damaged by the old nested admin
form, slots bare `player.` targets, remaps non-existent traits (rescaling 0–100 thresholds to
1–20), and migrates legacy `severity` values.
