# Last Sync

> Updated by the acting agent every time any stage's `output/` is written
> (see `SKILL.md`'s "How to use this skill", step 5). Read by the
> session-start staleness check (see `SKILL.md`'s Triggers table) to find
> commits that have landed since `.context/` was last reviewed.


- **Commit:** af87ee0311b9f8057edb974e23e289a3a67d4c26
- **Date:** 2026-11-23
- **Stages touched this pass:** `01_overview`, `03_data`, `04_interfaces`, `07_synthesis`, shared

> ⚠️ **The three bullets above are what the staleness check reads** (`SKILL.md`, Triggers table:
> `git log <Commit>..HEAD -- . ':!.context' ':!.agents'`). Keep them at the top and update them on
> every pass. Older passes were rotated, verbatim, to
> `wunderkind-docs/context-archive/backend-shared-last-sync-history.md` on 2026-11-23. Keep roughly
> the last few passes here; add new passes directly below this note, newest first.

## 2026-11-23 — A retried world pack tier replays its own world (`af87ee0`, backend `development`)

Owner: *"we should never get mixed worlds."* Reverts `df3cae1`'s delete-on-serve: a served row is
stamped `served_at` (`Version20261123120000`) and kept, a retry replays it, a claimed set missing a
tier answers 410 instead of a rebuild, delete-all spares loading worlds, and `app:worldpack:maintain`
clears finished worlds after an hour. Stages: `03_data`, `04_interfaces`, `07_synthesis`, `01_overview`
(`deployment.md`), shared (compatibility log). **Tests written but not run in this session** (no PHP
8.5 or database here): `InitializeSetClaimTest`, `WorldPackSetClaimTest`.

## 2026-11-23 — Correctness pass over the nine commits since `5f1aff4` (backend `development`)

**Documentation only.** Reviewed against `5d162c1`.

- **World pack cache (`df3cae1`):** delete-country now deletes every cache row for the country
  (`deleteAllForCountry`), and `tier()` deletes each row once served (`consumeTier`). Corrected in
  `architecture-notes.md` ("The claim", "Keeping the buffer stocked"), `routes.md`, `services.md`,
  `deployment.md` trap 1 and the compatibility log. ⚠️ A retried tier is no longer a replay; it is
  built inline from a fresh generation. Fixed the same day, entry above.
- **`countryFactorBp` is published (`eb31662`):** `services.md` said the opposite; corrected.
- **Migrations recorded:** `Version20261121220000` (A32b, `scoutJudgementBandBp`, pack v15) and
  `Version20261122230000` (the two GameConfig transfer dials, `5d162c1`), with `entities.md`.
- **Added:** `LocationDeletionService` and `POST /admin/locations/enrichment/delete` (`25fbb1c`);
  the pool generators' ENABLED COUNTRIES quick select (`98ae970`). `65d75bc` is layout only.
- **`current-focus.md`** turned back into an index of live work; its 2026-11-02 → 2026-11-18
  changelog moved verbatim to `wunderkind-docs/context-archive/backend-07_synthesis-current-focus-history.md`.

- **A27: the scout age gate made to work, by generating the two keys it reads (2026-11-20)**, on
  `development`. **Stages touched:** 03_data (`migrations.md`), 04_interfaces (`services.md`),
  shared (this file). Report:
  `wunderkind-docs/economy-loose-ends/a27-scout-age-gate-report.md`.

  **The ruling.** Owner, 2026-11-20, asked whether scouting accuracy should vary by the player's
  age: *"Yes, it should."*

  🔴 **The defect was that a feature had never run once.** The app's `ageGateFor()` reads
  `judgements.youth` for an under-18 and `judgements.senior` otherwise.
  `ScoutGenerationService::build()` — the only path that has ever written a persisted scout — wrote
  five keys and neither of those, so the `: 0` fallback was the only branch that had ever executed,
  for all 5,278 scouts on dev and every scout in every save. With `AGE_GATE_WEIGHT` at 0.3 that
  made every scout's **displayed accuracy 70% of its designed value** and left the **real noise
  band never tightened**. Display and behaviour agreed with each other, so nothing looked broken.

  **What landed.** `build()` now rolls `youth` and `senior` from the same
  `PoolConfig::$scoutJudgementMin/Max` as the other five, independently of each other and of
  `potential`; `Version20261120120000` adds both keys to every existing scout and re-derives
  `ability` behind them in the same migration; `WORLD_PACK_VERSION` 12 → 13.

  **The decision, stated.** The age-band pair deliberately gets **no bounds of its own** — they are
  judgements on the same 0-100 scale, so a separate pair would be a second config surface for one
  quantity, and `ScoutAbilityCalculator`'s 70/30 weighting was measured against judgements drawn
  from this one flat range. Deriving the gate from existing keys was rejected on arithmetic:
  `youth = potential` collapses the app's blend to `potential` and changes nothing for exactly the
  under-18s the gate exists for.

  ⚠️ **The interaction with A26 was the thing to get right.** `ScoutAbilityCalculator` averages
  judgements **over the keys present**, so five keys to seven moved every scout's `ability`, and
  (from A24) will move every scout's wage. The migration re-derives the column in the same
  statement set rather than behind it, so stored ability never disagrees with the formula;
  `app:backfill-scout-abilities --force` was run after and reported **0 of 5,278 rows changed**,
  the end-to-end proof the SQL and the PHP agree. Dev distribution either side: **11-48 (mean
  29.13, sd 6.90) → 12-47 (mean 29.11, sd 6.76)** — the mean barely moves (same draw
  distribution), the spread narrows slightly (a mean of seven has less variance than a mean of
  five).

  **A26's five-key pin was changed deliberately, not deleted.**
  `ScoutGenerationServiceTest::testJudgementsAreTheFiveAuthoritativeKeys()` is now
  `…TheSevenAuthoritativeKeys()`, with the ruling quoted in its docblock and a restated account of
  what it still guards (that a key change moves every ability and wage). It did its job by making
  this change impossible to land quietly. A second test pins that the two keys are independent
  rolls within the configured bounds.

  **No DDL** — `scout.judgements` is a `json` column — so unlike `Version20261120100000` this
  needed no hand-application to the untracked `wunderkind_test` database.

  **Measured evidence the owner asked for**, real dev scout `01a0e9cd-b789…` (potential 42, youth
  79, senior 28), real app `scoutAccuracyPercent`: age 17 **29% → 53%**, age 25 **29% → 38%** —
  identical at both ages before, 15 points apart after.

- **A26 phase 1: scouts get a real `ability`, 1-100 (2026-11-20)**, on `development`.
  **Stages touched:** 01_overview (`environment.md`), 03_data (`entities.md`, `migrations.md`),
  04_interfaces (`services.md`), shared (this file). Report:
  `wunderkind-docs/economy-loose-ends/a26-scout-ability-report.md`.

  **The ruling.** Owner, 2026-11-20: *"add 'ability' value, 1-100, have it driven off experience
  and judgements, to set a single figure, then we'll base wage on that"* - because *"experience
  doesn't match 1 to one"*. Phase 1 is **the field only**; basing the wage on it is A24 and is
  blocked behind part B's budget move, so nothing monetary is wired here and no salary moves.

  **What landed.** `Scout::$ability` (int, unsigned, NOT NULL, 1-100) + `ScoutAbilityCalculator`
  (`clamp(1, 100, round(0.7 x experience + 0.3 x mean(judgements)))`), set at both generation
  paths, derived on `prePersist` by `ScoutAbilityLifecycleSubscriber` so no creation path can
  forget it, published on `GET /api/market/data` and in `buildScoutSnapshot()`, exposed
  READ-ONLY in the admin, and backfilled for all 5,278 dev scouts inside
  `Version20261120100000` itself rather than behind it. `WORLD_PACK_VERSION` 11 -> 12.

  **The 70/30 weighting is an owner decision, and the reason is BANDING.** `experience` is
  tier-banded; `judgements` are not (flat global `scoutJudgementMin/Max` at every tier). So
  weight given to judgements is weight taken from tier separation - which would re-open the
  £2,230/wk tier-8 scout that banding was added to fix, and A24 prices wage off this figure.

  ⚠️ **The 5-vs-7 judgement disagreement was real in code, and both repos were right.**
  `ScoutGenerationService` writes 5 keys and is the only path that has ever written a persisted
  scout (all 5,278 dev rows); `GenerateMarketDataCommand` writes 7 for a population not in the
  table. The mean is taken over keys PRESENT - a fixed /7 would under-rate every real scout by
  ~29%.

  ⚠️ **PHP and the migration's SQL disagreed on 6 of 5,278 rows** (exact-.5 rounding: binary
  floats vs `NUMERIC`). Fixed by exact integer arithmetic in the calculator, not by tolerating
  it - see `03_data/output/migrations.md`.

## 2026-11-20 — A24: a scout's wage is priced off `ability`, and is a DERIVATION (backend `development`)

**Stages touched:** `04_interfaces/output/services.md`, `03_data/output/schema.md`,
`03_data/output/entities.md` (corrections only — no stage regenerated).
**No migration. No schema change.** Both columns already existed, so `wunderkind_test`'s
hand-migration trap does not apply to A24 — stated in the subscriber test so nobody goes looking.

Two owner rulings, 2026-11-20, in the same pass.

**1. `ScoutWageCalculator` interpolates `ability`, not `experience`.** *"Give scouts a real ability
and price on that"* — `experience` goes back to meaning career length and scouting effectiveness.
A26 built the column and A27 backfilled it, and `ScoutAbilityCalculator`'s docblock already named
A24 as its consumer. `weeklyWageForScout()` reads `getAbility()`. The arithmetic is untouched, so
every value assertion in `ScoutWageCalculatorTest` held verbatim; **what moved is which of two
0-100 ints is fed in**, which is invisible to a type checker, so the entity-overload test now sets
them to DIFFERENT values (experience 50, ability 30) and asserts 30 and NOT 50.

⚠️ **`ScoutGenerationService` had to REORDER.** `setWeeklySalary()` sat immediately after
`setExperience()`; `ability` does not exist until the judgements are rolled, so pricing there would
have silently used the entity's floor of 1 and put every generated scout at the very bottom of the
band. The wage is now set after `setAbility()`, and the generation test asserts it is neither the
floor's price nor experience's. `GenerateMarketDataCommand` names its local `$ability` for the same
reason.

**2. `Scout::$weeklySalary` is a DERIVATION, not an override.** `ScoutAbilityLifecycleSubscriber`
now derives it on **`prePersist` AND `preUpdate`**, from the DERIVED ability (ability first, then
the wage — the order is load-bearing: a wage off a stale ability is a plausible-looking wrong
number), and the admin field is **read-only** with the help text rewritten. ⚠️ **The old help text
promised *"set by hand here only to override one scout"*, and `services.md`'s paragraph arguing
that asymmetry was CORRECT for the world it was written in — it is quoted and corrected there
rather than deleted.** The test is unchanged ("override or derivation?"); the answer flipped,
because once a wage is priced from a rule it stops being a figure an operator authors. An operator
who genuinely wants to pin one scout's wage now has no route; that is the ruling, and the honest
shape for it would be an explicit `weeklySalaryOverride` column. `--force` on
`app:backfill-scout-wages` is now only for raw-SQL rows and formula/range changes — there is no
hand-set figure left to protect.

**🔴 `PoolConfig::$scoutWageMin/Max` does NOT become dead config, contrary to A24's brief.** The
app now prices a scout at a CLUB from `GameConfig::$wageMultiplierTiers[].staffMultiplier` against
that club's own tier, exactly as it prices a coach. But the band resolves on the paying club's
tier, and a hire-market row has no paying club — one pool row is served to every country, which is
why `MarketDataService::scalePoolWage()` exists at all. So this pair still prices the shared pool,
and **that is the same treatment a market COACH gets** (`weeklySalary` mapped straight through), so
the two roles remain treated the same. Said at `PoolConfigController`, on the entity, and in
`routes.md` app-side.

**The band ceiling, written down rather than changed.**
`WageModelResolver::multipliersFor()`'s docblock said the live table *"lands on the last row by
accident rather than by design"*. It now states the rule — **the LAST ROW IS THE CATCH-ALL whether
or not its `maxAbility` is `null`** — with the measured reachability (2,186 of 9,784 staff, 22.34%;
`coach_ability_max` 75; 26 of 26 countries authoring a tier max above 60; global authored max 95)
and a pointer to `band-ceiling-finding.md`. `GameConfig::$wageMultiplierTiers`'s contract says the
same. **Nothing about the resolved multipliers changed** — a 61+ ability takes the same branch it
always took. Restoring the `null` in the live row is the equivalent fix and is the owner's admin
edit; the app carries a test proving the two tables are identical at every edge, which is the proof
that edit is safe. **60–95 was NOT re-banded**: calibration, unruled, and the app's CHECKS panel
now prints the gap so it can be measured first.

**Tests: +6, all attributable.** `ScoutAbilityLifecycleSubscriberTest` +5 (the wage is derived on
persist; it comes off ability and not experience; an edited input re-derives it and the new figure
is WRITTEN not just set; a hand-written wage is corrected; an unrelated update leaves it alone) and
`ScoutGenerationServiceTest` +1 (banding the experience still moves the wage, through ability).
`ScoutWageCalculatorTest` renamed its cases, no count change.

**Full report, with the live before/after across all 5,278 dev scouts and the band's own figures:**
`/Users/courtneyadesile/Documents/WunderkindFactory/docs/economy-loose-ends/a24-wage-bands-report.md`


## 2026-11-20 — A25: staff ability gets its own authored per-country/per-tier table (backend `development`)

Owner ruling, three limbs, all settled in one change: staff ability gets **its own authored table,
per country and per tier**, replacing both the flat globals and the borrow-the-player-band
workaround; **a staff ability is defined against the player `current_ability` scale**, not
potential and not a scale of its own; and **a 4-tier country is authored at four tiers**, not
padded to eight.

**New: `pool_config.staff_ability_ranges`** (`json`, `Version20261120150000`) and
**`StaffAbilityRangeService`**, the one place it is resolved — authored row →
`DEFAULT_TIER_BANDS` → the untiered pair, with the source reported for the same reason
`StarterPackService::resolveAbilityRange()` reports its own. I followed the existing authoring
pattern (a JSON column on a singleton config entity, a Twig grid, a POST handler) rather than
inventing a new entity shape: that is what `StarterConfig::$leagueAbilityRanges` is, and the staff
table belongs beside the pool config it is read with.

**🔴 The live row disagreed with the entity default again, twice.** `coach_ability_min/max` is
**4–75** live against a docblock and column default of 40–75 — a span so wide it was not a band
(a tier-8 club could draw a 75 coach, a tier-1 club a 4). And `starter_config.league_ability_ranges`
for EN is **40–90 down to 2–7** live, not the committed 75–95 down to 10–25. That second one
changed the design: the seed is computed **from each country's own authored player band** rather
than hardcoded, because a hardcoded table would have been on the wrong scale for the row the game
is running. Count them as instances four and five of the governing trap.

**The seed is measured, not chosen.** `bandFromPlayerBand()` = `[0.45 × midpoint, 0.85 × midpoint]`
of the tier's authored player band. 1,200 generated first-team players per tier land at a current
ability of almost exactly `0.6 ×` their potential band's midpoint, p25–p95 spanning `0.45×`–`0.85×`,
holding to within a point or two at every live tier. ⚠️ **A fit, not a law** — against the
*committed* default band it runs ~10% high at tiers 1–3, because current ability compresses against
high potential. So it is a **seed an operator edits**; choosing the numbers is calibration and was
out of scope. `DEFAULT_TIER_BANDS` is that transform applied to `StarterConfig::defaultTierRanges()`
and is pinned tier-for-tier, so retuning the player defaults cannot leave the staff defaults
describing a scale that no longer exists.

**🟡 The ruling's "SC has 4 tiers" is true of LIVE and false of this dev box**, where SC authors and
runs eight tiers with 3 clubs each. Neither number is hardcoded as a result: **depth comes from the
country's own authored player band**, which `StarterAbilityRangeService::syncToPyramid()` already
keeps in step with the pyramid and which the app's country picker already counts as a country's
depth. One source of truth for depth, and **nothing wired around club counts**. Verified by running
the migration's own SQL against the LIVE export: SC comes out at exactly four tiers, the other 25
at eight.

**🔴 `coachAbilityMin/Max` was NARROWED, not removed, and it is not dead config.** It bands the
UNTIERED population only — the shared hire-market pool, one row served to every country at every
tier, with no tier to be banded against. **Exactly the precedent A24 set for `scoutWageMin/Max`**,
whose brief also predicted it would go dead and which also did not, for the same reason. Removing
it would have forced an untiered band to be invented elsewhere. Said on the entity, on the admin
card (a visible "Untiered only" warning), in the controller's save, in `resolve()`'s reported
source, and pinned from both sides by `StaffAbilityRangeServiceTest`.

**The `$abilityRange` parameter is REMOVED, not defaulted**, from `StaffGenerationService::build()`,
`ScoutGenerationService::build()` and `WorldPackClubGenerationService::generateStaff()/
generateScouts()` — a caller that can pass a band can pass the wrong one, and leaving the hole open
is how the workaround survived. `StarterPackService:151-152`, `WorldInitializationService` and
`WarmWorldPackClubMessageHandler` now pass `($country, $tier)` only, each with its comment rewritten
to say what replaced the borrow and why (the band is applied to POTENTIAL, so the squad lands below
it while a coach handed the same band is at that figure from day one).

**Scouts: the banded input moved, the experience WINDOW did not.** A scout's `ability` is derived,
so there was no independent ability resolution to move; the tiered input that determines it now
comes from the staff table, and the flat untiered `scoutExperienceMin/Max` (4–34 live) is untouched
by ruling. ⚠️ **One pre-existing scale conflation remains and is flagged not fixed**: the banded
figure lands on `experience`, a years-of-experience axis, while the table is defined against
`current_ability`. The tiered input was already a different quantity from `experience` before A25
(it was player potential), so this makes the staff comparison agree without settling what the
experience axis means. Separate ruling.

**No wage pricing was touched** — `git diff --stat` over `src/Service/Economy/` and
`ScoutWageCalculator.php` is empty. A staff `weeklySalary` does move, because
`staffWeeklyPence()` takes ability as an input and the ability changed: the rule unchanged applied
to a corrected input.

**No backfill, and this is measured.** ⚠️ **`staff` has no `club_id` column**, so all 9,784 staff
and 5,278 scouts in dev are shared-pool rows drawn from the untiered band this change leaves alone
(their 4–75 spread is that band). A club's staff exist nowhere but in cached world packs and
starter packs, invalidated by **`WORLD_PACK_VERSION` 13 → 14** in this commit — and the bump is
what delivers the fix, since nothing re-derives a staff member the way
`app:backfill-scout-abilities` re-derives a scout's ability. Nothing destructive was run.

**🔴 The ruling doc's band-ceiling blocker was already closed by A24** before A25 started: an
ability above `wageMultiplierTiers`' top row lands on that row via the trailing return, pinned by a
test. The doc's warning was live when written and is now stale.

**Tests: +19, all attributable.** `StaffAbilityRangeServiceTest` +15 (new), `PoolConfigControllerTest`
+2 (the grid persists and merges; a transposed pair is ordered), `StaffGenerationServiceTest` +2
(a tiered build uses the table and not the flat pair; an unauthored tier falls through to the
defaults — and its flat-pair case was renamed to say it is the untiered one).
`ScoutGenerationServiceTest` and `WorldPackClubGenerationServiceTest` kept their counts.

⚠️ **The `wunderkind_test` trap DID apply this time** — this migration adds a column, so the DDL
was hand-applied there before the suite would pass.

**Full report, with the live row, the scale measurement, the before/after of generation and
everything in the ruling doc I found to be wrong:**
`/Users/courtneyadesile/Documents/WunderkindFactory/docs/economy-loose-ends/a25-ability-bands-report.md`

**🔴 AND IT DOES NOT FIX THE SCOTTISH NEW-GAME SCREEN — measured, and said rather than implied.**
The PM relayed device evidence from OTA 491: a new tier-4 Scottish club projects **player wages
£12k/wk against staff wages £52k/wk**, insolvent from week one. A25 changes exactly one input to a
staff wage (the ability), so the before/after ratio of a staff bill **is** the ratio of expected
`staffMultiplier` — computed through the real `multipliersFor()` over the live
`wageMultiplierTiers` and the live authored bands: **EN tiers 2–8 fall 18%–77%** (tier 8: 8.528 →
2.000) and EN tier 1 rises 21.6%, which is the chartered defect removed. But **every SC tier
RISES, tier 4 by +10.1%** (8.528 → 9.391).

**The cause is not the ability band.** Live SC authors `75-95 / 65-85 / 55-75 / 45-65` — tiers 1–4
of an EIGHT-tier ladder — so **Scotland's bottom division is authored as a mid-table English one**,
and a seed faithful to that input is necessarily high. The same input sets
`TierBandScaleResolver::scaleFor` to **64.7% for SC tier 4** against **6.9% for EN tier 8**, so two
*bottom* divisions differ by **44x** per staff member before A25 is involved at all. The Scottish
screen needs (a) SC's player band re-authored for a 4-tier pyramid — calibration of the PLAYER
table, which would move every Scottish player's generation and pricing, so explicitly not A25's;
(b) A24's staff/scout **country scaling**, a pricing defect being measured there; and (c) a look at
headcount — live starter counts are 20 players against **13 staff** (`starterCoachCount: 8`).
A25 is a necessary part of fixing that screen and not a sufficient one. §12 of the report has the
full table and a stated prediction to check against: on a pack rebuilt at v14, a tier-4 Scottish
club's staff wages should go **UP** by roughly a tenth.

## 2026-11-20 — Country scaling: TV, prize, position pot, parachute and youth scholarship now scale by country (backend `development`)

Owner ruling: *"everything should scale. TV, prize, position and parachute."* The authored ladder is
untouched (one country-blind figure per tier); `countryFactorBp` is applied where each figure is
RESOLVED. England (factor 10000) is byte-identical across all 8 tiers; Scotland is exactly 1/10.
`LeagueSizeResolver::tvPoolPenceFor/tvDealFor`, `LeaguePayoutResolver::parachuteFor` and an
explicit prize/pot override is now a baseline-country figure (`scaleExplicit`);
`WageModelResolver::youthScholarshipPenceUnder` scales its anchor and floor. Transfer fees already
scale (derived from the served wage). Gate check 6 in `app:economy:wage-table` is REVERSED: it now
reports a pool pre-scaled by hand (which would scale twice). Facilities/upkeep deliberately NOT
scaled (owner), so Scottish solvency reads WORSE: variant gate PASS -> FAIL at all four SC tiers —
expected, not a regression. Full suite run DEFERRED (machine loaded by orphaned processes); targeted
suites green. Report: `docs/economy-loose-ends/country-scaling-report.md`.
**For the PM:** `stages/04_interfaces/output/services.md` and `docs/api/economy-config.md`'s parachute
paragraph ("serves verbatim") — the latter I amended; the former not checked.

## 2026-11-20 — Documentation pass: `.context` brought into line with the day's five commits (backend `development`)

**Documentation only. No application code changed, no migration run, no database touched** — every
schema fact below was confirmed by reading the migration and the entity.

Pass commit range: `c05d54a7`, `3e1555c5`, `6c5e4ad5`, `a818a60b`, `c11c7f2d` (plus `d8376d94`,
found undocumented). `c05d54a7`, `3e1555c5`, `6c5e4ad5` and `a818a60b` had each already updated the
stages they touched and those entries were checked and found accurate. **`c11c7f2d` (country
scaling) updated only this file**, which is where most of this pass went.

**Corrected — country scaling (`c11c7f2d`):**
- `04_interfaces/services.md` — the `LeaguePayoutResolver` paragraph said an explicit prize/pot
  override is used **"verbatim — no ratio, no country factor"**. 🔴 **That is retired**: it is now a
  baseline-country figure scaled via `scaleExplicit()`. This is the claim the country-scaling
  session flagged for the PM and could not check; it was in `services.md` as the prize/pot override
  rather than as the parachute.
- `04_interfaces/services.md` — **check 6 now means the opposite of what the file said.** It used to
  tell an operator to hand-author a scaled TV table; that would now scale twice. Old text kept as
  the history that explains the check's name. Struck the claim that `league.tv_deal` is *"the one
  money figure the country factor never reaches"* — after this commit there is no such figure.
- `04_interfaces/services.md` — `LeagueSizeResolver` gained `tvPoolPenceFor()` and `tvDealFor()`,
  and `derivedTvDealPence()` now divides the **scaled** pool. Recorded, with the "publish this,
  never `getTvPoolPence()`" rule, the raw-pool-in-the-authoring-surface exception, and the
  pre-change `tv_deal` cache that check 9 will flag for SC until those rows are re-saved.
- `04_interfaces/services.md` + `03_data/entities.md` — the youth scholarship anchor and floor are
  scaled. Recorded **why it was country-blind by construction** (the factor cancels in
  `anchor × avg(tier) / avg(tier 1)`) rather than as an omission.
- `03_data/entities.md` — the parachute section said **"SERVED VERBATIM … without reading
  inside"**. Narrowed to one key: `parachuteFor()` scales `perSeasonPence` and passes everything
  else through. The passthrough argument itself is NOT withdrawn.
- `04_interfaces/services.md` — the export's three resolved keys are now scaled (raw `*Override`
  keys still unscaled, which is what keeps the round-trip exact); `WorldOverviewService` takes
  `LeagueSizeResolver` and caches for an hour.
