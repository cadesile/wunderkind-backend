# API Spec — NPC Club City-Size Fields

Adds four fields to every NPC club object returned in the world pack: `region`,
`citySize`, `populationSize`, `isCapital`. These describe the real-world city an
NPC club is named after and drive how the backend already biases that club's
naming, tier placement, and reputation/balance — this spec covers what the
client now receives, not how the backend generates it.

## Where this shows up

```
POST /api/initialize/league/{tier}
```

This is the endpoint the client calls once per league tier during world
initialization (after `POST /api/initialize/starter`). The response wraps the
tier's league snapshot:

```json
{
  "tier": 1,
  "data": {
    "id": "...",
    "tier": 1,
    "name": "...",
    "country": "EN",
    "clubs": [ /* NPC club objects — see below */ ],
    "fixtures": [ ... ]
  }
}
```

Each entry in `data.clubs[]` is one NPC club. The fields below are **new**;
every other key on the club object (`id`, `name`, `abbreviation`, `reputation`,
`startingBalance`, `primaryColor`, `secondaryColor`, `stadiumName`,
`facilities`, `personality`, `players`, `staff`) is unchanged.

## New fields

| Field | Type | Description |
|---|---|---|
| `region` | `string \| null` | The city's subnational region/administrative division (e.g. `"Greater Manchester"`, `"Catalonia"`). `null` for the handful of pre-existing NPC clubs generated before this feature shipped (see Backfill below) — never `null` for clubs generated after. |
| `citySize` | `"BIG" \| "MEDIUM" \| "SMALL"` | The city's size classification, derived from its population rank within its country (top 20% of curated cities = `BIG`, bottom 50% = `SMALL`, rest = `MEDIUM`). A country's capital is always `BIG` regardless of population rank. |
| `populationSize` | `integer` | The city's approximate real-world population. `0` for pre-existing clubs generated before this feature (see Backfill below). |
| `isCapital` | `boolean` | Whether the city is the national capital of the club's country. |

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
  "region": "Greater London",
  "citySize": "BIG",
  "populationSize": 8982000,
  "isCapital": true,
  "personality": {
    "playingStyle": "POSSESSION",
    "financialApproach": "SPECULATIVE",
    "managerTemperament": 62
  },
  "players": [ ... ],
  "staff": [ ... ]
}
```

## Backfill / pre-existing data

`NpcClub` rows created **before** this feature shipped don't have real city
data — they get the column defaults: `region: null`, `citySize: "MEDIUM"`,
`populationSize: 0`, `isCapital: false`. There is no retroactive backfill; a
club only carries real values if it was generated (or regenerated) after this
change. In practice, most NPC clubs get regenerated periodically via the admin
"Generate Clubs" action, so this self-heals over time — but the client should
treat `region: null` / `populationSize: 0` as a valid, expected "no data yet"
state, not an error.

## Caching caveat — worldpack cache does not auto-refresh

World packs are cached per `(country, tier)` and are **not** automatically
invalidated when NPC clubs are regenerated in the admin panel. If a country's
tier pack was already cached (by any player completing world init for that
country/tier) before an admin regenerates that country's NPC clubs, the
cached pack keeps serving the old snapshot — including old `region`/`citySize`
values — until an admin manually clears it (`WorldPackController`'s delete
action, or `app:worldpack:warm --force`). This is existing, unchanged cache
behavior; it isn't specific to these new fields, but it means the client can't
assume every club it receives reflects the latest backend state — treat these
fields as descriptive/flavor data, not something to poll for freshness.

## Client integration notes

- **No migration needed for existing local data.** These are additive fields
  on an object the client already parses; older cached/stored world-pack data
  (from before this change) simply won't have them — treat missing keys the
  same as `null`/`0`/`false` per the table above.
- **Suggested uses:** `citySize` and `isCapital` are natural drivers for club
  crest/flavor-text prominence (e.g. a "Capital Club" or "Big City" badge in
  scouting/opponent screens); `region` is display-only city context (e.g. "London
  United — Greater London"); `populationSize` is not expected to be shown
  directly to players in its raw form, but is available if useful.
- **Not yet available:** the narrower `GET /api/club/foreign` endpoint (used
  for scouting opponent lookups) still only returns `id`/`name`/`country`/`tier`
  — these new fields are not present there. Ask if that endpoint should be
  extended too; it wasn't in scope for this change.
