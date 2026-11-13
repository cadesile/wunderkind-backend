# Absolute-pence thresholds under a scaled economy — review

Written 2026-11-12, step 8 of the economy overhaul. **A report. No live values were changed.**

The economy overhaul makes wages scale by country and tier. Every threshold those wages are
compared against is a **flat pence figure**, so the moment a country factor moves or the wage
model flips, the two decouple — and nothing announces it. This is the survey of which ones
break, by how much, and what to do instead.

Every number below comes from **one** `app:economy:wage-table EN SC` run against the live
`development` configuration on **2026-11-13**, so this document, `docs/deploy/economy-rollout.md`
and the code all quote the same run. Earlier revisions mixed a development clone's squad sizes
(tier-1 squad 33) with a turnover table derived against the deployed box's (tier-1 squad 26),
which put every quoted wage about 27% high relative to the table printed beside it. **Re-issue
both documents together from a single run** rather than updating either alone. That command now carries this as **check 5**, so these findings stay
live rather than becoming a stale snapshot.

---

## 1. `bankruptcyDeductionTiers` — two of its five rows cannot fire

The live table (pence):

| row | points | `deficitMin` | `deficitMax` |
|---|---|---|---|
| 1 | 3 | 1 | 200,000 |
| 2 | 6 | 200,001 | 500,000 |
| 3 | 10 | 500,001 | **null** |
| 4 | 20 | 1,000,001 | **null** |
| 5 | 50 | 4,000,001 | **null** |

### How it resolves — and the honest limit of what I can say

The only contract that exists is the column's own docblock: *"Evaluated in order — first matching
tier wins."* The figures are published on `GET /api/game-config` and **consumed entirely by the
app**, which lives in `wunderkind-app` and is out of scope for work in this repo. There is no
app-side note about this in the repo and I have not read that codebase, so **I cannot tell you
what the app actually does** — only what each plausible rule would produce.

That turns out to be enough, because all three answers are bad in a way worth fixing:

| resolution | result on the live table |
|---|---|
| **First match wins** (what the server docblock says) | Rows 4 and 5 are **dead**. Any deficit ≥ 500,001 matches row 3 first, so the 20-point and 50-point penalties can never fire. The harshest reachable penalty is 10. |
| **Last match wins** | The ladder works as its author plainly intended — 50 points at ≥ £40,000. |
| **Highest matching penalty** | Same as last-match-wins here, because the penalties ascend. |

So the table **only behaves the way it looks** if the app does *not* follow the documented
server-side contract. One of the two is wrong, and finding out which is a question for the app
team.

**Recommendation, which is correct under all three rules:** give rows 3 and 4 real `deficitMax`
values so the rows stop overlapping. Then every resolution agrees, and the documented contract
stops being a trap. That is a value change and therefore not made here.

### The bigger problem: the ladder has no range left

Reaching the **harshest band (£40,000)** takes, in weeks of unpaid wages:

| tier | squad wages / wk | weeks to harshest band |
|---|---|---|
| 1 | £3,802,124 | **0.01** |
| 2 | £1,174,400 | **0.03** |
| 3 | £348,032 | **0.11** |
| 4 | £194,762 | **0.21** |
| 5 | £69,181 | **0.58** |
| 6 | £22,890 | 1.75 |
| 7 | £5,701 | 7.02 |
| 8 | £1,613 | 24.80 |

For the top five tiers the entire five-rung ladder is traversed in **under a week**. The
mechanic is not "a graduated penalty for financial trouble", it is "any deficit at all =
maximum penalty" — and it has been for a while. Under the *legacy* model the figures are
similar (0.03 weeks at tier 1), so this is **not caused by the overhaul**; the overhaul only
makes it measurable.

A single ability-25 player costs **£9,000/week** under the legacy model
(`25 × 2400 × ×15`), which clears row 1's whole £2,000 band in **0.22 weeks**. That is the
figure from the brief, confirmed.

---

## 2. The youth scholarship — FIXED 2026-11-12

