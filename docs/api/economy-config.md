# Economy configuration — what the backend publishes and what the app does with it

Added 2026-11-12. **Everything here is additive and ships switched off.** A build that predates
it reads none of these keys and behaves exactly as before.

## The one rule that governs all of it

**Every money figure the backend publishes is BASE-YEAR. The app applies inflation.**

The backend stores and publishes only `inflationRatePerSeasonBp` — a rate, never an index, never
an inflated value. Nothing on the server is stored inflated, no generated row carries an index,
and no world pack does: the pack cache is keyed `(country, tier)` with **no season dimension**,
so an inflated pack would be wrong for every save sitting at a different season.

The server computes an index from the rate for exactly one purpose — the owner's season
investment ceiling, which has to be checked against the season being claimed for.

## Where to read what

| Surface | Carries | Why there |
|---|---|---|
| `GET /api/starter-config` → `economy` | baseline values + factor **tables** | Public, unauthenticated, country-agnostic. No club to resolve a country from — same reason `userStartingBalanceMin/MaxPence` publishes a range here rather than a resolved grant. |
| `POST /api/sync` → `gameConfig` | the **resolved** per-tier table for the club's country, plus four gameplay constants | The club is known, so the country is known. |
| `GET /api/game-config` | the same six keys; `?country=` **optional** | Public, so the country has to be asked for. Omit it and you get parity with `country: null`. |

Do not read the same figure off two surfaces and expect it to be the same thing: the
starter-config block is the **authoring** view (baseline values and the factors that would be
applied), the sync/game-config block is the **resolved** view (what this country's tiers are
actually worth).

## `economyTierBaseline` — the resolved table

```json
{
  "country": "EN",
  "tierCount": 8,
  "wageModel": "legacy",
  "weeksPerSeason": 46,
  "wageCurveExponentBp": 25000,
  "reputationSpreadBp": 0,
  "wageScaleUnit": 1000000,
  "tiers": [
    { "tier": 1, "turnoverPence": 0, "wageScaleMicroPence": 0, "resolvable": false }
  ]
}
```

**`country` is null when no `?country=` was sent**, which is what every shipped build gets. That
is not an error state — it resolves at parity and the table is complete and usable.

**`tiers` has exactly `tierCount` rows, and row *i* is tier *i+1*.** A four-tier country gets
four rows. Tier indexes are **not** stretched across an eight-rung scale; see
`EconomyScaleResolver` for why that convention won over the alternative.

**`resolvable: false` means the operator has not authored this tier's turnover yet.** It exists
because a bare `turnoverPence: 0` cannot tell "unauthored" apart from "genuinely worth nothing",
and the first is the shipped state. Treat an unresolvable tier as "fall back to whatever you did
before", not as "this division is free".

**`wageScaleMicroPence` is pence × `wageScaleUnit` (10⁶).** It is not a whole number of pence on
purpose: `S_tier` for a bottom division is a fraction of a penny per unit of ability^k, and
rounding it to pence would quantise every wage in that division to a handful of values — or, at
a small country factor, round it to zero and make every player free. Divide by `wageScaleUnit`
at the very end, after multiplying by `ability ^ (wageCurveExponentBp / 10000)`.

**There is no country factor in this block, deliberately.** The app never needs one: the figures
are already resolved for its country. Publishing a factor would invite applying it a second time
to something already scaled.

### Pricing a wage from this block

```
weeklyPence = round(
    wageScaleMicroPence
  * ability ^ (wageCurveExponentBp / 10000)
  * reputationModifier
  / wageScaleUnit
)
```

`reputationModifier` is `1 + (clubReputation - 50) / 50 * reputationSpreadBp / 10000`, which is a
flat `1.0` at the shipped `reputationSpreadBp = 0`. A non-zero spread **redistributes** a tier's
wage bill between its clubs rather than inflating it — the calibration is derived at modifier
1.0. That is worth stating because the obvious reading of "reputation raises wages" is that the
bill goes up.

## The five gameplay constants

All five are published and **not consumed server-side** — the device is the game engine, so
matchday income, NPC insolvency, points deductions and a Director of Football's opinion all
happen there. They live on the backend so an operator can retune them without an app release.