- `07_synthesis/current-focus.md` — struck "verbatim" from its parachute line.

**Corrected — staff ability bands (`a818a60b`) and the pack version:**
- `07_synthesis/architecture-notes.md` trap 3b described **the workaround as the design**: staff
  ability from the club's PLAYER band via `StaffGenerationService::build()`'s `$abilityRange`. That
  parameter is **removed, not defaulted**, and `StaffAbilityRangeService` is the only resolver.
- `02_architecture/structure.md` — `StaffAbilityRangeService` recorded beside its player twin, with
  the STAFF ABILITY BANDS grid on the **existing** `/admin/staff-pool-config` screen and save route.
- `04_interfaces/controllers.md` — `PoolConfigController` entry gained the grid, and the note that
  `coach_ability_min/max` is **narrowed to the untiered hire market, not dead config**.
- `04_interfaces/services.md` — **`WORLD_PACK_VERSION` read 11 here while the constant was 14.**
  ⚠️ That line has now been stale twice (it read 9 at 10 before), which is the argument for reading
  `WorldInitializationService:277`. Recorded v12/v13/v14 as ability-shaped census content, so v11's
  "last bump money will ever cause" stands — **but qualified in three places** (`services.md`
  twice, `01_overview/tribal-knowledge.md`): a pack's census content is frozen at build time, so
  re-authoring `pool_config.staff_ability_ranges` reaches a cached country only through a rebuild.
  "Re-authoring any figure needs no bump" is a claim about **price**, not a general licence.

