# Club Kit Identity API (client integration)

Sets/updates the real, player-owned club's own kit + badge identity —
`homeKitConfig`, `awayKitConfig`, `badgeConfig` — the "chairman" customizes
these on-device, and they're synced up here so the club's real branding can
be shown wherever it appears publicly (the public leaderboard, and the
marketing site's live activity feed).

This is **new, additive** surface — no existing field changes shape, nothing is
removed. Every field is optional and defaults to `null` until you set it, so
existing builds that don't send anything here keep working unchanged.

## Endpoints

```
GET  /api/club/kit-identity
POST /api/club/kit-identity
Authorization: Bearer <JWT>   (ROLE_CLUB required, both)
Content-Type: application/json   (POST only)
```

`GET` takes no body/params and returns the same shape as the `POST` response
(§ below) — use it to prefill your kit/badge editor on load. Resolves to the
same club `POST /api/sync`/`GET /api/club/status` already operate on (the
account's most-recently-created club unless you send the `X-Club-Id` header —
same resolution rule as every other authenticated club endpoint).

### Example — read the current kit identity

```
GET /api/club/kit-identity
```
```json
200 OK
{
  "homeKitConfig": { "kit": "stripes", "primary": "#c8202f", "secondary": "#f4f3ee", "shorts": "black", "socks": "primary" },
  "awayKitConfig": null,
  "badgeConfig": { "badgeShape": "shield", "badgePattern": "plain", "badgeCentre": "initials", "initials": "TSD", "badgeFill": "#c8202f", "badgeTrim": "#f4f3ee", "badgeSymbol": "#f2c230" }
}
```

## Request body (`POST` only) — all fields optional, partial update

Only the keys you actually send are changed. Omit a key entirely to leave that
field untouched — send it as JSON `null` to explicitly clear it back to unset.

| Key | Type | Validation | Notes |
|---|---|---|---|
| `homeKitConfig` | `object \| null` | none (stored verbatim) | `{kit, primary, secondary, shorts, socks}` — see §1 |
| `awayKitConfig` | `object \| null` | none (stored verbatim) | same shape as `homeKitConfig` |
| `badgeConfig` | `object \| null` | none (stored verbatim) | `{badgeShape, badgePattern, badgeCentre, initials, badgeFill, badgeTrim, badgeSymbol}` — see §1 |

### Example — set the home kit only

```json
POST /api/club/kit-identity
{
  "homeKitConfig": { "kit": "stripes", "primary": "#c8202f", "secondary": "#f4f3ee", "shorts": "black", "socks": "primary" }
}
```

Leaves `awayKitConfig` and `badgeConfig` exactly as they were — a second call
setting only `badgeConfig` doesn't touch the home kit you just set.

## `POST` Response — `200 OK`

Always echoes the **full current state** of all 3 fields, regardless of which
ones you sent — same shape `GET` returns.

## 1. Shape reference

Same shapes, same enums, as `NpcClub.identity`'s per-variant kit and badge
fields — full field reference and exact enum/hex value tables:
`docs/api/sprite-appearance-schema.md` §2a and §3. In short:

- `homeKitConfig`/`awayKitConfig`: `{kit: KitStyle, primary: KitColor hex, secondary: KitColor hex, shorts: KitPart, socks: KitPart}`
- `badgeConfig`: `{badgeShape: BadgeShape, badgePattern: BadgePattern, badgeCentre: BadgeCentre, initials: string (0-3 chars, [A-Z0-9]), badgeFill/badgeTrim/badgeSymbol: KitColor hex}`

Nothing here is validated server-side beyond basic JSON shape — the client's
own kit/badge builder is the source of truth, same trust model as
`docs/api/owner-avatar.md`'s `avatar` field. Send whatever your builder
produces; there's no enum rejection to handle.

## 2. No auto-generation

Unlike `NpcClub.identity` (always generated for NPC clubs) or the owner
avatar (auto-generated on first nationality/dob save), **nothing here is ever
generated server-side.** All three fields start `null` and stay `null` until
you explicitly `POST` a value — there's no "first fill" behavior to account
for.

## 3. Where else these values now show up

Once set, the same three fields are also included (read-only) on:

| Endpoint | What it adds |
|---|---|
| `GET /api/club/status` | `homeKitConfig`/`awayKitConfig`/`badgeConfig`, mirrored alongside the club's other state, for convenience if you're already polling this |
| `GET /api/leaderboard/{category}` | the same three fields on **every entry**, including opponent clubs — so a leaderboard row can render a real club's kit/badge, not just its name |

These are the same values you set via this endpoint's `POST` — nothing new to
learn about the shape, just where else you can read it back from.

## 4. Error responses

| Status | Cause |
|---|---|
| `401` | No/invalid JWT |
| `404` | No club resolved for this account (see `ClubResolver`'s resolution rule) |

There is no `422` case for this endpoint — every field is a freeform object
with no server-side validation (see §1).
