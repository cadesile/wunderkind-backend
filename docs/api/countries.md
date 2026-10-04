# Country/Nationality Mapping API (client integration)

Branch: `development` (pending commit). Backend-driven single source of truth for
nationality demonym ↔ country display label ↔ country code — replacing any local
copy of this mapping the client maintains.

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
| `code` | 2-letter code — matches `Club`/`League`/`NpcClub`'s `country` field, and the new `countryCode` field below. |

Currently 21 entries. Static data (changes only on deploy, when a new country
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

`countryCodeToFlag()` (deriving a flag emoji from a 2-letter code via Unicode
regional indicators) needs no change — just feed it a `code` value sourced
from here (or from `countryCode` below) instead of deriving it through a
nationality lookup first. The `'EN'` special-case for the England flag stays
correct either way, since the code itself is unchanged.

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