**Added — `03_data/migrations.md`:** an entry for **`Version20261120110000`** (`d8376d94`,
`bond_decay_floor` 50 → 20), which was the only migration on disk with no entry. Not one of the
five commits this pass was briefed on; found by diffing `migrations/` against the document.

**Recorded — `01_overview/environment.md`, new "Test COUNTS through 2026-11-20" section:**
3,271 at `6c5e4ad5`; 3,290 at `a818a60b`; **3,359 at `a818a60b` re-measured**; 3,366 at `c11c7f2d`.
🔴 **The 69-test gap at the same commit is recorded as OPEN and UNEXPLAINED — not resolved in
either direction**, because both figures claim the same tree and it is not known which is wrong.
The guidance recorded is to re-measure at the commit you build on and quote your own figure, which
is what surfaced the gap rather than absorbing it (3,359 + 7 = 3,366 reconciles exactly; against
3,290 it would have read as a spurious +76). Also recorded: this file is the **owner** of the
three-to-five range, that the range is about **failures and never a count**, the narrower
`--exclude-filter admin_logs` (keeps 71 of 72 against the whole-class filter below it), and
`--colors=never` when the summary numbers are to be read.

**Recorded — the two world-pack operational traps**, in `01_overview/deployment.md` as their own
section, with pointers from `02_architecture/structure.md` and
`07_synthesis/architecture-notes.md`:
1. 🔴 **The admin "rebuild the world pack cache" spares CLAIMED sets by design** (`deleteUnclaimed`).
   After a day of testing every set is claimed, so it deletes nothing, **reports success**, and the
   stale packs survive. `app:worldpack:warm <CC>` builds inline and works.