| Key | Type | Default | Notes |
|---|---|---|---|
| `matchdayFoodUptakePercent` | int | `35` | Whole percent of the crowd that buys food. |
| `npcSolvencyThresholds` | int[] | `[4,12,26]` | **Weeks in deficit**, not money, at which an insolvent NPC club escalates: `[warn, crisis, folded]`. Sorted ascending by the backend. |
| `dofWageBillThresholdPct` | float[] | `[0.40,0.50,0.60,0.70]` | Wage-bill-to-turnover ratios at which a DoF objects, as **fractions**. Sorted ascending. |
| `sponsorWeeklyPence` | int | `0` | ⚠️ See below. |
| `bankruptcyDeductionWeeks` | object[] \| **null** | **`null`** | The deficit ladder in **weeks of the club's own wage bill**. ⚠️ See below — `null` is meaningful. |

### `bankruptcyDeductionWeeks` — null means "use your own ladder"

```json
"bankruptcyDeductionWeeks": [
  { "weeksMin": 1,  "weeksMax": 2,    "points": 3 },
  { "weeksMin": 2,  "weeksMax": 4,    "points": 6 },
  { "weeksMin": 4,  "weeksMax": 8,    "points": 10 },
  { "weeksMin": 8,  "weeksMax": 16,   "points": 20 },
  { "weeksMin": 16, "weeksMax": null, "points": 50 }
]
```

**It ships as `null` and is `null` on every box today.** That is not a gap — it means no
operator has authored a ladder, so **keep using your built-in constant**. Guard with
`?? yourConstant`, not with a falsiness check, and do not treat an absent key differently from
a null one.

**`[]` can never reach you.** The setter collapses it to `null`, because an empty ladder would
read as a real one with no rungs and switch the mechanic off while looking configured.

**The rows are contiguous, not merely non-overlapping.** Each `weeksMin` equals the previous
row's `weeksMax`, so the upper bound is **exclusive** and every deficit lands in exactly one
row. The backend sorts rows, closes gaps and overlaps, and drops anything behind an unbounded
row before storing — so what you receive already satisfies that contract. `weeksMax: null` ends
the ladder.

**Why weeks and not pence.** `bankruptcyDeductionTiers` (still published, unchanged, still the
ladder for `legacy`) is flat pence, and wages scale by country, tier, inflation and wage model —
so the two decouple the moment any of those moves. Against England's authored table the entire
pence ladder is traversed in **0.01 weeks** of unpaid wages at tier 1. Weeks survive all four,
because each moves the numerator and the denominator together.

**Both keys are published.** Read whichever your model uses; they are not alternatives the
backend chooses between.

### ⚠️ `sponsorWeeklyPence` is WEEKLY. The sponsor bands are PER SEASON.

`smallSponsorMin`/`Max`, `mediumSponsorMin`/`Max` and `largeSponsorMin`/`Max` are a league
sponsor's **season total**, rolled at pack-build time and summed into `sponsorPot` on the league
snapshot. `sponsorWeeklyPence` is a **per-week payout** the app makes on the weekly tick.

**They are not convertible into each other by dividing by a season length.** They describe
different deals. Two sponsor money figures in one config in different time units is a trap, which
is why the unit is in the key name, the column docblock and this table.

`0` means "no weekly sponsor income" and is the inert default, so a client that reads the key
before an operator sets one behaves as though the mechanic is off.

### Why `dofWageBillThresholdPct` is fractions when the economy block is basis points

Basis points exist in the economy block because those figures are links in an integer chain that
ends in pence and must not round mid-way. These are thresholds the app compares a ratio against —
nothing multiplies them into money — so they are stored in the units the client already compares
in. Converting twice would be the error-prone choice. `missionGemRollThresholds` is a float list
for the same reason.

## `economy` on `GET /api/starter-config`

```json
{
  "wageModel": "legacy",
  "weeksPerSeason": 46,
  "wageToTurnoverRatioBp": 6000,
  "wageCurveExponentBp": 25000,
  "staffWageShareBp": 2500,
  "reputationSpreadBp": 0,
  "wageScaleUnit": 1000000,
  "tierFactorsBp": [10000, 10000, 10000, 10000, 10000, 10000, 10000, 10000],
  "baselineTurnoverPence": [0, 0, 0, 0, 0, 0, 0, 0],
  "inflationRatePerSeasonBp": 0
}
```

`baselineTurnoverPence` shipping as eight zeros is **not a usable configuration and deliberately
not a harmless one** — the wage scale divides by turnover, so zero makes the turnover model
undefined rather than cheap, and the server refuses it rather than dividing. A plausible-looking
default would let the model be switched on against numbers nobody authored.

## `wageModel`

