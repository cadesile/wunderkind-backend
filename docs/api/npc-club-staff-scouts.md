# API Spec — NPC Club Facility Manager / DOF / Scout Staff

Extends per-tier NPC club generation to also produce Facility Managers,
Directors of Football (DOF), and Scouts, scaled by league tier. Two visible
changes for clients: the `staff[]` array on every NPC club object may now
contain `director_of_football`/`facility_manager` entries (previously only
`manager`/`coach`/`chairman` were ever drawn for NPC clubs), and every NPC
club object gains a brand-new `scouts[]` array. This spec covers what the
client now receives, not how the backend generates it.

## Where this shows up

```
POST /api/initialize/league/{tier}
```

This is the endpoint the client calls once per league tier during world
initialization. The response wraps the tier's league snapshot:

```json
{
  "id": "...",
  "tier": 1,
  "name": "...",
  "country": "EN",
  "clubs": [ /* NPC club objects — see below */ ],
  "fixtures": [ ... ]
}
```

Each entry in `clubs[]` is one NPC club. The `staff` and `scouts` fields
below are the only changed/new parts of the club object; every other key
(`id`, `name`, `abbreviation`, `reputation`, `startingBalance`,
`primaryColor`, `secondaryColor`, `identity`, `stadiumName`, `facilities`,
`region`, `citySize`, `populationSize`, `isCapital`, `personality`,
`players`) is unchanged.

This endpoint is cached per `(country, tier)` — see "Caching caveat" below.
`GET /api/club/foreign` and `GET /api/scout/foreign-clubs` are **not**
affected: they return a flatter, uncached club shape (no `staff`/`scouts` at
all today), so nothing changes there.

## New fields

| Field | Type | Description |
|---|---|---|
| `staff[].role` | `string` | May now be `"director_of_football"` or `"facility_manager"`, in addition to the existing `"manager"`, `"coach"`, `"chairman"`. Shape of each `staff[]` entry is unchanged (`id, firstName, lastName, dateOfBirth, nationality, role, tier, coachingAbility, scoutingRange, weeklySalary, morale, specialisms, appearance, personality`). |
| `scouts` | `array` | New. A list of scouts associated with the club. Each entry: `id, name, dateOfBirth, nationality, experience, tier, judgements, appearance, personality` — the same shape already used for the player's own starter-pack scouts. May be an empty array `[]` for lower tiers (see "Client integration notes"). |

## Example club object

```json
{
  "id": "0197f3b2-...",
  "name": "London United",
  "abbreviation": "LON",
  "tier": 1,
  "reputation": 84,
  "startingBalance": 520000000,
  "primaryColor": "#c0392b",
  "secondaryColor": "#2c3e50",
  "stadiumName": "London Park",
  "facilities": { "training_pitch": 8, "north_stand": 4 },
  "personality": {
    "playingStyle": "POSSESSION",
    "financialApproach": "SPECULATIVE",
    "managerTemperament": 62
  },
  "players": [ ... ],
  "staff": [
    { "id": "...", "role": "manager", "...": "..." },
    { "id": "...", "role": "coach", "...": "..." },
    { "id": "...", "role": "chairman", "...": "..." },
    { "id": "...", "role": "director_of_football", "...": "..." },
    { "id": "...", "role": "facility_manager", "...": "..." }
  ],
  "scouts": [
    {
      "id": "0197f3b2-...",
      "name": "Marcus Webb",
      "dateOfBirth": "1978-04-12",
      "nationality": "EN",
      "experience": 62,
      "tier": 3,
      "judgements": [],
      "appearance": { "...": "..." },
      "personality": { "determination": 14, "professionalism": 12, "...": "..." }
    }
  ]
}
```

## Per-tier counts

The number of each staff type/scouts drawn per NPC club is admin-configured
per league tier (`StarterConfig::$npcSquadConfig`, edited via the "NPC Squad
Config" admin screen), tapering toward the lower tiers — by design, tier 7-8
clubs may have zero DOF/Facility Manager staff and zero scouts. This is not
new information for the client (the generation logic is server-internal),
but explains why `scouts: []` or an absence of these two roles is expected
and common at lower tiers, not a bug.

## Backfill / pre-existing data

This is generation-config-driven, not a persisted-entity-column change — no
existing `NpcClub`, `Staff`, or `Scout` rows need migrating. Only world packs
generated (or regenerated) after this change ships will include the new
staff roles/`scouts[]`.

## Caching caveat

World packs (`POST /api/initialize/league/{tier}`) are cached per
`(country, tier)`, keyed additionally by an internal shape-version counter
(`WorldInitializationService::WORLD_PACK_VERSION`, bumped 1 → 2 for this
change). Any tier pack cached under the old version is automatically treated
as a miss and rebuilt with the new shape on its next request — **no manual
admin cache-clear is required** for this rollout. This is a stronger
guarantee than some other additive changes to this same object (compare the
`region`/`citySize`/`populationSize`/`isCapital` fields, which were **not**
covered by a version bump and can still be served stale after an admin
regenerates NPC clubs without also clearing the world-pack cache) — for
`staff[]`/`scouts[]` specifically, the version bump means every client gets
the new shape on first fetch after this deploys.

## `GET /api/game-config` — breaking change to the `npcSquadConfig` key

Separately, `GET /api/game-config`'s `npcSquadConfig` key has changed shape.
Previously it exposed a dead, never-actually-used config array (0-indexed,
`{squadMin, squadMax, managers, coaches, chairmen, foreignPercent}`) that did
not reflect the real generation config at all. It now returns the same
tier-keyed config that actually drives NPC generation:

```json
{
  "1": {
    "playerMin": 20, "playerMax": 24,
    "managerCount": 1, "coachCount": 5, "chairmanCount": 1,
    "directorOfFootballCount": 1, "facilityManagerCount": 2, "scoutCount": 3,
    "foreignPercent": 60
  },
  "2": { "...": "..." },
  "...": "...",
  "8": { "...": "..." }
}
```

If any client code was reading the old `npcSquadConfig` shape from
`/api/game-config` (unlikely, since it never reflected real generation
behavior), it needs to be updated for the new tier-string-keyed object and
field names above.

## Client integration notes

- **Additive/new-enum-values only** for the world-pack object — no new
  parsing logic needed for `staff[]` beyond handling two new (already
  human-readable) `role` string values you may not have seen before.
- **`scouts: []` and missing DOF/Facility-Manager roles are valid, expected
  states** at lower league tiers — treat them the same as any other
  legitimately-empty roster slice, not an error.
- **Old cached/stored world-pack data** (from before this change) simply
  won't have a `scouts` key — treat a missing key the same as `[]`.
