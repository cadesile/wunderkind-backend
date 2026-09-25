# Sprite Appearance & Kit/Badge Schema (API contract)

Branch: `sprites` (branched off `master`). Backend-owned generation, admin-editable,
served verbatim to clients — nothing on the frontend needs to invent or validate
these shapes, only render them.

## 1. `appearance` — Player / Staff / Scout / Agent

A nullable JSON object on `Player`, `Staff`, `Scout` and `Agent`. Always the same
15-key shape regardless of role — a saved config may hold both player-only and
staff-only keys harmlessly; each renderer ignores the keys that don't apply to it.
`null` means the entity hasn't been backfilled yet — treat as "use my own local
generator" fallback, same as before.

```json
{
  "hair": "quiff",
  "hairColor": "#8a5a2b",
  "headband": false,
  "skin": "s3",
  "face": "neutral",
  "facial": "none",
  "lip": "#c9575e",
  "primary": "#c8202f",
  "secondary": "#f4f3ee",
  "kit": "stripes",
  "shorts": "black",
  "socks": "primary",
  "outfit": "coat",
  "trousers": "black",
  "glasses": false
}
```

### Field reference

| Key | Type | Applies to | Notes |
|---|---|---|---|
| `hair` | enum `HairStyle` | all | `bald, buzz, crop, quiff, mohawk, afro, long, bun, cornrows` |
| `hairColor` | hex string | all | one of the 6 `HairColor` swatches below — value **is** the hex, not a token |
| `headband` | bool | all | cosmetic overlay |
| `skin` | enum `SkinId` | all | `s1`–`s6`, lightest → darkest. **Not a hex** — resolve via the sprite's own skin-id → base/shade color table (two shades per id: base fill + a darker shade for ears/neck/brows). See §4. |
| `face` | enum `Face` | all | fixed expression, chosen at generation time — **no longer computed from live morale**: `neutral, happy, wink, focused, shout, sad, frustrated, angry, cool` |
| `facial` | enum `FacialHair` | staff/scout/agent only | `none, stubble, beard` — players are always `none` |
| `lip` | hex string | all | one of the 6 `LipColor` swatches below |
| `primary` | hex string | all | one of the 12 `KitColor` swatches below (player kit / staff outfit trim) |
| `secondary` | hex string | all | same palette as `primary`, generation guarantees `secondary !== primary` |
| `kit` | enum `KitStyle` | player only | `plain, stripes, hoops, halves, sash, band, sleeves` — ignored for staff/scout/agent |
| `shorts` | enum `KitPart` | player only | `primary \| secondary \| white \| black` — a **reference**, not a color: resolve against this entity's own `primary`/`secondary`, or the fixed white/black |
| `socks` | enum `KitPart` | player only | same resolution rule as `shorts` |
| `outfit` | enum `Outfit` | staff/scout/agent only | `coat, track, suit, jumper` — ignored for players |
| `trousers` | enum `KitPart` | staff/scout/agent only | same resolution rule as `shorts`/`socks` |
| `glasses` | bool | staff/scout/agent only | cosmetic overlay, always `false` for players |

`KitPart` resolution (`shorts`/`socks`/`trousers`) is the one indirection in this
shape — it's a pointer into the *same object's* `primary`/`secondary`, not a
standalone color, so a shorts/socks recolor never needs a write if the kit's
`primary`/`secondary` changes.

## 2. `identity` — NpcClub kit + badge

A nullable JSON object on `NpcClub`. Nested `home`/`away` kit variants plus flat
badge keys shared by both:

```json
{
  "home": {
    "kit": "stripes",
    "primary": "#c8202f",
    "secondary": "#f4f3ee",
    "shorts": "black",
    "socks": "primary"
  },
  "away": {
    "kit": "hoops",
    "primary": "#1f4fb8",
    "secondary": "#f4f3ee",
    "shorts": "white",
    "socks": "secondary"
  },
  "badgeShape": "shield",
  "badgePattern": "plain",
  "badgeCentre": "initials",
  "initials": "FC",
  "badgeFill": "#c8202f",
  "badgeTrim": "#f4f3ee",
  "badgeSymbol": "#f2c230"
}
```