`legacy` (shipped) or `turnover`.

`legacy` prices a generated wage as `ability × rand(contractValueRandMin, contractValueRandMax) ×
tierMultiplier` — the bands and multipliers that have priced every row in the world so far. It is
a **supported mode, not dead code**: it must keep producing byte-identical figures.

`turnover` anchors a tier's wage scale to a configured share of that tier's turnover, derived
from a reference squad whose size and ability spread come from `npcSquadConfig` and
`leagueAbilityRanges` — the same config the world generator sizes real squads from, so the figure
the scale is calibrated against is the figure that actually gets paid.

**The flip is an operator action, not a config tweak.** Cached world packs hold wages and the pack
cache has no TTL, so flipping the model changes pack *content* without changing pack *shape* —
`WorldInitializationService::WORLD_PACK_VERSION` has to be bumped in the same pass or every
already-cached country serves pre-flip wages forever. That constant's docblock is a post-mortem
of exactly that happening twice.

## The TV pool

A league's TV money is a **pool** split equally across its clubs, NPC clubs included. The
backend cannot pay a club anything — there is no income loop here, the device runs the economy —
so what it publishes is the pool and the club count, and the app does the paying.

| Key | Meaning |
|---|---|
| `tvDeal` | **The PER-CLUB share, in pence.** Unchanged in name and meaning since the first release. |
| `tvPoolPence` | The league's whole TV money for a season, in pence. The authored value. |
| `clubCount` | What the pool was divided by. |

`tvDeal` was deliberately **not** re-interpreted as the pool: shipped builds read that key and
treat it as one club's money, so changing what it means would be a changed value under an
unchanged key. It is maintained from the pool instead — `tvDeal = intdiv(tvPoolPence, clubCount)`
— and `clubCount` is published so a client can check the arithmetic rather than trust it.

**`tvPoolPence` is null on a league nobody has authored a pool for**, which is every league until
an operator sets one. A null pool derives nothing and `tvDeal` keeps exactly the figure it always
had. That is what makes shipping the column a provable no-op.

Base-year pence, like everything else here.

### Where the keys appear — and where they deliberately do not

Added to the three **uncached** league payloads: the sync response's league snapshot,
`GET /api/initialize/leagues`, and the season pyramid returned by `POST /api/league/conclude-season`.

**Not** added to the cached world-pack league block. That surface is gated by
`WorldInitializationService::WORLD_PACK_VERSION`, so a key there forces a bump that invalidates
every country's cache and makes the next request per country/tier pay a ~2-4s rebuild. Nothing
needs the figure in a pack — the pack is squad and fixture data — so the shape stays put. Same
resolution the NPC-club `locationId` work reached.

### Where `clubCount` comes from, and why it is not counted

A `League` row is a **template shared by every save** in its country and tier: on 2026-11-12
England tier 8 had 147 real player clubs pointing at one row. The eighteen-club table a player
sees is assembled on their device from the world pack. So nothing countable on the server means
what a divisor needs to mean, and counting player clubs would give England tier 8 a divisor of
148.

`league.slots` is therefore authored, with **0 meaning "work it out"** from NPC rows plus one for
the country's bottom division (where new clubs are placed). The derivation is kept as the
fallback, so an operator who prefers it simply never sets `slots`.

The reason the size is stored rather than derived is not the arithmetic — it is that **a derived
divisor moves whenever somebody clicks Generate**, silently re-pricing a tier's TV money for
every shipped build as a side effect of an unrelated admin action.

## Parachute payments

`parachute` is published on the same three uncached league payloads as the TV pool, resolved
**`league ?? gameConfig`** — whole-value, not merged key by key.

**The backend serves this block verbatim and does not read inside it.** It stores it, decides
which of the two applies, and publishes it. There is no schema, no validation, and no
normalisation beyond `[]` becoming `null`.

That is a deliberate decision, not a gap. The backend cannot pay a club anything — there is no
income loop, the device runs the economy — so any structure imposed here would be a second
opinion about a schedule only the app can execute, and the two would drift the first time the
app wanted a fourth season or a cliff instead of a taper. **A schema the app owns outright
cannot drift from itself.** Same treatment as `league.trophy`, `player.appearance` and
`npc_club.identity`.

The accepted cost: a malformed block is stored and served, and only the app notices. That is the
same trade `starter_config.setup_tutorial_config` makes, which is why that one has a checker
command rather than a validator.

### Whole-value resolution

