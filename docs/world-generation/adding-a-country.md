# Adding a new playable country

This is the repeatable process for standing up a new country's league pyramid, NPC clubs,
player/staff/scout pool, and world pack cache. It replaces the ad hoc "add a case here, a
const there" approach that caused real drift bugs twice before (`App\Enum\Country`'s own
docblock, and `docs/superpowers/specs/2026-07-06-club-name-options-canonical-source-design.md`).

## 1. Is the country already in `App\Enum\Country`?

Check `src/Enum/Country.php`. If it's one of the 30+ existing cases, skip to step 3.

If not — a genuinely new country (e.g. adding Poland as a real playable country, not just a
name pool) — add a new `case`, plus one `match` arm each in `label()`, `nationality()`,
`locale()`. Leave `isGenerationCapable()` returning `false` for it until step 3 is done.

## 2. Author the club-naming content

**You no longer need a deploy for the three naming categories.** `prestigeSuffixes`,
`genericSuffixes` and `stadiumFormats` can be authored from **Locations & land → Dashboard →
CLUB-NAMING CONTENT**, stored per country on `country_profile`, with the PHP consts below as
the fallback. That card is what the readiness panel's **AUTHOR CONTENT** button jumps to.

- **One token per line** — not commas and not JSON. The shipped data contains `1. FC`,
  `Città di` and `Estadio Municipal de %s`, so any separator that can appear inside a token is
  a trap waiting on the first country that uses one.
- **Stadium formats are `sprintf` templates**: exactly one `%s`, and no other percent sign
  anywhere. A bad one is refused at the form rather than becoming a fatal inside club
  generation later.
- **SUGGEST WITH AI** asks the configured provider for all three lists in one billed call.
  What comes back is validated and stored, and lands in the fields for you to read over —
  these are culture rather than fact, and the model is better at some countries than others.
  It will not replace a list you authored unless you tick the box.
- **REVERT TO BUILT IN** drops your lists and goes back to the PHP consts.
- Changing any of this affects **clubs generated from now on**. Names already given to clubs
  are their own and never change, and clients keep the old stadium names until that country's
  world pack is rebuilt (`app:worldpack:warm <CODE>`).

Everything else in the registry — places, world region, suffix order, business cluster — is
still PHP, and still needs the pass below.

## 2a. Author the rest in `CountryContentRegistry`

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
  *(or author these in the admin, per §2 — the consts are the fallback)* —
  club-name suffix tokens reflecting real local football-club naming conventions. Prestige
  suffixes get used for BIG-city clubs, generic for SMALL (MEDIUM picks randomly between the
  two) — see `pickSuffixForCitySize()`. Names are composed as `"{place} {suffix}"`.
- **`STADIUM_FORMATS_BY_COUNTRY`** (~4-6 `sprintf` templates, one `%s` placeholder)
  *(or author these in the admin, per §2)* —
  reflecting local stadium-naming convention.
- **`WORLD_REGIONS`** — map the country's code to the closest existing `WorldRegion` case
  (skin-tone distribution for appearance generation). Add a new `WorldRegion` case (with a
  `skinWeights()` entry summing to exactly 100 — `WorldRegionTest` enforces this) only if no
  existing region fits.
- **`SUFFIX_LEADS_NAME`** — *only* if the country's clubs put the club's own name before the
  place. Club names are composed `"{place} {suffix}"` by default (Madrid CF, Lisboa SC), which
  is wrong for countries like Poland, where every club reads `Legia Warszawa` / `Górnik Zabrze`.
  Adding `'<CODE>' => true` flips the order for that country only. Leave it alone otherwise —
  it is opt-in, and `PolandContentTest` asserts every other country keeps trailing order.
- **`BUSINESS_CLUSTERS`** — map the code to an existing cluster key in
  `MarketPoolService::BUSINESS_CLUSTERS`, or add a new cluster (plus ~8 evocative location
  words) there if none fit. This only affects sponsor/investor flavor company names.

Any category you skip degrades gracefully to a generic fallback (bland placeholder city names,
`['FC']` suffixes, `'%s Stadium'` formats, uniform skin-tone, `_fallback` business words) —
nothing errors, but the result looks obviously generic. `app:country:check` (step 5) tells you
exactly what's missing.

