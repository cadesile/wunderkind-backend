# Country/Nationality Mapping API (client integration)

Branch: `development`/`dev`/`master` (merged). Backend-driven single source of
truth for nationality demonym ↔ country display label ↔ country code —
replacing any local copy of this mapping the client maintains.

## Why this exists

The backend already has one canonical mapping (`App\Enum\Country`) that every
generation path (club/league country codes, player/staff/scout nationality
names) derives from. The client previously had no way to read that mapping —
so `wunderkind-app`'s `src/utils/nationality.ts` grew its own independent
copies (`NATIONALITY_TO_CODE`, `NATIONALITY_TO_CLUB_CODE`/
`CLUB_CODE_TO_NATIONALITY`, `CLUB_COUNTRIES`). Those tables can drift from the
backend's — confirmed in practice: newly-added nationalities (e.g.
"American") need a manual entry added on the client side or they silently
fall through to wrong/guessed codes. This endpoint, plus the new `countryCode`
field described in §2, let the client read the mapping directly instead of
maintaining a second copy of it.

## 1. `GET /api/countries`

Public, no auth required. Returns every supported nationality/country/code
triplet as a flat array — one row per backend `Country` case.

```
GET /api/countries
```
```json
200 OK
[
  { "name": "English",  "country": "England", "code": "EN" },
  { "name": "Italian",  "country": "Italy",    "code": "IT" },
  { "name": "American", "country": "USA",      "code": "US" },
  { "name": "Canadian", "country": "Canada",   "code": "CA" }
]
```

| Field | Meaning |
|---|---|
| `name` | Nationality demonym — matches `Player`/`Staff`/`Scout`'s `nationality` field **exactly** (case-sensitive), e.g. `"English"`, `"American"`. |
| `country` | Display label for the country, e.g. `"England"`, `"USA"`. |
| `code` | 2-letter code — matches `Club`/`League`/`NpcClub`'s `country` field, and the new `countryCode` field below. **Not an ISO 3166-1 code in every case** — see below. |
| `isoAlpha2` | The real ISO 3166-1 alpha-2 code, or `null` where none exists. Equal to `code` for 67 of the 72 entries. |
| `isIsoCode` | `true` when `code` and `isoAlpha2` agree. `false` for the five exceptions. |

Currently 72 entries. Static data (changes only on deploy, when a new country
is added backend-side) — response is sent with `Cache-Control: public,
max-age=3600`, safe to cache for the session rather than refetching per
screen.

**Build your lookup maps from this response instead of hardcoding them.**
Anywhere the client currently does `NATIONALITY_TO_CODE[nationality]` or
`CLUB_CODE_TO_NATIONALITY[code]`, fetch this list once (e.g. at app boot,
alongside `/api/starter-config`) and derive both directions from it:

```ts
const countries = await fetch('/api/countries').then(r => r.json());
const nameToCode = Object.fromEntries(countries.map(c => [c.name, c.code]));
const codeToName = Object.fromEntries(countries.map(c => [c.code, c.name]));
```

### `code` is a football code, not always an ISO one

Five of the 72 codes are **not** ISO 3166-1 alpha-2, and two of them collide with real ISO
codes belonging to entirely different countries:

| `code` | Country | `isoAlpha2` | Why |
|---|---|---|---|
| `EN` | England | `GB` | England has no ISO country code of its own. |
| `SC` | Scotland | `GB` | **ISO `SC` is Seychelles.** |
| `WA` | Wales | `GB` | No ISO code. |
| `NI` | Northern Ireland | `GB` | **ISO `NI` is Nicaragua.** |
| `XK` | Kosovo | `null` | `XK` is user-assigned; there is genuinely no ISO code, and substituting `RS` would assert Serbian sovereignty. |

**Which one to use.** Read `code` to match anything in our own data — `Club.country`,
`League.country`, `GET /api/locations?country=`, flag asset filenames. Read `isoAlpha2` when
handing a country to something outside this system that expects a real ISO code: a geo or
currency API, or an ISO-keyed flag set. Never derive one from the other.

`code` keeps its existing meaning and values — `isoAlpha2` and `isIsoCode` are purely
additive, so nothing that already reads `code` needs to change.

`countryCodeToFlag()` (deriving a flag emoji from a 2-letter code via Unicode
regional indicators) needs no change — just feed it a `code` value sourced
from here (or from `countryCode` below) instead of deriving it through a
nationality lookup first. The `'EN'` special-case for the England flag stays
correct either way, since the code itself is unchanged. **Do not switch that function to
`isoAlpha2`** — it would render the Union Jack for Scotland, Wales and Northern Ireland, and
nothing at all for Kosovo. The server's own flag assets are keyed on `code` for exactly this
reason (`public/assets/flags/README`).

## 2. New `countryCode` field on generated players/staff/scouts

Every `Player`/`Staff`/`Scout` object returned from world-pack generation
(club tier packs, `GET /api/initialize/league/{tier}`) and starter-pack
initialization (`POST /api/initialize/starter`) now includes a `countryCode`
field alongside the existing `nationality` field:

```json
{
  "firstName": "Noah",
  "lastName": "Williams",
  "nationality": "American",
  "countryCode": "US",
  "...": "..."
}
```

Derived server-side from the exact same mapping `/api/countries` exposes —
guaranteed consistent with it, no client-side lookup needed at all when a
player/staff/scout object is already in hand. Can be `null` only if
`nationality` itself is an empty string (Staff/Scout's `nationality` is
nullable; Player's is never null).

**This is additive** — `nationality` is unchanged and still present; existing
client code reading it keeps working. `countryCode` is new, safe to ignore
until you're ready to migrate.

## 3. Not covered by this change

- `CLUB_COUNTRIES` (the original 9-country hardcoded list used for
  neighbour/reputation logic) is a separate, still-legacy table on the client
  — this change doesn't touch it. `GET /api/starter-config`'s
  `enabledCountryOptions` (`{code, label}` pairs, already backend-driven)
  remains the source for the onboarding country picker specifically; `/api/countries`
  is the broader nationality/flag mapping, covering every supported
  nationality regardless of whether that country is currently
  player-selectable.
- No existing endpoint or field changed shape — this is purely additive
  (one new endpoint, one new field).

## 4. Error responses

None — `GET /api/countries` always returns `200` with the full list.