> **Resolved in two passes, and the second one matters.** The first fixed the end that inverted:
> `economyYouthScholarshipCapBp` capped a scholarship at 50% of the tier's **worst** first-team
> wage, taking tier 8 from 1.97x an average wage to 0.05x with tiers 1-6 byte-identical.
>
> **That left the other end quietly wrong, and running the gate on real data found it.** £250 is
> **0.19% of an average tier-1 first-team wage** — a rounding error, not a cheap academy. A flat
> figure cannot be right at both ends of a ladder spanning 1,047x, so the cap had fixed the half
> that threw an error and declared the rest fine. The scholarship is now **anchored at tier 1
> and scaled down the wage ladder**, with a floor under it and the cap still on top. See
> "The second pass" below.
>
> The original finding is kept because the reasoning is what justifies the shape of the fix.

### The original finding: The youth scholarship is now MORE than an average tier-8 wage

`youthScholarshipWagePence` is a flat **£250/week** for a player signed below first-team age.
Against the turnover model's average first-team wage:

| tier | mid wage / wk | scholarship ÷ mid wage |
|---|---|---|
| 1 | £132,984 | 0.00× |
| 5 | £3,997 | 0.06× |
| 7 | £447 | 0.56× |
| 8 | **£127** | **1.97×** |

**At tier 8 a thirteen-year-old scholar is paid nearly twice what an average first-team player
earns**, and at tier 7 more than half. That is a new consequence of the turnover model — the
legacy model pays tier 8 an average of £480/week, so the scholarship sits at 0.52× there and
never inverts.

This one is a **backend-authored** figure, so unlike the bankruptcy bands it is ours to fix. It
was the clearest single blocker to flipping the model for a country with a narrow bottom-tier
band.

Worth being precise about what "ours" means here: the backend **only publishes** this figure —
`grep` confirms it is never written onto a `Player`, so the app applies it. That does not make it
the app's problem, because the backend is the one that decides the number; but it does mean the
fix had to be a published figure rather than a changed write path, which is why it lands as a new
key rather than as a different wage on a row.

### What was done, and why a cap rather than a replacement

The flat figure is **right at tiers 1-6 and wrong at 7-8**. Expressing the whole scholarship as a
share of the band would have re-priced every tier to fix two of them, throwing away a
hand-authored number that works. So:

```
scholarship = min(youthScholarshipWagePence, worstFirstTeamWageAtTier x capBp)
```

**Anchored to the worst first-team wage, not the average**, because that is the comparison the
defect is about: a scholar must cost less than the *cheapest* professional at the club.
Anchoring to the average would still let one out-earn the bottom of the squad.

> ⚠️ **This anchor was changed in the second pass.** It did not survive measurement — see below.

**`youthScholarshipWagePence` is untouched** — same name, same type, same meaning, still
published. The resolved per-tier figure arrives as a new key in
`economyTierBaseline.tiers[].youthScholarshipPence`, so an older build keeps reading the flat
one, which is the correct fallback for a client that does not know tiers have their own.

**The cap ships ON at 50%, breaking this series' own "every switch arrives inert" rule — and it
is still a provable no-op.** It applies only under the `turnover` model, so the flat figure
stands through the whole shipped state *and* through the half-configured period while an
operator fills the turnover table in. Shipping it off would have meant a cap that has to be
remembered at flip time, and the entire point is that the inversion cannot reach a player. `0`
still means "no cap".

That model gate is not cosmetic: the first implementation checked only whether a tier was
*resolvable*, so it capped under `legacy` with a turnover table authored — where tier-8 wages
average ~£480 and £250 is already sensible. `YouthScholarshipCapTest` caught it.

### The second pass: anchor at tier 1 and scale down

**What the first pass missed.** It only ever asked "is the scholarship too high?" — so it never
looked at tier 1, where £250 against an average first-team wage of £132,892 is **0.19%**. The
docblock's claim that "the flat figure is right at tiers 1-6" was about the figure being a
*plausible absolute wage*, not about it being a sensible *proportion*, and nobody checked the
second reading until the gate was run against a real authored table.

**One knob, at the top.** An operator authors `economyYouthScholarshipTier1Pence` — what a
scholar costs at tier 1 — and every lower tier is the same proportion of its own average wage:

```
scholarship(tier) = anchor x averageWage(tier) / averageWage(1)
```

