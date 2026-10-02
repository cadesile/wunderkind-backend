# Club List (client integration)

Returns every club the authenticated account owns, each with a brief identity + last-sync
summary — e.g. for a save-slot picker. This is **new, additive** surface; no existing
endpoint changes shape.

## Endpoint

```
GET /api/club/all
Authorization: Bearer <JWT>   (ROLE_CLUB required)
```

Unlike every other `/api/club/*` endpoint, this does **not** resolve to a single club via
the `X-Club-Id` header — it deliberately returns all of them. A user can own more than one
club (one per save slot); every other endpoint on this controller picks just one (the
header-named club, or the newest if no header is sent), which is exactly why this one
exists — there was no way to even see the others before.

There is no equivalent lookup by an arbitrary user ID — this always returns the
JWT-authenticated caller's own clubs, same as every other endpoint in this API.

### Example

```json
GET /api/club/all

200 OK
{
  "clubs": [
    {
      "id": "0199...",
      "name": "Thornfield United",
      "country": "EN",
      "abbreviation": "THU",
      "reputation": 420,
      "balance": 1250000,
      "hasDebt": false,
      "totalCareerEarnings": 8400000,
      "hallOfFamePoints": 12,
      "homeKitConfig": { "kit": "stripes", "primary": "#c8202f", "secondary": "#f4f3ee", "shorts": "black", "socks": "primary" },
      "awayKitConfig": null,
      "badgeConfig": { "badgeShape": "shield", "badgePattern": "plain", "badgeCentre": "initials", "initials": "THU", "badgeFill": "#c8202f", "badgeTrim": "#f4f3ee", "badgeSymbol": "#f2c230" },
      "lastSync": {
        "weekNumber": 12,
        "syncedAt": "2026-10-01T12:00:00+00:00",
        "leaguePosition": 4,
        "form": ["W", "L", "D"]
      }
    },
    {
      "id": "0199...",
      "name": "Second Save FC",
      "country": "EN",
      "abbreviation": null,
      "reputation": 0,
      "balance": 0,
      "hasDebt": false,
      "totalCareerEarnings": 0,
      "hallOfFamePoints": 0,
      "homeKitConfig": null,
      "awayKitConfig": null,
      "badgeConfig": null,
      "lastSync": null
    }
  ]
}
```

## Fields

| Key | Type | Notes |
|---|---|---|
| `id` | `string` (UUID) | Pass as `X-Club-Id` on every other endpoint to act on this club. |
| `name`, `country`, `abbreviation` | | Same values as `GET /api/club/status`. |
| `reputation`, `balance`, `hasDebt`, `totalCareerEarnings`, `hallOfFamePoints` | | Same values/units as `GET /api/club/status` — `balance`/`totalCareerEarnings` are real pence. |
| `homeKitConfig`, `awayKitConfig`, `badgeConfig` | `object \| null` | Same shape as `docs/api/club-kit-identity.md`. |
| `lastSync` | `object \| null` | `null` if this club has never completed a sync. |
| `lastSync.weekNumber` | `int` | The club's own last-synced week (authoritative — set on every accepted sync). |
| `lastSync.syncedAt` | `string` (ISO 8601) | Real-world server receipt time of that sync. |
| `lastSync.leaguePosition` | `int \| null` | From the most recent *valid* sync's payload — can be `null` even when `lastSync` itself isn't, if no sync has reported a position yet. |
| `lastSync.form` | `string[]` | `["W","L","D",...]`, most-recent-first, from the most recent *valid* sync's payload. Empty array if none reported yet. |

`leaguePosition`/`form` are sourced from the latest **valid** sync specifically (a sync
flagged invalid by the week-rollback anti-cheat check is skipped) — `weekNumber`/`syncedAt`
are not, since those two are plain columns on the club updated on every accepted sync
regardless of validity.

## What this does NOT include

Per-club dividend draws / the user's overall centralized balance (see
`docs/api/user-ledger.md`) are not part of this response — there is no public endpoint for
those yet either. Ask if/when the app needs to show that alongside this list.

## Error responses

| Status | Cause |
|---|---|
| `401` | No/invalid JWT |

There is no `404` case — an account with zero clubs gets `{"clubs": []}`, not an error.