| league | gameConfig | published |
|---|---|---|
| `{"seasons":1}` | `{"seasons":3,"x":true}` | `{"seasons":1}` — the global's `x` does **not** leak through |
| `null` | `{"seasons":3}` | `{"seasons":3}` |
| `null` | `null` | `null` — the shipped state |

Merging would require the backend to understand the keys, which is precisely what it declines to
do, and a half-overridden schedule is a worse answer than either whole one.

`null` is what every league publishes today: an old build does not read the key, and a new build
reads it as "no parachute scheme configured" rather than as an empty one to pay out.

### The agreed shape

Not enforced anywhere — this is the contract, and this document is the only place it lives.
Money in **base-year pence**; the app applies inflation.

```json
{
  "seasons": 3,
  "perSeasonPence": [550000000, 450000000, 200000000]
}
```

`seasons` is how many seasons after relegation the club is paid for; `perSeasonPence[i]` is what
it gets in season `i+1` after going down. Add keys freely — the backend passes through anything,
so extending this needs no backend change at all. Clearing an override is a blank field in the
admin, which stores `null` and restores the global.

## Per-league overrides

`leaguePositionDecreasePercent` is now a nullable per-league column, resolved
`league ?? gameConfig` at every publish site. The key keeps its name, its type and — for every
league with no override, which is all of them until an operator sets one — its exact value.

Nullable rather than defaulting to 0, because "not overridden" has to be expressible and a taper
of **zero is a legitimate setting**: it means every finishing position pays the same.

## How a pool row gets priced, and why it is different

Two populations, two treatments. This is the distinction that governs everything about where
the wage model can be applied:

| Population | Priced | Why |
|---|---|---|
| World-pack club players and staff | **At generation**, stored in the pack | Generated for a known country and tier. This is why flipping the model needs a `WORLD_PACK_VERSION` bump — packs hold wages. |
| Market-pool players, coaches, scouts | **At serve time**, nothing stored changes | One row is served to every country at every tier. There is no country or tier to price it by at generation. |

So a pool row's stored wage is always the **legacy** figure, and flipping the model changes
nothing about the pool. Flipping back is instant.

At serve time:

- **Pool players** (`GET /api/players/foreign`, `GET /api/scout/search`) are **re-priced** from
  the ability curve for the browsing club's country and tier, because a player has an ability
  the model has an opinion about.
- **Pool coaches and scouts** (`GET /api/market/data`) are **scaled by the country × tier
  factor only**, not re-priced. Their pay comes from bands the turnover model says nothing
  about — a scout's wage is PoolConfig's floor and ceiling interpolated by experience — so
  there is no `S_tier` to re-derive it from. What the model *does* have an opinion about is
  that money means different things in different countries and divisions.

`ScoutWageCalculator` is deliberately untouched by all of this: it must stay reproducible from
`(experience, PoolConfig)` alone, because `app:backfill-scout-wages --force` re-derives every
scout in the world from exactly those two inputs.

### The paying club's country, not the player's nationality

A wage demand is what *this club* has to pay, so it is anchored to the economy the club plays
in. A Brazilian at an English tier-1 club is paid English tier-1 money. Pricing by nationality
would make a squad's wage bill depend on where its players were born.

### Tiers are resolved server-side

`GET /api/market/data` and `GET /api/scout/search` resolve the club's country and tier from the
authenticated request rather than accepting them as parameters — they set the **price**, and a
client-supplied price anchor would be a client-supplied discount. `GET /api/players/foreign`
keeps `country` as an explicit parameter because there it **selects** which players to show;
its tier is still resolved server-side.

`GET /api/market/data` is now `Cache-Control: private`. The body varies by the caller's club, so
a shared cache in front of it would serve one country's prices to another.

## `youthScholarshipPence` — prefer the per-tier figure

Each row of `economyTierBaseline.tiers[]` carries a `youthScholarshipPence`. **Prefer it over the
top-level `youthScholarshipWagePence`**, and keep that key as the fallback.

Why both exist: the flat key is a single figure for every tier, and a single figure cannot be
right across a wage ladder that spans **1,330×** top to bottom. At England tier 8 an average
first-team wage is about **£127**, so a flat £250 scholarship had a thirteen-year-old
out-earning an average professional by nearly 2×. At tier 1 an average wage is **£132,892**, so
the same £250 is **0.19%** of it — a rounding error. Both ends were wrong, in opposite
directions.

So the per-tier figure is, in order:

