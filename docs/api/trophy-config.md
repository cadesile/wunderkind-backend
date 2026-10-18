# Trophy Config (API contract)

Replaces the old `trophyImage` + `trophyColour` pair on leagues and competition
templates with a single `trophy` object: the 16-bit trophy sprite's own
`TrophyConfig`, stored and served verbatim.

Sibling of `sprite-appearance-schema.md` — same contract style as
`appearance`, `identity` and `homeKitConfig`: the backend owns the stored
value, the client owns the drawing, and nothing in between reshapes it.

## Why it changed

The old model named a **static asset**: `trophyImage` was one of
`trophy-1`..`trophy-15`, served as `/images/trophies/{slug}.svg` — a black
silhouette that each surface recoloured with its own CSS filter chain, driven
by `trophyColour` (`silver` / `gold` / `gold_silver`). That meant:

- the artwork was a file, so "Trophy 7" told nobody what the trophy looked like;
- the recolouring logic was duplicated per surface (the admin leagues screen and
  the landing page's honours board each carried their own copy of the filter
  chains, with a comment telling future readers to keep them in step);
- only the whole silhouette could be coloured — no per-part finish, no ribbons.

The new model names a **design**: `classic`, `goblet`, `urn` … procedurally
drawn by `TrophySprite` (`assets/Components/trophy16/trophy16.ts`), the same
component the app and the backend's admin both render with. One renderer, no
filter chains, and the colour vocabulary is unchanged apart from a rename
(see §2).

## 1. Shape

```json
{
  "style": "classic",
  "finish": "gold",
  "ribbons": true,
  "ribbonPrimary": "#c8202f",
  "ribbonSecondary": "#f4f3ee"
}
```

| Key | Type | Values |
|---|---|---|
| `style` | string | `classic` · `goblet` · `loving` · `urn` · `chalice` · `tall` · `winged` · `league` · `ball` · `bowl` |
| `finish` | string | `gold` · `twotone` · `silver` |
| `ribbons` | bool | |
| `ribbonPrimary` | `#rrggbb` | one of the 12 shared kit colours (below), lower case |
| `ribbonSecondary` | `#rrggbb` | same palette |

All five keys are always present when `trophy` is non-null. **`trophy` itself is
nullable** and `null` is meaningful: "this division/cup awards no trophy". Render
nothing (or a count-only chip, as the honours board does) — do **not** substitute
a default cup, or every unconfigured league will look like it awards silverware.

Ribbon colours are the **same 12-colour palette as kits** (`KitColor` /
`KIT_COLORS`), deliberately, so a trophy's ribbons can be set to a club's own
colours without a second palette:

```
#c8202f red     #7a1f2b maroon   #f07a1a orange     #f2c230 yellow
#1f8a4c green   #0f4d33 dk green #7fb8e6 sky blue   #1f4fb8 royal blue
#1b2a4a navy    #5b2c83 purple   #f4f3ee white      #1a1a1a black
```

Behaviour the sprite decides for you, with nothing to configure:

- **`twotone`** — silver cup body; gold rims, bands, foot rim, knot, handles,
  finial, medallion, plaques and ball.
- **Ribbons on a handled style** (`classic`, `goblet`, `loving`, `winged`,
  `league`) tie a knot on each handle: outer tail primary, inner tail secondary.
  On the others a bow is tied on the stem, with tails stopping above the plinth.
- `classic` has an engraved medallion; `ball` has ball panels.

## 2. Migrating from `trophyImage` / `trophyColour`

Already applied server-side — this is here so a client reading old cached
payloads knows the correspondence.

**Finish maps 1:1, with one rename.** No option was lost:

| old `trophyColour` | new `finish` |
|---|---|
| `gold` | `gold` |
| `silver` | `silver` |
| `gold_silver` | `twotone` |
| *(unset)* | `gold` |

**Style has no artwork correspondence** — the 15 retired files were hand-drawn
silhouettes, the 10 styles are procedurally drawn shapes. The slugs were spread
across the styles in order rather than matched by eye, which keeps whatever
variety an admin had already configured instead of collapsing everything onto
`classic`. In practice only `trophy-1`..`trophy-8` were in use (one per league
tier), so every tier landed on a distinct style:

| | | | | | | | | | |
|---|---|---|---|---|---|---|---|---|---|
| `trophy-1` | `-2` | `-3` | `-4` | `-5` | `-6` | `-7` | `-8` | `-9` | `-10` |
| classic | goblet | loving | urn | chalice | tall | winged | league | ball | bowl |

`trophy-11`..`trophy-15` wrap back onto `classic`..`chalice`. A null or
unparseable slug became `null` — "no design set" meant "no trophy", even where a
colour was set.

Migrated trophies keep **ribbons on** in the sprite's default red/white: the old
design had no ribbon concept, and defaulting them off would make every converted
trophy look plainer than the one it replaced.

## 3. Endpoints carrying `trophy`

`trophyImage` and `trophyColour` are **gone** from every payload below — this is
a replacement, not an addition.

| Endpoint | Where |
|---|---|
| `GET /api/initialize/leagues` | per league row |
| `POST /api/club/initialize` | each league in the leagues pack; the tier pack's own `trophy` |
| `GET /api/competitions/available` | per available competition |
| `GET /api/competitions/active` | the active competition |
| `GET /api/competitions/{id}` | the competition instance |

For competitions the value is read **live off the `CompetitionTemplate`**, not
copied onto the instance — so re-skinning a cup updates every competition
already running under it.

## 4. Rendering

```tsx
import TrophySprite from '@/components/trophy-sprite/TrophySprite';

{league.trophy && <TrophySprite config={league.trophy} scale={3} />}
```

Pass the stored object straight through. `TrophySprite` merges it over
`DEFAULT_TROPHY_CONFIG`, so a payload from an older backend that is missing a
key still renders.

Validate defensively if you cache payloads across releases:
`validateTrophyConfig()` keeps known keys, checks the enums, accepts only
`^#[0-9a-fA-F]{6}$` colours, falls back per key and never throws — the backend's
`TrophyConfigFactory::validate()` does exactly the same job in PHP, in front of
admin form posts and the world import.