## 2b. Author the player and staff name pools

**This is no longer a PHP step either.** Every player, coach, scout, agent and guardian
generated for a country is named from two lists — first names and surnames — and they can be
authored from **Locations & land → Dashboard → PLAYER & STAFF NAMES**, stored per country on
`country_profile.first_names` / `.last_names`, with `NamePoolRegistry` as the fallback.

**Thirty-two of the seventy-one cases ship with a pool; thirty-nine do not** — including every
country added in the 2026-10-25 UEFA batch. A country without one has its people named in
English, which `app:country:check` reports and `app:pool:warm` warns about once per country.
It is not a go-live blocker, but a Norwegian league full of Alfies and Fitzroys reads like a
translation.

- **One name per line.** The pools contain `Van der Berg` and `O'Neill`, so a separator that
  can appear inside a name is the same trap as above.
- **Target 150 of each**, which is what English and Spanish hold — about 22,500 distinct full
  names against the 500 players a warm generates. The floor is 10; below that the form refuses
  the list rather than storing a pool that repeats absurdly.
- **Diacritics are kept, and must be.** `Güneş` and `Gunes` are two different names here; the
  dedupe folds case only. An anglicised pool is the pool the game already ships.
- **TOP UP WITH AI** asks the configured provider for *the shortfall only*, one billed call per
  list, with the names already held sent as exclusions. A country at target costs nothing, so
  clicking it twice is free. Authored names are never replaced — only extended.
- **A list identical to the shipped one is stored as "built in", not as a copy**, so a later
  PR adding names still reaches the country.
- **CLI twin:** `app:names:top-up <CODE> [--list firstNames|lastNames] [--dry-run]`. Use
  `--dry-run` to see the shortfall without spending, and read the names it prints — they are
  culture, not fact, and the model is better at some countries than others.

**Nobody already generated is renamed, and this will read as a bug.** Names are persisted
columns. Author 150 Albanian names, look at the pool page, and you still see 500
English-named "Albanian" players — the save worked; those rows are simply older than it. To
see the difference you have to clear the pool and warm it again: **Player pool config → CLEAR
PLAYER POOL** (a global clear — there is no per-country one), then `app:pool:warm <CODE>
--force`. NPC squads additionally live in `country_world_pack_cache.payload` and are served
to clients until that country's pack is rebuilt (`app:worldpack:warm <CODE>`) — and when it
is, **every NPC squad in the country is renamed at once**, including clubs a player has
already seen in a league table or scouted. Persisted `player` rows are immutable; cached NPCs
are not. Rebuild deliberately, not as a reflex after editing a pool.

To add a pool to the shipped fallback instead (a PR, not a deploy-free change), add a
`'Nationality' => ['firstNames' => [...], 'lastNames' => [...]]` block to
`NamePoolRegistry::POOLS`. It is keyed off `Country::nationality()`, and
`NameGeneratorNationalityCoverageTest` pins the count so a duplicate array key cannot silently
swallow one.

### Flag asset (every case, not just generation-capable ones)

`public/assets/flags/<code>.svg`, lower-cased. The Owner Spotlight builds its `<img src>`
straight from the case name with no existence check, so a missing file is a broken image on
the public landing page, not a caught error. `CountryFlagAssetTest` turns that into a red
build — grab the file from
`https://cdn.jsdelivr.net/npm/flag-icons@7.5.0/flags/4x3/<iso-code>.svg` and save it under
the `Country` code, which differs from the upstream code for nations that are a GB
subdivision upstream (England, Scotland, Wales). See that directory's README.

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
- `--enable` — also mark the country **LIVE**, which is what makes it player-visible (see
  step 6). Refused if the readiness checks do not pass. Only meaningful on a *first*
  bootstrap: club generation below is additive, so passing `--enable` to a country that is
  already bootstrapped gives it a second full set of clubs.
- `--enable-only` — promote to LIVE and do nothing else. This is the one to use on an
  already-bootstrapped country.

### The ability-range step on its own, from the admin

