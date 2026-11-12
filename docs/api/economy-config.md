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

## The four gameplay constants

All four are published and **not consumed server-side** — the device is the game engine, so
matchday income, NPC insolvency and a Director of Football's opinion all happen there. They live
on the backend so an operator can retune them without an app release.

| Key | Type | Default | Notes |
|---|---|---|---|
| `matchdayFoodUptakePercent` | int | `35` | Whole percent of the crowd that buys food. |
| `npcSolvencyThresholds` | int[] | `[4,12,26]` | **Weeks in deficit**, not money, at which an insolvent NPC club escalates: `[warn, crisis, folded]`. Sorted ascending by the backend. |
| `dofWageBillThresholdPct` | float[] | `[0.40,0.50,0.60,0.70]` | Wage-bill-to-turnover ratios at which a DoF objects, as **fractions**. Sorted ascending. |
| `sponsorWeeklyPence` | int | `0` | ⚠️ See below. |

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

## Per-league overrides

`leaguePositionDecreasePercent` is now a nullable per-league column, resolved
`league ?? gameConfig` at every publish site. The key keeps its name, its type and — for every
league with no override, which is all of them until an operator sets one — its exact value.

Nullable rather than defaulting to 0, because "not overridden" has to be expressible and a taper
of **zero is a legitimate setting**: it means every finishing position pays the same.

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
