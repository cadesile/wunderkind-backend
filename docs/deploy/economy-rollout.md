# Turning the economy on — operator runbook

Written 2026-11-12. The economy overhaul is **deployed and inert**: eight migrations have
landed, every factor is parity, the wage model is `legacy`, the inflation rate is 0, and the
turnover table is unauthored. Nothing it added changes a single generated figure.

This is how you turn it on. **One country at a time**, and the first one is the hard one.

> **The one-line version.** Author the turnover table → run `app:economy:wage-table` until it
> exits 0 → re-author the TV table → flip the wage model → **bump `WORLD_PACK_VERSION`** → warm
> every country's packs → watch `staleWorldPacks` reach 0.
>
> Skip the bump and every already-cached country serves pre-flip wages **forever**. The cache
> has no TTL.

---

## 0. Before you touch anything

### Know which flip is irreversible in practice

| change | reversible? |
|---|---|
| Country factor, tier factor, turnover table | Yes — nothing is stored from them until generation runs |
| Inflation rate | Yes, but **every existing save's derived prices move with it**, in both directions. Audited. |
| `economyWageModel` | The setting, yes, instantly. The **packs generated while it was on, no** — they hold the wages they were built with. |
| Season-investment base ceiling | Yes. Already-made investments stand. |

So the ordering below exists to make sure nothing is *generated* until the numbers are right.

### Deploys run migrations for you

`dev` deploys on a push to the **`dev`** branch, prod on a push to **`master`**; both run
`doctrine:migrations:migrate` (`deploy-dev.yml` / `deploy-prod.yml`). Nothing in this runbook
needs a migration run by hand.

Commands below are shown as they run on the box:

```bash
docker compose -f docker-compose.dev.yml exec -T app php bin/console <command>
```

Locally that is `lando php bin/console <command>`.

---

## 1. Set the baseline country

**World → Economy → Country factors.** Pick the radio for the baseline and save.

England is flagged by `Version20261112160000`'s `postUp()`, so on a database that has an `EN`
profile this is already done. Check it anyway — the card header names the current baseline, and
"NONE" there means every country is resolving at parity.

**Exactly one baseline exists, enforced by a partial unique index.** The screen moves it with
clear-then-set inside a transaction, because Postgres checks that index per row during an
UPDATE. You cannot end up with two.

Factors are a **percentage of the baseline**, normalised against the baseline's own stored
value — so setting England to 12000 does not shift every other country, and the baseline is
always exactly parity with itself.

---

## 2. Author the turnover table for the baseline country

**World → Economy → Per-tier tables.** Eight figures, in **pounds**.

It ships as eight zeros, which is **not a usable configuration and deliberately not a harmless
one**: the wage scale divides by turnover, so zero makes the model undefined rather than cheap
and the resolver refuses to price anything. That is what keeps the model unreachable until you
fill this in.

A starting ladder that hits the agreed targets for England — about £300,000/week for the top
tier-1 earner and about £100/week for an average tier-8 player — against the **live** squad
sizes and ability bands:

| tier | baseline turnover |
|---|---|
| 1 | £492,578,230 |
| 2 | £162,251,456 |
| 3 | £51,057,810 |
| 4 | £28,780,384 |
| 5 | £11,116,147 |
| 6 | £4,068,954 |
| 7 | £976,429 |
| 8 | £272,195 |

Derived, not guessed: anchored at both ends and geometric between, so `S_tier` comes out
strictly decreasing by construction. **Re-derive rather than copy if squad sizes or ability
bands have changed** — those are the inputs, and the preview reads them live.

Leave the other knobs at their shipped values for the first pass (ratio 60%, staff share 25%,
k 2.5, reputation spread 0, 46 weeks). Change one at a time, with a preview run between.

---

## 3. Run the gate until it exits 0

```bash
php bin/console app:economy:wage-table EN SC
```

It reads the **live config rows** — not arguments — and exits non-zero on five checks:

1. `S_tier` strictly decreasing with tier number.
2. No ability paid more at a lower tier than the one above (checked across each adjacent band
   overlap, so a promoted player cannot take a pay cut).
3. TV per club a sane fraction of baseline turnover.
4. The reference squad's bill matching the configured share — the chain against its own
   definition.
5. The absolute-pence thresholds still meaning something.

**Read the table, do not just check the exit code.** The targets are yours; the command only
knows about internal consistency.

Add `SC` (or any shallow-pyramid country) to the arguments: a four-tier country exercises the
raw row indexing, which is the part most likely to look wrong.

### What you will see fail, and what to do about it

**`TV alone is N% of baseline turnover`** — a hard gate. See step 4.