Not an eight-row table: the per-tier shape is already expressed by the turnover ladder, and
authoring it twice invites the two to disagree. Same reasoning as sponsorship taking the country
factor but not the tier factor.

**Two candidate scalings were modelled first, and one of them is a trap.** Scaling by `S_tier`
is the obvious-looking choice and it is catastrophic — `S_tier` carries almost none of the
ladder's spread:

| scaled by | top-to-bottom spread | tier-8 scholarship, anchored at £1,500 | vs an average tier-8 pro (£127) |
|---|---|---|---|
| `S_tier` | **2.18×** | £687.31 | **6.9× — far worse than the original defect** |
| worst first-team wage | 3,908× | £0.38 | effectively free |
| average first-team wage | 1,330× | £1.13 | effectively free |

**So it needs a floor, and that is why the pair exists.** A pure proportion is as broken at the
bottom as a flat figure is at the top. `economyYouthScholarshipFloorPence` holds the bottom up;
the cap is applied **last** so the invariant always wins over the preference. An absurd floor is
clamped down to the tier's cap rather than breaking the one promise the mechanism makes.

With the anchor at **£1,500** and the floor at **£25**, against the authored England table:

| tier | scholarship | % of an average professional at that tier | |
|---|---|---|---|
| 1 | £1,500.00 | 1.1% | |
| 2 | £535.08 | 1.1% | |
| 3 | £173.68 | 1.1% | |
| 4 | £98.49 | 1.1% | |
| 5 | £38.75 | 1.1% | |
| 6 | £25.00 | 1.5% | floored |
| 7 | £25.00 | 5.6% | floored |
| 8 | £25.00 | 25.0% | floored |

Tiers 1–5 ride the proportion; 6–8 sit on the floor, which is what stops the scholarship decaying
into nothing where the wage ladder does.

### The cap's anchor moved to the average, deliberately

The rule above — 50% of the tier's **worst** first-team wage — did not survive measurement.
England tier 8's cheapest professional earns **£10.11/week**, so half of it is £5.05: the rule
did not deliver "cheaper than a professional" at the bottom, it delivered *free*, which the
resolver then had to floor at 1p to keep meaningful.

So `economyYouthScholarshipCapBp` is now a share of the tier's **average** first-team wage —
which is also the comparison this document and the gate command were measuring all along. The
invariant becomes sayable in one line: **a scholar never earns more than `capBp` of an average
professional at their tier.** At tier 8 that is £63.47 rather than £6.42.

Safe to redefine rather than add a second column: the cap is gated on `economyWageModel =
turnover`, which is `legacy` on every deployed box, so it has never applied to a published figure.

**The accepted cost, stated rather than buried:** where a band is as wide as tier 8's, a
scholarship can exceed the single cheapest professional's wage (£25 against £10.11). That is
accepted, because £10.11/week is an artefact rather than a wage anybody authored — see the new
finding below.

---

## 2b. NEW FINDING: tier 8's cheapest professional earns £10.11/week

