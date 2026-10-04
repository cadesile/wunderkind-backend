# Adding a new playable country

This is the repeatable process for standing up a new country's league pyramid, NPC clubs,
player/staff/scout pool, and world pack cache. It replaces the ad hoc "add a case here, a
const there" approach that caused real drift bugs twice before (`App\Enum\Country`'s own
docblock, and `docs/superpowers/specs/2026-07-06-club-name-options-canonical-source-design.md`).

## 1. Is the country already in `App\Enum\Country`?

Check `src/Enum/Country.php`. If it's one of the 19+ existing cases, skip to step 3.

If not — a genuinely new country (e.g. adding Poland as a real playable country, not just a
name pool) — add a new `case`, plus one `match` arm each in `label()`, `nationality()`,
`locale()`. Leave `isGenerationCapable()` returning `false` for it until step 3 is done.

## 2. Author the content in `CountryContentRegistry`

`src/Service/CountryContent/CountryContentRegistry.php` is the single file holding everything
that used to be scattered across `NpcClubGenerationService`, `WorldRegion`, and
`MarketPoolService`. Add an entry for the new country's ISO code to each of:

- **`PLACE_NAMES_BY_COUNTRY`** — the hard part, and the one this process exists to make
  repeatable. Each entry is `['name' => ..., 'population_size' => ..., 'region' => ...,
  'is_capital' => true]` (the last key omitted for non-capitals). Practical guidance:
  - **~28-34 real cities is a good target** — enough for `classifyPlaces()`'s 20%/30%/50%
    BIG/MEDIUM/SMALL split (see below) to produce real variety across all 8 league tiers.
  - **You do not need to hand-sort cities into tiers.** `NpcClubGenerationService::
    classifyPlaces()` ranks every place by `population_size` and classifies the top 20% as
    `BIG`, the bottom 50% as `SMALL`, the rest `MEDIUM` — then `GameConfig::
    getNpcClubSizeWeightsForTier($tier)` (tier-1 skews toward BIG, tier-8 toward SMALL) does
    the actual tier-weighting at generation time. Just supply a realistic population spread.
  - **`region`** is the country's own real subdivision name (state/province/prefecture/
    county) — used as flavor text on the generated `NpcClub`, not matched against anything.
  - **`is_capital: true` goes on the true seat of government even when it isn't the largest
    city** — `classifyPlaces()` forces `is_capital` entries to `BIG` regardless of population
    rank. Nigeria's Abuja (not Lagos) is the existing precedent.
  - Source real population figures (a national statistics bureau or a reputable current
    population listing) — precision doesn't need to be exact/current, just real and
    plausible, matching the existing data's own level of precision (whole-number populations).
- **`ALL_PLACE_NAMES_BY_COUNTRY`** — only needed if you're curating a subset of a much larger
  historical city list (see the ES/EN entries, "preserved for Task 5"). For a new country it's
  fine to make this equal to the flat name list of what you put in `PLACE_NAMES_BY_COUNTRY` —
  `getRemainingPlaceNames()` then correctly returns `[]`.
- **`PRESTIGE_SUFFIXES_BY_COUNTRY`** (~10) and **`GENERIC_SUFFIXES_BY_COUNTRY`** (~15-20) —
  club-name suffix tokens reflecting real local football-club naming conventions. Prestige
  suffixes get used for BIG-city clubs, generic for SMALL (MEDIUM picks randomly between the
  two) — see `pickSuffixForCitySize()`. Names are composed as `"{place} {suffix}"`.
- **`STADIUM_FORMATS_BY_COUNTRY`** (~4-6 `sprintf` templates, one `%s` placeholder) —
  reflecting local stadium-naming convention.
- **`WORLD_REGIONS`** — map the country's code to the closest existing `WorldRegion` case
  (skin-tone distribution for appearance generation). Add a new `WorldRegion` case (with a
  `skinWeights()` entry summing to exactly 100 — `WorldRegionTest` enforces this) only if no
  existing region fits.
- **`BUSINESS_CLUSTERS`** — map the code to an existing cluster key in
  `MarketPoolService::BUSINESS_CLUSTERS`, or add a new cluster (plus ~8 evocative location
  words) there if none fit. This only affects sponsor/investor flavor company names.

Any category you skip degrades gracefully to a generic fallback (bland placeholder city names,
`['FC']` suffixes, `'%s Stadium'` formats, uniform skin-tone, `_fallback` business words) —
nothing errors, but the result looks obviously generic. `app:country:check` (step 5) tells you
exactly what's missing.

### Name pool (only if the nationality has none yet)

Check `NameGeneratorService::getNamePools()` for the country's `nationality()` string. Most
existing countries already have one (it's the oldest, most complete per-country data in the
codebase). If not, add a `'Nationality' => ['firstNames' => [...], 'lastNames' => [...]]` block
there directly — this file is intentionally not part of the registry above (too large/
sensitive to move wholesale), but it's still keyed off `Country::nationality()` via
`NameGeneratorService::nationalities()`, not a separate hand-maintained list.

## 3. Flip `isGenerationCapable()`

Once the registry has real content, add the country's case to the `true` branch of
`Country::isGenerationCapable()`. This is what makes it appear in every admin "Generate"
dropdown automatically (`Country::generationCapableLabels()` drives all of them — see
`DashboardController`).

## 4. Run the bootstrap command

```bash
lando php bin/console app:country:bootstrap <CODE>
```

This chains: `LeagueService::generateLeaguesForCountry()` (8 tiers) → `NpcClubGenerationService::
generateClubs()` (once per tier) → seeds `StarterConfig::leagueAbilityRanges[<CODE>]` if missing
→ `app:pool:warm <CODE>` → `app:worldpack:warm <CODE>` (all tiers). Useful flags:

- `--clubs-per-tier=N` (default 8)
- `--force` — proceed even with missing registry content (generic placeholders)
- `--delete-existing` — wipe and regenerate NPC clubs per tier
- `--skip-pool` / `--skip-worldpack` — if you want to run those steps separately
- `--enable` — also add the country to `StarterConfig::enabledCountries` (see step 6)

## 5. Verify

```bash
lando php bin/console app:country:check <CODE>
```

Read-only report: enum/capability status, which registry categories are present vs. falling
back to generic, whether a name pool exists, league/NPC-club/worldpack-cache coverage per
tier, and whether `StarterConfig` has an ability-range entry and the player-visibility flag.

## 6. Decide whether to make it player-visible

`StarterConfig::enabledCountries` is a *separate*, deliberately decoupled concept from
generation-capability (see `Country`'s own class docblock) — a country can be fully generated
and still hidden from the landing page / club-creation flow until an admin flips it on via the
Starter Config screen (`/admin?routeName=admin_starter_config`), or by re-running the bootstrap
command with `--enable`.

## League Tier Defaults (financials, trophies, sponsors) — country-independent

The financial/trophy/sponsor defaults every new league gets (promotion spots, TV deal, prize
money, position pot, sponsor count, trophy design/colour) are **not** per-country — they're a
single per-*tier* table, shared by every country, editable at
`/admin?routeName=admin_leagues_overview` under "League Tier Defaults". That screen writes to
`GameConfig::leagueTierDefaults`, which `LeagueService::generateLeaguesForCountry()` reads for
every country going forward — nothing in this per-country process above needs to touch it.