| Key | Type | Notes |
|---|---|---|
| `home.kit` / `away.kit` | enum `KitStyle` | same enum as player `kit` |
| `home.primary` / `away.primary` | hex string | `KitColor` palette |
| `home.secondary` / `away.secondary` | hex string | `KitColor` palette, `!== primary` |
| `home.shorts` / `away.shorts` | enum `KitPart` | resolves against **that variant's own** primary/secondary |
| `home.socks` / `away.socks` | enum `KitPart` | same rule |
| `badgeShape` | enum `BadgeShape` | `shield, crest, round, diamond, hex` |
| `badgePattern` | enum `BadgePattern` | `plain, stripes, hoops, halves, quarters, chevron` |
| `badgeCentre` | enum `BadgeCentre` | `none, initials, star, ball, crown` — `none` is a valid saved value (only the admin randomiser avoids rolling it) |
| `initials` | string | 0–3 chars, `[A-Z0-9]` only — sanitized server-side on every save regardless of what's submitted |
| `badgeFill` / `badgeTrim` / `badgeSymbol` | hex string | `KitColor` palette, independent of the kit's own primary/secondary (though generation biases `badgeFill` toward the home primary ~50% of the time) |

**`Club.primaryColor` / `Club.secondaryColor` are now derived, not independent.**
Saving an `identity` with a `home` kit copies `home.primary`/`home.secondary` onto
the club's existing flat `primaryColor`/`secondaryColor` columns — those two
fields still exist and are still served (see §3), but they're a read-only mirror
of the home kit for any consumer that isn't kit-aware yet. Don't write them
directly; write `identity.home` instead.

## 3. Enum value tables (exact hex / string values)

**SkinId** (base fill → shade, lightest → darkest):

| id | base | shade |
|---|---|---|
| `s1` | `#f6d3b3` | `#e0b28c` |
| `s2` | `#ecc095` | `#d39f73` |
| `s3` | `#d49c64` | `#b9804c` |
| `s4` | `#b37548` | `#965d36` |
| `s5` | `#8a5230` | `#704023` |
| `s6` | `#5c3822` | `#472a18` |

**HairColor**: `black #1c1410` · `darkBrown #4a2c1a` · `brown #8a5a2b` ·
`ginger #c8602a` · `blonde #e6bd55` · `grey #d9d5cc`

**LipColor**: `rose #c9575e` · `peach #d98a7e` · `red #b8302f` ·
`berry #a8424a` · `brown #8a4a3a` · `plum #6e2f3f`

**KitColor** (shared by `primary`/`secondary`/badge fields, player kits and staff
outfits alike): `red #c8202f` · `maroon #7a1f2b` · `orange #f07a1a` ·
`yellow #f2c230` · `green #1f8a4c` · `darkGreen #0f4d33` · `skyBlue #7fb8e6` ·
`royalBlue #1f4fb8` · `navy #1b2a4a` · `purple #5b2c83` · `white #f4f3ee` ·
`black #1a1a1a`

## 4. Endpoints that now carry these fields

| Endpoint | Shape(s) added |
|---|---|
| `POST /api/club/initialize` (starter pack + world pack) | `appearance` on every player/staff/scout snapshot; `identity` on every NPC club snapshot |
| `POST /api/initialize/starter`, `GET /api/initialize/leagues`, `POST /api/initialize/league/{tier}` | same, via `WorldInitializationService::buildTierPack`/`buildLeaguesPack` |
| `POST /api/sync` — response `league.clubs[]` | `identity` per NPC club (own league, shown to the client for opponent branding) |
| `GET /api/market/data` | `appearance` on coach/scout/agent listings |
| `POST /api/market/consume` | `appearance` on the returned player/staff snapshot |
| `GET /api/scout/search` | `appearance` on each returned player, and on the nested `agent` object |
| every player snapshot's nested `agent` object (world pack, market, scout search) | `appearance` — the shared `Agent::toSnapshotArray()` shape now includes it |

`Agent` objects are nested wherever a player is serialized, in this exact shape
(now 9 keys, was 8):

```json
{
  "id": "...", "name": "...", "commissionRate": "10.00",
  "reputation": 50, "experience": 0, "rating": 50,
  "nationality": null, "dateOfBirth": null,
  "appearance": { "...": "15-key shape from §1, or null" }
}
```

## 5. Not in this schema (known gap, flagged not fixed)

`Player.secondaryPosition` (new nullable field, same enum as `position`) was
added to the admin edit form in this same work but is **not yet serialized** in
`buildPlayerSnapshot()` or any other player payload — it's an Identity-fieldset
change, not a sprite-config one, so it was left out of this pass's scope. Flag
to backend if the frontend needs it; it's a one-line addition
(`'secondaryPosition' => $player->getSecondaryPosition()?->value` in
`WorldInitializationService::buildPlayerSnapshot()`).