Surfaced by the scholarship work rather than looked for. Against the authored England ladder,
tier 8's band is **2–7** and the curve exponent is 2.5, so the within-band spread is `7^2.5 /
2^2.5` ≈ **23×** — the cheapest professional at the tier earns £10.11/week (£465/season) while
the best earns £231.91.

| tier | band | cheapest /wk | average /wk | best /wk | within-band spread |
|---|---|---|---|---|---|
| 1 | 40–90 | £39,506.17 | £132,983.68 | £300,000.00 | 7.6× |
| 7 | 5–10 | £138.16 | £447.39 | £781.57 | 5.7× |
| 8 | **2–7** | **£10.11** | £100.00 | £231.91 | **22.9×** |

**Why it is a finding and not a bug.** Nothing is wrong with the arithmetic; the inputs are
`starter_config.league_ability_ranges` (which authors tier 8 as 2–7) and the exponent. The problem is that
a band starting at **2** sits where `ability^2.5` is near zero, so the bottom of that band prices
at a nominal figure. Every other tier's band starts far enough up the curve for this not to bite.

**Why it matters beyond the scholarship.** Any rule phrased as a share of "the cheapest
professional at the tier" is anchored to that artefact — which is exactly what the first
scholarship cap did. Candidate fixes, none implemented:

1. **Raise tier 8's band floor** (2–7 → 4–7, say). Changes generated player abilities, so it is
   a `WORLD_PACK_VERSION` bump and re-prices tier 8 wholesale.
2. **Floor the per-player wage** the way the scholarship is now floored. Cheap, local, and keeps
   the band as authored.
3. **Leave it** and only ever anchor rules to the tier *average*, which is what has now been
   done everywhere that mattered.

Recommendation: **(3) for now, (2) if a tier-8 wage ever reaches a player.** (1) is the real fix
and the most expensive; it should be a deliberate calibration decision, not a side effect.

---

## 3. Sponsor bands — FIXED 2026-11-12

> **Resolved after this review was written.** `SponsorBandResolver` scales every band by the
> **country factor**, so a country factored to 20% now earns a fifth of the sponsorship while
> paying a fifth of the wages. Byte-identical at parity, which is the shipped state. The
> original finding is kept below.

### The original finding: sponsor bands are per-season and unscaled by country

`smallSponsorMin/Max`, `mediumSponsorMin/Max`, `largeSponsorMin/Max` — live at roughly £56k–82k,
£82k–134k and £250k–800k **per season** — are flat pence rolled at pack-build time into
`sponsorPot`.

They are **not** scaled by country, so a club in a country factored to 20% earns the same
sponsorship as an English club while paying 20% wages. That is not "a cheaper country", it is a
different game: sponsorship becomes five times more valuable relative to the wage bill.

### What was done

Both roll sites held **byte-identical** copies of the same loop. `SponsorBandResolver` is now
the one implementation and both still-public methods delegate to it — that is the **fifth**
duplication this overhaul has collapsed, after three copies of the wage tier-walk and three of
the ability-band lookup.

**Country factor only, not country × tier**, and that is the decision worth defending. A
league's tier variation is *already* expressed in this mechanic by two things the tier factor
would double-count: `League::$sponsorCount` (authored per tier) and which `CompanySize` bands
its sponsors draw from. Applying the tier factor on top would re-tier an already-tiered mechanic
and quietly make a tier-1 pot enormous. So the fix is exactly as wide as the defect was.

The six flat `smallSponsorMin`-style keys are **untouched and still published**. The resolved
per-country bands arrive as a new `sponsorBandsPence` key inside `economyTierBaseline`, so a
client rolling its own sponsor offers agrees with the `sponsorPot` the server rolled, and an
older build keeps reading the flat ones.

**Not gated on `economyWageModel`** — a sponsor band has nothing to do with how a player is
priced — so it starts applying the moment a country factor is set. That is why the runbook
requires a `WORLD_PACK_VERSION` bump on a **factor change**, not only on a model flip:
`sponsorPot` is part of the cached league block.

Note also the unit trap documented in `docs/api/economy-config.md`: these six are **per season**
while the new `sponsorWeeklyPence` is **per week**, and the two are not convertible by dividing
by a season length.

---

## 4. Fixed during this review: NPC balances were never country-scaled

Decision 4 of the brief lists NPC balances among the things the backend bakes the country factor
into. **They were not** — the wage unification threaded country and tier through players and
staff and left `NpcClubGenerationService::balanceForTier()` reading the raw table.

The effect: a club in a country scaled to 20% paid **20% wages out of 100% money**. Fixed in
this step (through `EconomyScaleResolver::scalePence()`, applied to the band *before* the
city-size skew so a big-city club stays the same multiple of its division's average whatever
the country is worth). Inert at parity, which is how it ships.

This is a code gap rather than a threshold value, which is why it was fixed rather than
reported.

---

## 5. Reviewed and deliberately left alone

| figure | why it stays absolute |
|---|---|
| `userStartingBalanceMinPence/MaxPence` | Account credit, not club money. `tribal-knowledge.md` records that an affordability ceiling tied to the wallet was **removed on instruction** — the wallet is the thing the player grows, so it is deliberately not calibrated to the world. |
| `facility_template.base_cost`, `weekly_upkeep_base` | Out of scope per the brief: they pay real account credit. |
| `clubFundingMinimumPence` | 0 = no minimum, and the stake is whatever the player has. |
| `scoutWageMin/Max` (PoolConfig) | Pool rows, scaled at serve time since step 5. `ScoutWageCalculator` must stay reproducible from `(experience, PoolConfig)` alone for `--force` to work. |
| `leaguePositionPot`, `prizeMoney`, `tvDeal`/`tvPoolPence` | Per-**league** columns, so country variation is already expressible without a factor — an operator authors them per country. No scaling needed, but see `docs/api/economy-config.md` on TV against turnover. |
| `npcSolvencyThresholds` | **Weeks in deficit, not money.** Already immune, and the model to copy. |
| `dofWageBillThresholdPct` | A **ratio** of wage bill to turnover. Already immune. |

---

## The proposal: express a deficit threshold in weeks of wages

`npcSolvencyThresholds` already does the right thing and is the template: it is
`[4, 12, 26]` **weeks in deficit**, not money. It cannot decouple from anything, because weeks
are not denominated in pounds.

Recommendation for the bankruptcy ladder, **additive, leaving `bankruptcyDeductionTiers`
byte-identical for shipped builds**:

```json
"bankruptcyDeductionWeeks": [
  { "weeksMin": 1,  "weeksMax": 2,    "points": 3 },
  { "weeksMin": 2,  "weeksMax": 4,    "points": 6 },
  { "weeksMin": 4,  "weeksMax": 8,    "points": 10 },
  { "weeksMin": 8,  "weeksMax": 16,   "points": 20 },
  { "weeksMin": 16, "weeksMax": null, "points": 50 }
]
```

**Why weeks of the club's own wage bill, and not a fraction of turnover.** The backend does not
know a club's wage bill — the roster lives on the device, which is why
`POST /api/club/fund`'s projection is "half a projection, honestly labelled". The app knows it
exactly. So weeks-of-wages is the one unit **both sides can agree on without the backend needing
data it does not have**, and it is also a sentence a player understands: *"you are three weeks'
wages in the red."*

It survives a country factor, a tier factor, inflation, and a wage-model flip with no
re-authoring, because every one of those moves the numerator and the denominator together.

**Non-overlapping bounds on every row**, so the resolution question in §1 stops mattering.

### Implemented 2026-11-13 — `Version20261113120000`

The three reasons it was held back have all been answered, in order:

1. **The shape was agreed with the app team** rather than guessed. The rows above are what both
   sides implement; the app reads them with a `?? constant` fallback.
2. **It is not a speculative publish.** The column is nullable and ships `null`, so no box
   changes behaviour until an operator authors a ladder. `null` means "the app uses its own",
   which is the state every box is in today.
3. The owner's review approved building it.

**What was built:** a nullable `game_config.bankruptcy_deduction_weeks`, published through
`EconomyConfigPublisher::clientBlock()` so it reaches `GET /api/game-config` **and** both sync
branches with the parity test guarding them; a JSON textarea on the game-config admin screen
where **a blank field means null**, not an empty ladder; and normalisation in the setter that
sorts rows, closes gaps and overlaps so the ladder is contiguous, and drops anything behind an
unbounded row.

**The normalisation is also why `app:economy:wage-table` now reports on this column.** A repair
made silently on save is how an operator ends up believing in a ladder they do not have, so the
gate names gaps, overlaps, unreachable rows and a ladder with no unbounded final row.
`bankruptcyDeductionTiers` is untouched — same name, type, values and verbatim storage — and
remains the ladder for `legacy`.

What *is* implemented is the measurement: `app:economy:wage-table` now fails check 5 on exactly
the problems above, so none of this can quietly stop being true.

---

## Summary for the runbook

Before flipping the wage model for a country, `app:economy:wage-table` will report:

- **Blocking (ours to fix):** ~~the youth scholarship inverting against a narrow bottom-tier
  band~~ — **fixed 2026-11-12** by the cap above. The gate no longer reports it.
- **Needs an app-side decision:** the bankruptcy ladder's unreachable rows and its collapsed
  range. Not a backend blocker — `bankruptcyDeductionsEnabled` gates the whole mechanic — but it
  should not be discovered by a player.
- **Done 2026-11-12:** the sponsor bands scale by country, so a cheap country is now cheap on
  both sides of the ledger.