1. **Anchored** — an operator authors what a scholar costs at tier 1, and every lower tier is the
   same proportion of its own average first-team wage.
2. **Floored** — a pure proportion reaches £1.13 by tier 8, and free is a different mechanic from
   cheap.
3. **Capped** — never more than `capBp` (default 50%) of an average professional at that tier.
   Applied last, so it always wins.

**You do not need to reproduce any of that.** Read
`economyTierBaseline.tiers[].youthScholarshipPence` for the tier the club is in and charge it.
None of the three inputs is published, deliberately — they are authoring knobs, and the resolved
figure is the answer.

A worked ladder, anchored at £1,500 with a £25 floor against England's authored table:

| tier | `youthScholarshipPence` | % of an average professional at that tier |
|---|---|---|
| 1 | `150000` | 1.1% |
| 2 | `55473` | 1.1% |
| 3 | `18714` | 1.1% |
| 4 | `11039` | 1.1% |
| 5 | `4511` | 1.1% |
| 6 | `2500` | 1.5% |
| 7 | `2500` | 5.6% |
| 8 | `2500` | 19.7% |

`youthScholarshipWagePence` is unchanged in name, type and meaning, so a build that does not
know about the per-tier key behaves exactly as before.

All of it applies only under the `turnover` model. **Under `legacy` — which is every deployed
box today — every tier publishes the flat figure**, so the per-tier key is currently just £250
eight times over. It starts differing the day the model is flipped, which is the day to be
reading it rather than the flat one.

## `sponsorBandsPence` — prefer the per-country bands

`economyTierBaseline.sponsorBandsPence` carries the three company-size bands resolved for the
block's country:

```json
{
  "small":  { "min": 5600000,  "max": 8200000  },
  "medium": { "min": 8200000,  "max": 13400000 },
  "large":  { "min": 25000000, "max": 80000000 }
}
```

**Prefer these over the six flat `smallSponsorMin`/`Max`-style keys**, which are unchanged and
remain the fallback. The flat ones are country-agnostic, so a client rolling its own sponsor
offers from them would disagree with the `sponsorPot` the server rolled for the same league —
a club in a country factored to 20% would earn full English sponsorship while paying a fifth of
the wages.

Still **per season**, like the flat keys, and still not convertible with `sponsorWeeklyPence`.

**Country factor only — these are not tier-scaled.** A league's tier variation is already
expressed by how many sponsors it has and which size bands they draw from, so the per-tier rows
deliberately do not carry their own sponsor bands.

Unlike the wage model this is **not gated on `wageModel`**: it applies as soon as a country
factor is set.

## Before flipping `economyWageModel`

Run `app:economy:wage-table EN SC`. It reads the **live config rows** — squad sizes from
`npcSquadConfig`, bands from `leagueAbilityRanges`, every knob from `game_config` — and exits
non-zero if any of four checks fail:

1. `S_tier` is strictly decreasing with tier number.
2. No ability earns more at a lower tier than the one above (checked over each adjacent band
   overlap, so a promoted player cannot take a pay cut).
3. TV per club is a sane fraction of baseline turnover.
4. The reference squad's bill actually matches the configured share — the chain checked against
   its own definition.

Check 4 earned its place immediately: it caught the reference squad being summed over
*continuous* abilities while `Player::$currentAbility` is an integer, which left England's
tier-8 bill 2.5% over the budget it was calibrated to. Invisible on a wide band, severe on a
narrow one.

## Backwards compatibility

Nothing was removed, renamed or retyped. `?country=` on `GET /api/game-config` is optional and
omitting it is byte-identical to the previous behaviour plus the new keys.

`seasonTicketHolderPercent` was **not** duplicated into the economy block — it is already live on
both `/api/game-config` and the sync `gameConfig` block.

`tvPoolPence`, `clubCount` and the resolved `leaguePositionDecreasePercent` are additions to
three uncached payloads; `tvDeal` is byte-identical on every league, guaranteed by the
migration's backfill (`tv_pool_pence = tv_deal x slots`), which was verified exact across all
120 leagues on the dev dataset.

Both branches of `SyncService`'s `gameConfig` ternary carry an identical key set, in the same
order, built from one place (`EconomyConfigPublisher`).
`tests/Service/SyncServiceGameConfigParityTest.php` asserts that statically, and
`tests/Controller/Api/LegacyNewGameFlowTest.php` asserts the additions are additions by diffing
the key set against what the endpoint served before.
