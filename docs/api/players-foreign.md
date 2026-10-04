# Foreign Players API (client integration)

Branch: `development` (pending commit). Two additions, same underlying idea — excluding a
nationality from a pool-player query, keyed by country code: a brand-new endpoint, and a new
param on the existing scout-search endpoint.

## `GET /api/players/foreign`

```
GET /api/players/foreign?country=<code>&amount=<n>
Authorization: Bearer <JWT>   (ROLE_CLUB required)
```

| Param | Required | Type | Notes |
|---|---|---|---|
| `country` | **yes** | 2-letter code | The account's own club country (e.g. `EN`, `US`, `NG`) — same codes as `GET /api/countries`. Validated against the backend's canonical country list; `400` if omitted, `422` if not a real code. |
| `amount` | no | integer | How many players to return. Default `20`, clamped to a max of `200` regardless of what's requested. |

Resolves `country` to its nationality (`EN` → `"English"`, `NG` → `"Nigerian"`, etc. — the
same mapping `GET /api/countries` exposes) and returns a random draw of pool players whose
`nationality` does **not** equal that value. `?country=EN` returns anything but English
players. No ability, position, or age filtering — if you need those, `GET /api/scout/search`
is the existing endpoint for a filtered browse.

This is read-only / non-consuming — players returned here are **not** removed from the pool,
unlike assigning a player via `POST /api/market/assign` or the starter-pack flow. Calling it
repeatedly is safe and won't deplete anything.

### Example

```
GET /api/players/foreign?country=EN&amount=5
```
```json
200 OK
{
  "country": "EN",
  "amount": 5,
  "players": [
    {
      "id": "uuid",
      "firstName": "...",
      "lastName": "...",
      "dateOfBirth": "YYYY-MM-DD",
      "nationality": "Nigerian",
      "countryCode": "NG",
      "position": "MID",
      "potential": 72,
      "currentAbility": 58,
      "contractValue": 1500000,
      "tier": "regional",
      "recruitmentSource": "scouting_network",
      "pace": 60, "technical": 55, "vision": 58, "power": 62, "stamina": 70, "heart": 65,
      "overall": 61,
      "physical": { "height": 181, "weight": 76 },
      "appearance": { "...": "..." },
      "agent": { "id": "uuid", "name": "...", "commissionRate": "10.00", "...": "..." },
      "personality": { "determination": 14, "...": "..." },
      "guardians": []
    }
  ]
}
```

`amount` in the response is the **actual** count returned (may be less than requested if the
pool doesn't have enough matching players) — not an echo of the request param.

### New field: `countryCode`

Every player object across both pool-browsing endpoints (`/api/players/foreign` and the
existing `GET /api/scout/search`) now also carries `countryCode`, alongside the existing
`nationality` string — same addition already shipped on the world-pack/starter-pack player
snapshots. See `docs/api/countries.md` for the full mapping contract; use `GET /api/countries`
rather than hardcoding a nationality→code table client-side.

### Error responses

| Status | Cause |
|---|---|
| `401` | No/invalid JWT |
| `400` | `country` missing or blank |
| `422` | `country` doesn't resolve to a real country code |

## `GET /api/scout/search` — new `ignore_country` param

The existing scout-search endpoint gains one new optional query param, alongside its existing
`rep`/`position`/`nationality`/`age_range`/`ability`/`amount` filters:

| Param | Required | Type | Notes |
|---|---|---|---|
| `ignore_country` | no | 2-letter code | Excludes this country's nationality from the results — same resolution/validation as `/api/players/foreign`'s `country` param. `422` if not a real code; omit entirely for no exclusion. |

```
GET /api/scout/search?rep=local&amount=20&ignore_country=EN
```
```json
200 OK
{
  "rep": "local",
  "amount": 20,
  "ability": 0,
  "ignoreCountry": "EN",
  "players": [ "...same shape as above, none English..." ]
}
```

`ignoreCountry` in the response is `null` when the param wasn't passed. It composes fine with
the existing `nationality` param (which *includes* only one nationality) as long as they don't
name the same country — `nationality=Spanish&ignore_country=EN` is a perfectly normal "only
Spanish, and definitely not English" combination, not a contradiction.

## Shared shape with `GET /api/scout/search`

Both endpoints now serialize players through the same `PlayerBrowseSerializer` service —
the full-detail player object shape (everything shown in the example above, including nested
`agent`/`personality`/`guardians`) is identical between them, so client-side player-card
rendering code can be shared across both screens.