2. 🔴 **On the dev stack the async build does not run at all.** Dispatch succeeds, `crond` is
   confirmed running, `deficit()` is version-aware, the version is stamped — and nothing is written.
   Cause unknown. ⚠️ **`supervisorctl` cannot work on that image**: `docker/supervisord.conf`
   defines no control socket, so "no such file" is by construction, not a fault — use
   `docker exec <container> ps aux`.

**Not done, deliberately:** nothing was restructured, pruned or deleted. Superseded text was
corrected in place or kept with the correction beside it. `CLAUDE.md` does not exist in this repo
and `AGENTS.md` is the gitignored pointer — it carries no figures, so **no re-mirror was needed**.
**What I believe is wrong but did not rewrite** is listed for the PM in the report, including
`07_synthesis/current-focus.md` still describing 2026-11-18 as the current focus.

**Report:** `/Users/courtneyadesile/Documents/WunderkindFactory/docs/economy-loose-ends/ctx-backend-report.md`

---

## Pass — B2, world-pack dispatch defect, 2026-11-21, at `d731db22`

**Scope:** `04_interfaces` only, and only where this pass's own change made the text wrong.

**Corrected — `04_interfaces/routes.md`:** `POST /admin/worldpack-cache/top-up` now reaps the
country's stranded BUILDING sets, returns `claimable`/`target`/`building`/`reaped` on every
answer, and **refuses a STARVED country with `409` + `success: false`** — nothing claimable, yet
a zero deficit because BUILDING rows are counted in flight. That case previously answered
`success: true, reason: 'already at target'` while the screen's own stock column read
`0/2 SHORT`.