The seed-if-missing of `StarterConfig::leagueAbilityRanges[<CODE>]` is also a button:
**Locations & land → Dashboard**, the READINESS panel's **ABILITY RANGES** row carries
**SEED DEFAULTS** while that check is failing. Both go through
`StarterAbilityRangeService::seedDefaults()`, so they cannot drift.

It exists because the blocker used to name this command and nothing else — and the Starter
config screen could not help either: its grid is built from countries that already have League
rows, so a country with no pyramid yet has no row to type into. The button is free, writes no
clubs, and does nothing to a country that already has a band.

## 5. Verify

```bash
lando php bin/console app:country:check <CODE>
```

Read-only report: enum/capability status, which registry categories are present vs. falling
back to generic, whether a name pool exists, league/NPC-club/worldpack-cache coverage per
tier, whether `StarterConfig` has an ability-range entry, and whether the country's profile
is LIVE (which is what player-visibility means).

Then check the pool can actually serve a new club in that country:

```bash
lando php bin/console app:starter-pack:check <CODE>          # tier 8, where new clubs start
```

Or in the browser: **Configuration → Starter Pack Check** (`/admin?routeName=admin_starter_pack_check`).
Both are read-only — counts only, nothing is created or consumed, so they are safe to run
against production.

`app:country:check` can report a country as fully stood up while club creation still fails:
it checks that *content and world data* exist, not that the **player pool** holds players who
match the starter draw. That draw filters on the tier's ability band **and**
`PoolConfig::$playerFirstTeamAgeMin` at once, and those two can each be well-stocked while
their intersection is empty — most often when a tier's configured band sits inside the youth
ability ceiling, so every player low enough to match is too young to be drawn. This check
reports the draw filter by filter and names the step where the count collapses.

## 6. Decide whether to open it to players

A country is offered to players **exactly when its `CountryProfile` is LIVE**. There is no
separate list: `StarterConfig::enabledCountries` used to be one, and it is gone — a
hand-ticked list could and did disagree with the readiness it was meant to describe, ticked
while a profile was still `DRAFT` or left un-ticked long after the country went live. The
column remains on the entity, documented as superseded and read by nothing.

Generating a country and opening it are still separate decisions: a country can be fully
generated and sit at `TIER_READY` indefinitely. What changed is that the second decision is
now recorded on the country itself rather than in a list beside it.

**Promotion is gated.** `CountryReadinessService` must pass first — seven checks covering
leagues, ability ranges, registry content, locations, plots, enrichment and sellable towns.
All three ways in go through `CountryGoLiveService`, so they agree:

- **Locations → Dashboard** (`/admin?routeName=admin_locations_dashboard`) — pick the country
  and use **GO LIVE**. The button is disabled until every check passes, and the checks run
  again on submit, because an enabled button is not a check.
- **Countries CRUD** (`/admin?routeName=admin_country_profile_index`) — setting the status to
  Live is refused with the blocking reasons if it is not ready.
- The CLI:

```bash
lando php bin/console app:country:bootstrap <CODE> --enable-only
```

**Use `--enable-only`, not `--enable`, on a country you have already bootstrapped.** Step 2's
club generation is additive — it creates `clubs-per-tier` clubs on every run unless
`--delete-existing` is passed — so a second `--enable` run to flip visibility would quietly
double the country's club count. `--enable-only` skips every generation step, and is a no-op
if the country is already live.

`locationsLiveAt` is stamped the first time a country reaches LIVE and is never rewritten, so
a country that goes back to `DRAFT` and returns keeps its original date.

## League Tier Defaults (financials, trophies, sponsors) — country-independent

The financial/trophy/sponsor defaults every new league gets (promotion spots, TV deal, prize
money, position pot, sponsor count, trophy design/colour) are **not** per-country — they're a
single per-*tier* table, shared by every country, editable at
`/admin?routeName=admin_leagues_overview` under "League Tier Defaults". That screen writes to
`GameConfig::leagueTierDefaults`, which `LeagueService::generateLeaguesForCountry()` reads for
every country going forward — nothing in this per-country process above needs to touch it.