**`the youth scholarship is MORE than an average first-team wage`** — **should no longer
appear.** Fixed 2026-11-12: `economyYouthScholarshipCapBp` (World → Economy → Wage model, default
50%) caps a scholarship at a share of the tier's worst first-team wage, taking England tier 8
from 1.97× an average wage to 0.05× while leaving tiers 1-6 untouched. If you do see it, the cap
has been set to 0 ("no cap") or raised above 100%.

**`the harshest bankruptcy band is reached in N weeks`** and **`row N can never be reached`** —
**not backend blockers.** That mechanic is client-side and gated by
`bankruptcyDeductionsEnabled`; the ladder has been collapsed since before this work, and two of
its five rows cannot fire under the documented resolution. Full analysis and the proposed fix in
`docs/economy-threshold-review.md`. Do not let it block the flip, but do not let a player
discover it either.

**`tier N has no baseline turnover authored`** — you have not finished step 2.

The same breakdown is on **World → Economy → Resolver preview**, which runs the real resolver
rather than a copy, so the screen and the command cannot disagree. Use the screen to iterate and
the command to gate.

---

## 4. Re-author the TV table — a hard gate

**Do not flip the wage model until per-club TV is a sensible fraction of baseline turnover.**

Tier-1 TV has been authored at figures larger than any plausible tier-1 turnover, which makes
`wageToTurnoverRatio` meaningless: TV alone already exceeds the revenue the wage bill is supposed
to be a fraction of. Check 3 fails on anything over 100%, and the preview's TV table shows the
percentage per tier.

TV is authored per league, on **World → League overview**: set **TV POOL** (the league's whole TV
money) and leave **TV DEAL** to be derived — it is the per-club share, and the screen disables the
field while a pool is set. The divisor is **SLOTS**, blank meaning "work it out from NPC rows".

### Should the pool be derived from turnover automatically?

**Recommendation: no.** Asked and answered during step 4, and the reasoning has not changed:

- `tvDeal` is a key shipped builds read. Deriving the pool from `baselineTurnover × tvShare`
  would mean editing a **wage-model knob silently re-prices TV money for live clients** — a
  changed value under an unchanged key, with no author.
- It collides with per-league overrides, which are the entire point of having them. A derived
  pool either overrides them or is overridden by them, so it is not a single source of truth
  either way.

The drift risk is real though, so it is **reported rather than prevented**: check 3 and the
preview's TV column both show TV as a percentage of turnover, so drift is visible on every run.
That matches how this repo handles the same class of problem elsewhere — `LeagueTierDefaultDiff`
reports drift rather than correcting it.

**Optional follow-up, not built:** a "suggest from turnover" button on the league screen that
fills the pool field with `turnover × tvShare` and leaves the write to a human. Worth doing if
re-authoring 196 leagues by hand becomes the bottleneck.

---

## 5. Flip the wage model

**World → Economy → Wage model → `Turnover-anchored` → Save.**

From this moment every player and staff member the world **generates** is priced by the new
model. Nothing already generated changes.

### Then bump `WORLD_PACK_VERSION`, in the same pass

`src/Service/WorldInitializationService.php`:

```php
public const WORLD_PACK_VERSION = 5;   // was 4
```

**This is the step that is easy to skip and expensive to skip.** A cached pack holds the wages
its players were generated with. Flipping the model changes pack **content** without changing
pack **shape**, which is the one case that constant does not catch on its own — and the cache has
**no TTL**, so every already-cached country would serve pre-flip wages indefinitely.

That constant's own docblock is a post-mortem of this exact failure happening three times, once
leaving "84 of 85 rows on the production restore" serving a retired shape forever. Add a numbered
note to it saying what the bump was for, the way versions 1–4 do.

The bump is a code change, so it ships through a normal deploy.

**The same bump is required when a country factor changes**, for the same reason.

---

## 6. Warm the packs

Every cached row is now a miss, so the next client request per country/tier pays the rebuild
(~2s for an 8-club tier, ~3–4s for 18) on its critical path. Pre-empt it:

```bash
php bin/console app:worldpack:warm EN
```

**Not `--force`.** A stale-versioned row is already a miss and is rebuilt through the atomic
replace path; `--force` in all-tiers mode **deletes the country's entries up front** and leaves
it with none until the run finishes. The option exists for a different job.

For a big country, one tier at a time is gentler and is what the cron uses:

```bash
php bin/console app:worldpack:warm EN --tier=1
```

Run it for **every** country that has cached packs, not just the one you re-tuned — the bump
invalidated all of them.

### Watch it land