**Corrected — `04_interfaces/services.md`:** `WorldPackSetService` now exposes **`stock()`**
(`target`/`claimable`/`building`/`deficit` + a **`starved`** flag) and
**`reapStaleBuilding()`**; `deficit()` delegates to `stock()` so the gate and the reported
figures cannot drift. `WorldPackSetTopUpService` passes both through and logs a starved country
at WARNING rather than the previously invisible INFO `at_target`. The entry's existing claim
that `BUILD_STALE_AFTER` stops a killed worker suppressing top-ups "permanently" is kept and
qualified: the bound narrows that window, it does not close it, and inside the window the gate
reports a starved country as stocked.

🔴 **FLAGGED, NOT EDITED — one claim in this file and two stages are now contradicted by
measurement.** The entry above in this same document, `07_synthesis/architecture-notes.md`'s
"Keeping the buffer stocked" bullet, and `01_overview/deployment.md`'s trap #2 all state that
the async world-pack build does not run on the dev stack. **It does.** Two sets were built on
dev through the queue alone on 2026-11-21 — SC `01a1ff72…` (18:51:03 → ready 18:51:10) and EN
`01a1ff79…` (queued 18:58:04, picked up 18:59:00, ready 18:59:17) — with `messenger:consume
worldpack` and `worldpackset` both in `ps aux`, queue depth zero and nothing world-pack-shaped
in the failure transport. Trap #1 (the rebuild sparing CLAIMED sets) is unaffected and stands.
Status and review are the PM's, so the three places are listed in the report rather than
rewritten here.

