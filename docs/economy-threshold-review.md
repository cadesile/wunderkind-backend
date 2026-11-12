# Absolute-pence thresholds under a scaled economy — review

Written 2026-11-12, step 8 of the economy overhaul. **A report. No live values were changed.**

The economy overhaul makes wages scale by country and tier. Every threshold those wages are
compared against is a **flat pence figure**, so the moment a country factor moves or the wage
model flips, the two decouple — and nothing announces it. This is the survey of which ones
break, by how much, and what to do instead.

Every number below is computed from the **live config** (England bands and squad sizes as
exported 2026-11-12) against the candidate turnover ladder, by
`app:economy:wage-table`. That command now carries this as **check 5**, so these findings stay
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
| 1 | £4,818,700 | **0.01** |
| 2 | £1,587,242 | **0.03** |
| 3 | £499,478 | **0.08** |
| 4 | £281,547 | **0.14** |
| 5 | £108,745 | **0.37** |
| 6 | £39,805 | 1.00 |
| 7 | £9,552 | 4.19 |
| 8 | £2,663 | 15.02 |

For the top five tiers the entire five-rung ladder is traversed in **under half a week**. The
mechanic is not "a graduated penalty for financial trouble", it is "any deficit at all =
maximum penalty" — and it has been for a while. Under the *legacy* model the figures are
similar (0.03 weeks at tier 1), so this is **not caused by the overhaul**; the overhaul only
makes it measurable.

A single ability-25 player costs **£9,000/week** under the legacy model
(`25 × 2400 × ×15`), which clears row 1's whole £2,000 band in **0.22 weeks**. That is the
figure from the brief, confirmed.

---

## 2. The youth scholarship — FIXED 2026-11-12

> **Resolved after this review was written.** `GameConfig::$economyYouthScholarshipCapBp` caps a
> scholarship at a share of the tier's **worst** first-team wage (default 50%). Tier 8 went from
> **1.97x an average wage to 0.05x**, tier 7 from 0.56x to 0.15x, and **tiers 1-6 are
> byte-identical** — which is the point of a cap rather than a replacement. The original finding
> is kept below because the reasoning is what justifies the shape of the fix.

### The original finding: The youth scholarship is now MORE than an average tier-8 wage

`youthScholarshipWagePence` is a flat **£250/week** for a player signed below first-team age.
Against the turnover model's average first-team wage:

| tier | mid wage / wk | scholarship ÷ mid wage |
|---|---|---|
| 1 | £132,892 | 0.00× |
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

### Why this is not implemented

Three reasons, in order:

1. **It is a client-side mechanic.** The backend publishes these figures and does not read them.
   A new key is worth nothing until the app consumes it, and the shape should be agreed with the
   app team first — publishing a guess would just create a second thing to keep in sync.
2. `.context/shared/api-backwards-compatibility.md` is explicit that nothing should be added to
   a published surface speculatively.
3. The brief for this step says report and propose, not change.

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