**Dashboard → NPC Clubs & World** shows `staleWorldPacks`, counting rows whose
`payload_version` is not the current constant. **That number reaching 0 is the signal the
rollout is complete.** `cachedWorldPacks` tells you how many exist at all.

`World → Worldpack cache` lists them per country and tier.

---

## 7. One country at a time

Do not set factors for 25 countries and flip once.

The baseline country is the only one whose numbers you can judge against a target, because every
other country's figures are a **percentage of it**. Get England right, ship it, look at real
generated clubs, and only then give the second country a factor.

For each subsequent country:

1. Set its factor on **World → Economy → Country factors**.
2. `app:economy:wage-table <CODE>` — it exits non-zero on the same five checks.
3. Bump `WORLD_PACK_VERSION` and warm, **if** you changed a factor that affects generated
   content.
4. Check `staleWorldPacks` is back to 0.

A country with no profile row resolves at parity, so an unconfigured country is not broken — it
is just not scaled yet.

---

## 8. The two optional switches

Both ship off and neither needs the wage model on.

### Inflation

**World → Economy → Inflation.** A rate in basis points per season; 250 = 2.5%, compounded.

**Changing it moves every existing save's derived prices retroactively**, in both directions,
because the app compounds it over a club's season count. Every change writes an audited row with
the previous value, who changed it and why — fill the note in, it is the only field a later
reader actually wants.

The backend holds the **rate and nothing else**. Nothing is ever stored inflated and no world
pack carries an index — the pack cache is keyed `(country, tier)` with no season dimension, so
an inflated pack would be wrong for every save at a different season. The one server-side use is
the season-investment ceiling.

Deflation is refused: a negative rate would make a long save's ceiling approach zero, so the
owner who played longest could invest least.

### Owner season investment

**World → Economy → Owner season investment.** A base ceiling in pounds; `0` or blank is off.

Zero is an **off switch**, not a neutral default: the resolved ceiling is
`base × countryFactor × tierFactor × inflationIndex(season)`, so a base of zero makes every
amount above the ceiling and the endpoint refuses everything. That is how its two new
client-visible error codes (`409 already_invested_this_season`, `422 above_season_ceiling`) ship
unreachable by a client that does not understand them yet.

**Check the app understands the response before switching this on.** `creditClubPence` is an
**instruction the device applies** — the server debits the owner's ledger and cannot credit
`club.balance`. A client that ignores that key takes the player's money and gives them nothing.
Contract: `docs/api/season-investment.md`.

---

## 9. Things that are not part of this rollout

**`app:backfill-scout-wages`** already runs on every deploy and acts only on rows at 0, so it
needs nothing from you. Run it with `--force` **only** after changing `PoolConfig`'s scout wage
range, and know that it re-prices every scout in the world.

**Pool rows are not re-priced by any of this.** Market-pool players, coaches and scouts are one
row served to every country at every tier, so they are stored at the legacy figure and scaled at
**serve time** from the requesting club's country and tier. Flipping the model changes nothing
stored about the pool, and flipping back is instant. `app:worldpack:warm` does not touch it.

**The sponsor bands are still unscaled by country** — a club in a country factored to 20% earns
full English sponsorship while paying 20% wages. Flagged in
`docs/economy-threshold-review.md`; worth doing in the same pass as the second country, and not
a blocker for the first.

---

## If it goes wrong

| symptom | likely cause |
|---|---|
| Generated wages unchanged after the flip | Packs were not invalidated. Check `staleWorldPacks`, bump `WORLD_PACK_VERSION`, warm. |
| One country right, the rest absurd | A factor was set as an absolute rather than as a percentage of the baseline. The preview's `OF BASELINE` column is the check. |
| Every wage zero in a country | Its turnover table is unauthored. The preview says `UNAUTHORED` per tier. |
| A shallow country's bottom division is rich | Expected, and deliberate. Tier *t* reads row *t* with no stretching — see `GameConfig::getNpcClubBalanceRangeForTier()`. Author that country's own tier factors. |
| Wage model shows `turnover` but figures look legacy | A tier with no authored turnover falls back to legacy rather than pricing at zero. Check the preview for `UNAUTHORED`. |
| First client request per tier is slow after a bump | Expected: that request pays the rebuild. Warm ahead of it. |

### Backing out

1. **World → Economy → Wage model → `Legacy`.** Immediate for everything generated afterwards.
2. **Bump `WORLD_PACK_VERSION` again** and warm — the packs built while it was on still hold
   turnover-model wages, and reverting the setting does not touch them.
3. Country factors back to 10000 and the turnover table back to zeros if you want the resolver
   to refuse outright rather than sit armed.

Already-written ledger rows (season investments) stand. They are real money the owner spent.