**Not done, deliberately:** nothing restructured, pruned or deleted; no stage outside
`04_interfaces` touched.

**Report:** `/Users/courtneyadesile/Documents/WunderkindFactory/wunderkind-docs/economy-loose-ends/b2-worldpack-dispatch-report.md`

---

## A32 — the flat judgement term in a scout's `ability` (2026-11-21)

**Backend change: a docblock only. No code, no config, no migration, no pack version bump.**
`src/Service/ScoutAbilityCalculator.php` — three corrections and the A32 measurements, recorded
beside the 70/30 ruling with an explicit note that **the ruling is not the defect**:

1. 🔴 It said the banded input comes from *"the club's own `league_ability_ranges` window"* — that
   is the **PLAYER** table and a POTENTIAL figure. A25 moved it to
   `StaffAbilityRangeService::resolve($country, $tier)` on 2026-11-20 and removed the parameter so
   no caller can put the player band back.
2. 🔴 It said `ScoutGenerationService` rolls *"all five"* judgements and that *"all 5,278 scouts in
   the dev database carry exactly these five"*. **A27 made both populations seven** on 2026-11-20.
   Measured the same day: `SELECT cardinality(…), count(*) FROM scout GROUP BY 1` returns the
   single row `7|5278`. The *rule* the passage supports — average over the keys PRESENT — is right
   and unchanged; only its evidence had moved, which mattered because that evidence is the
   argument against a fixed divisor.
