# GET /api/players/youth — youth pool draw

Draws youth players (aged **12–14 inclusive**) from the shared Player pool, weighted
towards one country and spread across all four reputation tiers, for the client's youth
intake across the whole pyramid — the user's club and every NPC club.

Auth: `ROLE_CLUB` (the `api` JWT firewall), same as `GET /api/players/foreign`.

## Request

| Param | Type | Required | Default | Notes |
|---|---|---|---|---|
| `country` | `Country` code, e.g. `EN` | **yes** | — | The nationality the draw is weighted *towards*, not filtered to. Case-insensitive. Passed explicitly by the client, not inferred server-side — same convention as `/foreign` and `/scout/search`. |
| `limit` | int | no | `200` | Clamped to 1–500. |

There is deliberately **no `tier` parameter**. The device matches players to a club's
reputation tier itself, so the response carries a spread across all four tiers plus the
field needed to classify them.

```
GET /api/players/youth?country=EN&limit=200
```

### Errors

| Status | When |
|---|---|
| `400` | `country` missing or blank |
| `422` | `country` is not a known `App\Enum\Country` code |
| `401` | no/!valid club token |

## Response

```jsonc
{
  "country": "EN",
  "amount": 200,
  "ageRange": { "min": 12, "max": 14 },
  "distribution": {
    "requested": 200,
    "returned": 200,
    "poolSize": 3188,
    "byPotentialTier": { "local": 110, "regional": 56, "national": 26, "elite": 8 },
    "nationalityMatched": 171
  },
  "players": [ /* player objects, shape below */ ]
}
```

`distribution` is the **realised** spread, not the target — see "The pool is the supply".

### Player object

The canonical player snapshot (`PlayerSnapshotSerializer`) — **the same shape the world
pack returns**, so these upsert through the client's existing `players` helpers with no new
parsing. Youth draws add `guardians` and `isYouth`.

```jsonc
{
  "id": "01a13bf9-86b2-7037-bd19-c4a8408deda5",
  "firstName": "Ellis",
  "lastName": "Hartley",
  "position": "MID",
  "nationality": "English",
  "countryCode": "EN",
  "dateOfBirth": "2013-04-18",
  "contractValue": 0,
  "potential": 78,
  "currentAbility": 24,
  "overall": 24,
  "tier": "regional",              // banded on currentAbility
  "potentialTier": "elite",        // banded on potential  ← classify youth on THIS
  "morale": 50,
  "recruitmentSource": "youth_intake",
  "isActive": true,
  "physical": { "height": 168, "weight": 58 },
  "pace": 21, "technical": 28, "vision": 26,
  "power": 18, "stamina": 23, "heart": 25,
  "personality": {
    "determination": 10, "professionalism": 10, "ambition": 10, "loyalty": 10,
    "adaptability": 10, "pressure": 10, "temperament": 10, "consistency": 10
  },
  "appearance": { /* 15-key SpriteConfig, see sprite-appearance-schema.md */ },
  "agent": null,
  "isYouth": true,
  "guardians": [
    {
      "id": "01a13bf9-86b5-7225-985a-6cc4f2e3b4b5",
      "firstName": "Marie", "lastName": "Hartley",
      "dateOfBirth": "1988-02-02", "gender": "female",
      "demandLevel": 6, "loyaltyToClub": 58, "contactEmail": null
    }
  ]
}
```

`isYouth` is present **only** on this endpoint and is always `true` — it is omitted
entirely elsewhere rather than sent as `false`, so no other response gained a key. Use it
to set the client's own youth flag / join table.

## Classify on `potentialTier`, not `tier`

Both are `local | regional | national | elite` (`App\Enum\Tier`, bands 0–14 / 15–39 /
40–74 / 75–100), but they band different numbers:

- **`tier`** = `currentAbility`. Unchanged in meaning from what `/scout/search` and
  `/players/foreign` have always sent.
- **`potentialTier`** = `potential`. **Use this one for youth.**

Current ability is derived as `abilityTarget × potential`, and `abilityTarget` is only
0.30–0.60 for under-21s, so a 12–14 year old's ability sits near the floor. Measured
against a real 5,412-player pool, `currentAbility` never exceeded 69 — nothing was *ever*
`elite` by that measure — while `potential` reached 90 and produced a usable four-band
spread. Scouting a 13-year-old on what they might become is also the domain-correct read.

## The pool is the supply

This endpoint **draws from the existing pool; it does not generate**. The quality and
nationality spreads are therefore properties of what has been seeded, not guarantees.

The draw is stratified — it asks for a target per (tier × nationality) cell, then backfills
from the rest of the age window when a cell is short, preferring the requested nationality.
Targets are `local 55 / regional 28 / national 13 / elite 4` by weight, with the requested
country making up `95 / 85 / 70 / 45 %` of each tier respectively — lower tiers are mostly
local lads, the elite band is the most internationally mixed. Those four percentages are
**admin-editable** (`PoolConfig`, Player Pool screen); the tier weights are not.

With the defaults a 200-player draw is about **175 home / 25 foreign (12.5%)** — but the
foreign players concentrate at the top (`local 5% / regional 15% / national 30% / elite
55%`), so a draw read best-first looks far more international than the overall figure.

**`returned` can be less than `requested`.** That means the pool does not hold enough youth
matching the ask, not that the request failed — `poolSize` (how many 12–14 year olds exist
at all) and `byPotentialTier` are there to make which shelf is empty obvious. Clients should
treat a short draw as normal and render what they get.

## Non-consuming

Nothing is deleted, claimed or marked. Youth intake runs for every club in the pyramid, so
a consuming draw would empty the pool in a handful of calls.

**Consequence for clients: two devices can be handed the same player `id`.** This is a
shared read, not an exclusive allocation — upsert by `id` and expect overlap between
devices and between successive calls. If a player must belong to exactly one club, the
client owns that decision locally.

Pool players carry **no club association** — `Player` has no club foreign key at all while
in the pool (see CLAUDE.md's Pool Lifecycle), so nothing in this payload references a club.

## Related

- `docs/api/players-foreign.md` — the sibling non-consuming pool draw
- `docs/api/sprite-appearance-schema.md` — the `appearance` blob
- `.context/stages/04_interfaces/output/routes.md` — full route table