3. The predicted risk, recorded as having happened and measured.

**The finding.** `0.3 × mean(judgements)` is a **flat 15.75 points** on every scout at every tier
(the live window is `scoutJudgementMin/Max` = **15/90**, not the entity's 40/80), and the banded
term cannot reach it. Live EN staff band (`poolConfig.staffAbilityRanges.EN`): t1 29–55, t8 **2–4**.
So t1 is `0.7×42 = 29.40` banded + 15.75 flat = 45.15 against a coach's 42 — 34.9% flat, and the
two roles stay comparable. t8 is `0.7×3 = 2.10` + 15.75 = **17.85 against a coach's 3** — 88.2%
flat. Priced through the app's band rung (`ability^k`, k = 2.5 live, times the authored band ratio)
that 5.95× ability gap becomes **65.6×** in money. Reproduced to the penny on the owner's own fresh
EN t8 save: 12 coaches £1,141.14/wk beside one ability-19 scout on £9,740.57/wk, **89.5%** of
£10,881.71. ⚠️ **This docblock predicted exactly that figure** — *"0.7/0.3 gives 61 vs 19"* — so
tier 8 landing at 19 was known and accepted. What changed is that A24 then priced the wage off
`ability` through a power law.

**Unruled as of 2026-11-21 at `3ff5a312`. Nothing was taken.** `WEIGHT_EXPERIENCE` /
`WEIGHT_JUDGEMENTS` are untouched, `WORLD_PACK_VERSION` stays **14**, and no pack needs rebuilding
for this change. The four levers, the curve of the outcome against the judgement weight at both
ends of the pyramid, and the pack/version answer either way are in
`wunderkind-docs/economy-loose-ends/a32-scout-ability-floor-report.md`.

🔴 **Flagged for the PM, not edited — a THIRD copy of the 70/30 blend.** The brief and
`scoutAbility.ts` both name two (the calculator and the app mirror). There is a third:
`migrations/Version20261120100000.php:86-87` does the same arithmetic in Postgres NUMERIC. It has
already run everywhere, so it is historical for existing databases — **but a fresh database
replaying migrations would backfill the OLD abilities**, and only
`app:backfill-scout-abilities --force` would then correct them. Any change to the weights must
touch all three. Worth its own small task: have the migration call the calculator, or document it
as frozen at v1.

**Not done, deliberately:** no stage `output/` touched (nothing in it is made wrong by a docblock
edit), nothing restructured or pruned, no header bullet changed.

---

## A32b — scout judgements are banded by tier, and the band is one authored dial (2026-11-21)

**Owner ruling, asked directly:** ***"Lower tier scouts genuinely worse at judging players is the
expectation."*** And, on being offered band numbers: *we can worry about the bands after we've
tested that the process of matching the scaling to the tier is correctly implemented, because we
can then view the raw data against the scouts' reviews and make a judgment call.* **So the
mechanism shipped and the number did not.**

**What changed.** `ScoutGenerationService::build()` now bands all seven judgement categories off
`StaffAbilityRangeService::resolve($country, $tier)` — **the same authored row the `experience`
and a coach's `coachingAbility` already come from** — stretched by one new dial,
`PoolConfig::$scoutJudgementBandBp` (basis points, 10000 = ×1.00 = exactly that band). Admin →
Staff Pool Rules → SCOUT GENERATION → "JUDGEMENT vs TIER". `Version20261121220000`.

🔴 **Deliberately NOT a second per-country/per-tier table.** That would be a second authored
surface for "how good is a person at this tier" — the defect class this workstream exists to
remove — and would need 26 × 8 new figures before anything could ship. One source for the band,
one dial for the relationship. ⚠️ **The cost of that choice, stated:** the staff table is shared,
so it cannot be moved to change scouts without moving coaches. That is what the dial is for.

⚠️ **The shared pool is untouched, by the same ruling that left `scoutExperienceMin/Max` alone.**
A pool scout has no tier, so it keeps the flat `scoutJudgementMin/Max` — **which is therefore NOT
dead config after A32: it is the untiered band.** Measured, the complete call graph is two
callers: `WorldPackClubGenerationService:148` (banded) and `MarketPoolService:354` (untiered).

**Measured on real generation**, 400 scouts per tier through the service itself at the shipped
default: every EN tier's scout `ability` now lands inside its own staff band — t8 **2–4** against
coaches on 2–4, where it was ~18 — and the pool row is byte-for-byte the old behaviour
(experience 4–34, judgements 15–90, ability 13–45). On the owner's own club the scout's share of
the staff bill goes **89.5% → 6.0%** and **65.6× → 0.49×** a coach; tier 1 goes 1.19× → 1.00×.

⚠️ **One consequence at the TOP of the pyramid, since the ruling only spoke about the bottom:** at
×1.00 an EN tier-1 scout's judgement mean is 42 against today's 52.5, so displayed accuracy reads
42% rather than 53%. **12500 holds tier 1 at exactly 52.5** while still banding every tier below
it (52.5 ÷ 42, derived not chosen). Flagged to the owner; not taken.

**Nothing re-rolls an existing scout.** Judgements are the person, not a price; no migration
touches them and `app:backfill-scout-abilities` re-derives `ability` from stored inputs. This
lands on new saves and newly generated scouts. The 70/30 weights are untouched in all three of
their copies, and the app's `fallbackScoutAbility` mirror needs no change — it mirrors the
FORMULA, and A32 changed which values are ROLLED.

✅ **PACK: `WORLD_PACK_VERSION` 14 → 15, BUMPED — owner ruling 2026-11-21, granted after the
recommendation below. In the SAME commit as the generator change, deliberately:** bump first and
every country rebuilds at v15 with the old flat-judgement scouts baked in; generate first and new
sets are stamped v14 beside existing v14 sets, so two scout populations share one version number
and the pack indicator cannot distinguish them. The v15 docblock records both orderings.
✅ **No backfill is needed and none is wanted — measured.** `WorldPackClubGenerationService`
builds pack scouts *"fresh, in-memory, never persisted"*, so the bump regenerating the pack IS how
they are replaced; and every persisted Scout row is a shared-pool row on the unchanged untiered
window — **all 5,278 rows carry `experience` inside 4–34, none above it**, where a persisted club
scout would read up to 55 at EN t1. `app:backfill-scout-abilities` would be a no-op, so there is
no ordering hazard against the bump (unlike `BackfillPersonalitiesCommand`'s documented one).
✅ **The pool keeps its flat window**, mirroring the `scoutExperienceMin/Max` ruling rather than
re-ruling it; pinned by a test that authors a band AND a ×0.01 dial and proves the pool ignores
both. So `scoutJudgementMin/Max` is NOT dead config — it is the untiered band.
✅ **One site, verified:** `WorldInitializationService:277` is the only literal; all 57 other
references read the constant, tests included, and the app gates nothing on it
(`worldPackVersionIsAdvisory.test.ts`). **A fresh save after the deploy should read `15`,
single** — `MIXED: 14, 15` is reachable if a save is created while the deploy is still rolling.

**The recommendation as it was made, kept because the evidence is the useful part:**
`WorldPackSnapshotBuilder:292` publishes `judgements`, so existing v14 sets hold old scouts.
Verified here rather than assumed: **both `countReadyUnclaimed` and `claimOldestReady` filter on
`payload_version`**, so a bump makes v14 sets uncountable (→ deficit → the per-init top-up
rebuilds, 16 s for a full 8-tier England) *and* unclaimable, so no save can be handed an old-scout
set. Without it, each country must be Regenerated by hand and a half-regenerated world is the
MIXED state that cost three hours on 2026-11-20. The *"v11 is the last money bump"* rule is not
breached: judgements and ability are census, and the reason here is a gameplay ruling.

⚠️ **FOURTH INSTANCE OF THE `wunderkind_test` TRAP, and `01_overview/environment.md` §"NOT
migration-tracked" is what caught it.** The first full run went red in a cluster around tests
500–549; `information_schema.columns` showed the new column absent from the test database. The run
was **stopped rather than read** — a suite measuring a schema the entity does not match is not
evidence. Fixed in the prescribed nullable → backfill → `NOT NULL` → `SET DEFAULT` order (22 rows
updated, which is that database's documented leaked `pool_config` population);
`SchemaIsInSyncTest` green afterwards. **A new column is two jobs** and the second has no reminder
attached — worth considering whether that page's warning should sit on the migration template too,
which is the PM's call.

**Not done, deliberately:** no stage `output/` touched; no second authored table; no pack
regenerated and no version bumped; `agent-standing-notes.md` left alone (it has two entries' worth
of material from this pass and curation is the PM's).

**Report:** `wunderkind-docs/economy-loose-ends/a32-scout-ability-floor-report.md` (Part Two).
