# Last Sync

> Updated by the acting agent every time any stage's `output/` is written
> (see `SKILL.md`'s "How to use this skill", step 5). Read by the
> session-start staleness check (see `SKILL.md`'s Triggers table) to find
> commits that have landed since `.context/` was last reviewed.

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

- **A23: `league.slots` retired — every division size is now DERIVED (2026-11-19)**, on
  `development` on top of `9918839c`. **Stages touched:** 01_overview (`environment.md`), 03_data
  (`entities.md`, `migrations.md`), 04_interfaces (`services.md`), shared (this file). Report and
  verbatim evidence: `wunderkind-docs/economy-loose-ends/a23-retire-slots-report.md` and
  `a23-run-2026-11-19/`.

  **The ruling.** Owner, 2026-11-19: *"Retire slots."* `LeagueSizeResolver`'s docblock argued at
  length that the field must stay authored, because *"a derived divisor moves whenever somebody
  clicks Generate"*. The owner heard that and overruled it. ⚠️ **The argument was rewritten, not
  deleted** — both the resolver's class docblock and `EconomyWageTableCommand`'s check 9 quote it
  and record what was given up, because a comment contradicting the code is worse than either.

  **What landed.** `sizeOf()` derives always: NPC club rows, plus one at the country's deepest
  division for the player's own place, and **0 for a tier with no rows at all**. Nothing writes
  the column any more — the admin quick-edit shows the derived size read-only and the world
  export/import drops the key at both ends (`worldEntityProvider()`'s exclusion list). The COLUMN
  is deliberately NOT dropped. Check 9 is rewritten around the two disagreements that remain: a
  stale world pack (FAILS — now the only way left to show a player a wrong divisor) and a stale
  `league.tv_deal` column (WARNS — no player reads it, four admin surfaces do).

  **Blast radius, measured off the 2026-11-19 world export (200 leagues / 3,175 clubs).**
  ⚠️ **All 25 countries checked, not the 2 in scope — 24 are byte-identical** and only **SC**
  moves, 14 → 12 at all eight tiers. The brief's "23 countries unchecked, out of scope" is
  therefore closed rather than deferred. England's variant-gate output is byte-identical before
  and after (`a23-run-2026-11-19/variantGate-EN.{BEFORE,AFTER}.txt`, `diff` empty).

  ⚠️ **A real defect was found and fixed in the same change, by the suite.** The unconditional
  `+1` turned a tier with NO rows into a division of ONE, so `derivedTvDealPence()` published the
  whole pool as a single club's share — the exact "divide by a clamped 1" failure the 0 exists to
  prevent, reached by another route. `EconomyTvCountryScalingGuardTest` caught it.

  ⚠️ **Flagged for the PM, not restructured.** `03_data/output/migrations.md`'s three
  `Version2026111909/12/15` sections are now historical — the column they fill is unread — and are
  marked as such at the top of the first rather than pruned. `04_interfaces/output/services.md`'s
  "a defect this exposes and does NOT fix" is resolved and annotated in place.

  **Suite.** `lando php vendor/bin/phpunit --filter '/^(?!.*AdminShellCoverage).*$/'` → **3,238
  tests** (baseline 3,233; +5), 155,841 assertions, **3 failures, all three proven pre-existing by
  stashing and re-running on the clean tree** (`NotificationLogCrudControllerTest`,
  `CompetitionControllerTest::testShowIncludesResultsOnceRoundsAreProcessed`,
  `FriendlyResolveSingleMatchTest`). `EconomyAuthoredSizeDriftTest` renamed to
  `EconomyDerivedSizeDriftTest`.

- **A16: the two `game_config` polluters now own the row for their duration (2026-11-19)**,
  committed on `development` directly on top of `8298cc60`. **Stages touched:** 01_overview
  (`environment.md`), shared (this file). Report and verbatim evidence:
  `wunderkind-docs/economy-loose-ends/a16-test-pollution-report.md`.

  **What landed.** `EconomyAuthoredSizeDriftTest` and `EconomyTvCountryScalingGuardTest` stash
  `economyBaselineTurnoverPence` in `setUp()` and restore it in `tearDown()`. Both still have to
  author a turnover table in `seed()` — without one no tier resolves and the gate short-circuits
  before the checks they exist for — so the row is OWNED rather than left alone. ⚠️ **The restore
  is in `tearDown()` on purpose, not at the end of each test:** `tearDown()` runs after a failed
  assertion too, so the row is clean exactly when something has already gone wrong. Proved by
  forcing a polluter to fail mid-run (scratchpad only, never committed): the forced failure was
  the ONLY failure, the reader after it still passed, and the row read `[0,...]` afterwards.
  Test-only change; no production code touched.

  🔴 **A10'S "SIXTH KNOWN FAILURE" WAS A DEFECT AND IS FIXED — IT IS NOT A FLAKE THAT STOPPED
  APPEARING, AND IT IS DELIBERATELY NOT LISTED IN `environment.md`.** Listing it would have
  trained the next agent to expect four failures where the baseline says three, which is the one
  habit this project's attribute-by-stashing discipline cannot afford. The known-failures list
  stays at items #1-#5 (range three to five) and now carries a short note saying the sixth was
  fixed, plus what to conclude if that assertion ever fails again (someone's pollution, find the
  writer).

  ⚠️ **TWO CORRECTIONS TO THE BRIEF, both measured rather than argued.**

  1. **It was NOT failing in every full run, and `environment.md` never listed it.** The brief
     said both. A single `--filter`ed full run before the fix showed the documented THREE
     failures and `testTheEconomyBlockShipsSwitchedOff` PASSED, while `polluter -> reader` on its
     own failed every time. The reason is new and worth keeping:
     `tests/Controller/Admin/EconomyAdminTest` sorts between the polluters and the reader and
     zeroes the turnover table through the real admin save, so the full run was green by accident
     of two tests both abusing the same shared row. A10 saw it because it ran the suite in
     20-file batches, where that accident does not hold. **"It passes in a full run" is therefore
     not evidence that a shared-row leak is harmless** — that is now in `environment.md` too. The
     sixth-failure record lived in this file (A10's entry), not in `environment.md`.
  2. The change is **86 lines, not ~20** — almost all of it the docblock explaining why the
     restore is where it is.

  **Suite.** Before: 3,233 tests, 3 failures (#1, #2, #3). After: 3,233 tests, 3 failures, the
  same three, assertions 155,818 -> 155,870. The shared row read `[0,0,0,0,0,0,0,0]` before the
  task and again after it, so nothing leaked either way. `AdminShellCoverageTest` (72 tests) is
  excluded by `--filter '/^(?!.*AdminShellCoverage).*$/'`; the OOM and that workaround are now
  recorded in `environment.md`, which they were not.

  ⚠️ **Three things for the PM.**
  - `environment.md`'s ```` ```bash ```` block at the per-branch-DB section was **unterminated**,
    so the ENTIRE known-failures section (items #1-#5, the three-to-five range, the "not ~31"
    warning) rendered as a literal code block and the `TEST_TOKEN` paragraph that belonged to the
    block sat outside it. Closing the fence and moving those four lines back is the only
    structural edit made here, and it was made because an unreadable baseline is the thing §4 of
    the brief is trying to protect. Flagged rather than hidden.
  - ⚠️ **`EconomyAdminTest` still does not restore `game_config`** — already noted in
    `environment.md`, and this task found it is now load-bearing by accident (see correction 1).
    It is the next polluter and deserves its own task.
  - `WorldOverviewServiceTest` restores `game_config` inline, before its assertions rather than in
    `tearDown()`. It happens to be safe today because the restore sits in a helper that returns
    before anything asserts, but it is the happy-path shape, not the failure-safe one.

- **A18: the catchment fan-base share ladder is published (2026-11-19)**, committed on
  `development` as the single commit directly on top of `b82540d7` (its own hash is not quoted
  here because it moved on an amend; the report has the final one). **Stages touched:** 03_data (`entities.md`,
  `migrations.md`), 04_interfaces (`routes.md`), shared (this file). Report and verbatim
  evidence: `wunderkind-docs/economy-loose-ends/a18-fanbase-backend-report.md` and
  `a18-run-2026-11-19/`.

  **What landed.** A NEW `starter_config.fan_base_share_percent` column
  (`Version20261119190000`), `Record<tier-string, {min,max}>` of whole percentages, seeded
  60-70% at tier 1 down to 15-20% at tier 8, admin-editable per tier at `/admin/starter-config`
  and clamped 0-100 on save, published as `fanBaseSharePercent` on `GET /api/starter-config`.
  It completes the app half committed as `0964fedc` on `new-game-save`, which computes
  `fanCount = floor(catchment x share / 100)` and was inert without it.

  **Four things worth carrying forward.**

  1. 🔴 **`fanBaseRanges` is NOT superseded and was not touched.** Two keys, two units, 1,000x
     apart: that one is an absolute headcount, this one a percent of the plot population a club
     sits on. The app falls back to the absolute band whenever the ladder OR a club's catchment
     is missing, so it is live behaviour. Reading the absolute key as a share would publish a
     tier-1 fan base of 700,000% of the catchment. ⚠️ **Its ENTITY default (50,000-150,000 at
     tier 1) is nothing like the deployed row (700,000-1,500,000)** — read it from an export,
     never from the PHP default. Three tests pin that it did not move, one of them as a
     relationship (every absolute band > 100) rather than as literals.

  2. 🔴 **NO `WORLD_PACK_VERSION` BUMP WAS NEEDED, AND THE BRIEF ASKED FOR ONE.** The brief and
     the app's own `WorldClub.populationSize` docblock both say the pack does not carry
     `populationSize`. It has carried it since `WorldPackSnapshotBuilder` was written — verified
     against cached payloads at versions 8 and 9, with both generation paths delegating to that
     one builder and `InitializeController::tier()` serving the stored payload whole. Bumping
     11 -> 12 would have invalidated **107 cached tier payloads** and forced a manual
     regeneration to publish a field that was already there. The version stays at **11**. The
     app's docblock is wrong and is flagged for the app session, not fixed here.

  3. ⚠️ **The ladder reproduces the England SPREAD but NOT the "uniform x0.47-0.49 offset" the
     plan predicted**, and the reason is the deployed data, not the numbers. England comes out at
     **68.4:1** against today's 73.3:1 (so the rejected 5-10% / 164:1 variant was definitely not
     applied), but the per-tier multiplier runs **x0.057 to x1.92** in England and **x0.010 to
     x0.100** in Scotland. Two causes: (a) **three separate clubs sit in London on the deployed
     export, at tiers 1, 3 AND 6**, so mean catchment is non-monotonic across tiers and a tier-6
     club lands at 2,290,410 fans — above today's tier-1 maximum; (b) `fanBaseRanges` is
     country-blind while Scotland's largest catchment is 635,640 against England's 8,982,000.
     ⚠️ The plan's claim that double-counting a shared city is "invisible to the engine because
     nothing aggregates fan bases" is **true for aggregation and false for the gradient** — the
     pyramid still inverts at that one club. Neither is fixable with a share percentage.

  4. 🟢 **The 42,500 demand ceiling stops being dead code, which is the real result.** Today
     every tier-1-to-6 absolute band has its MINIMUM above 42,500, so the cap binds on no club
     above tier 6 in either country. After the ladder it binds on all but 27 of England's 127
     clubs and all but 6 of Scotland's 111.

  `app:economy:wage-table` is byte-identical before and after for both EN and SC (verified by
  diff across a stash) — this touched no economy dial.

- **A10: the wage scale calibrated against the ability players ACTUALLY have (2026-11-19)**,
  committed on `development` on top of `0e68d6ac`. **Stages touched:** 04_interfaces
  (`services.md`), shared (this file). Report and verbatim evidence:
  `docs/economy-loose-ends/a10-wage-scale-report.md` and `a10-run-2026-11-19/`.

  **The defect, in one sentence.** `EconomyScaleResolver::referenceSquadAbilitySum()` built sigma
  by spreading the tier's authored ability band across the reference squad — but that band is the
  **potential** range handed to `PlayerGenerationService::generate()` as `$potentialRange`, while
  a wage is `S_tier x CURRENT ability^k`. Denominator in potentials, numerator in current ability,
  both errors pushing the same way. Measured against real generated squads, a season bill came to
  **21-35%** of the player budget it was nominally calibrated against. Each member is now a
  potential rung mapped through a new `PlayerGenerationService::expectedCurrentAbility()`;
  coverage lands at **94-124%** and every `S_tier` rose **3.18x to 4.66x**.

  **Four things worth carrying forward.**

  1. ⚠️ **The owner's recorded formula was not the generator's, and implementing it literally
     overshoots by 1.3-2.5x.** `next-work-plan.md`'s OWNER CHOSE (b) gives
     `potential x abilityTarget x mean(position attribute fractions)` and predicts bills near
     70-85%. That covers three of the six attributes: `power`, `stamina` and `heart` are NOT drawn
     from the cap at position fractions — they are computed from height, weight and the
     personality matrix, land at 54/53/61 regardless of potential, and are merely `min($cap, ...)`,
     so below about ability 55 they sit AT the cap. Both models were implemented and measured: the
     literal one gives **128-247%** coverage, the faithful one 94-124%. **So §4's "if you land on
     ~100%, suspect you fitted it" warning was aimed at the wrong model** — ~100% is what the
     turnover model means, and the predicted 70-85% was unreachable by any faithful derivation.
     The residual above 100% at narrow bands is Jensen: `x^2.5` is convex, so a real draw costs
     more than a squad of expected players, worst where a band is five integers wide.

  2. **Nothing was copied into the economy service, and that cost a refactor of the generator.**
     §3.4 of the brief forbade re-deriving the tables. So `PlayerGenerationService` grew
     `ABILITY_TARGET_BANDS` (lifted out of `buildAbilityTarget()`'s if/elseif), `capBounds()`
     (out of `randInCap()`), `weightFor()` and `powerFor()/staminaFor()/heartFor()` (out of
     `buildAttributes()`) — each now called by BOTH the random path and the deterministic
     expectation, so there is one implementation of each formula rather than a mirror. Personality
     needed `PersonalityGeneratorService::expectedTraits()`, a mould-weighted expectation pushed
     through the REAL `applyCorrelations()`; it agrees with 40,000 rolls to within 4.4% on all
     eight traits. ⚠️ **`expectedTraits()` must never build an entity** — a squad of average
     personalities is what the Personality Matrix exists to prevent.

  3. ⚠️ **THE REFERENCE SQUAD HAD THREE DEFINITIONS AND NOW HAS ONE.**
     `EconomyWageTableCommand::squadBillPence()` was spreading the band itself, which passed only
     while the resolver said the same two lines — with the resolver's squad no longer the band it
     reported **143-182%**, a drift that was its own and nothing else's. It now reads the new
     public `EconomyScaleResolver::referenceSquadAbilities()`. **The third copy is app-side and
     was NOT touched** (no cross-repo work): `wunderkind-app/src/economy/harness/loadExport.ts:89`
     `referenceSquad()` still mirrors the old spread, its comment "matching the backend's
     reference squad" is now false, and the `9782.608696` cross-tier invariant that file documents
     **will break** — it becomes `9782.608696 x sigma_old/sigma_new`, which runs 3.18-4.66.
     Flagged for an app-side task. The shipped runtime needs nothing: `clubBudget.ts:226` already
     sums real current abilities.

  4. **An integer overflow the change made reachable, fixed.**
     `WageModelResolver::turnoverPlayerPence()` did `intdiv((int) round(S x shape) x repBp, BP)`;
     `(int) round(S x shape)` reaches 2.8e15 on a steep tier, x 10000 is past `PHP_INT_MAX`, so
     the product became a float and `intdiv()` raised a TypeError. It was always within ~1.3x of
     that ceiling and the fourfold `S_tier` rise pushed it over. The modifier is now folded into
     the same rounding step — exactly equal at the shipped reputation spread of 0. `$ability` also
     widened to `int|float`, because a reference member's figure is an average over sixty
     age-and-position cells and rounding 1.4 to 1 at EN tier 8 would move sigma by a third.

  **Gate:** EN/SC `S_tier` up 3.18-4.66x, `bill % turnover` still 45.0% (check 4 is a definition
  and both sides moved), failed checks 31 -> 37. ⚠️ **The six new findings are checks 1 and 2 at
  EN tiers 4, 7 and 8 — real, not a regression.** `S_tier` stops being monotonic because sigma no
  longer scales smoothly with the band once the generator's `max(1, floor(cap x minFrac))` floor
  bites at small caps; EN 3->4 is 20-40 -> 15-35 while the squad drops 28 -> 26, and EN 6->7->8 is
  10-15 -> 5-10 -> 2-7. EN tier 8 ALREADY failed check 1 before this change. **It is the authored
  band ladder, not the scale** — Tranche D's "ability bands (R1)" decision. Nothing was tuned.

  Tests: three new in `EconomyScaleResolverTest` (an `S_tier` pin whose whole derivation is
  arithmetic in its docblock and is hand-checkable to 37.11 pence against the old 9.78; a
  determinism pin over 40 fresh resolvers with `mt_srand` re-seeded, since the chain now walks a
  service whose every other path draws; and a pin that no member sits at or above its potential
  rung). New `tests/Support/ReferenceSquadDoubles.php`, because fifteen unit tests construct the
  resolver directly and all of them want the shipped pool defaults and a real generator. ⚠️ **Two
  existing assertions were pinning the OLD calibration and were corrected, with the reason written
  into each:** `testTheWageScaleMakesTheReferenceSquadCostItsShareOfTurnover` reconstructed sigma
  from the band (it now reads the resolver's squad, so it asserts the definition rather than the
  implementation), and `testTheWageScaleCarriesSubPennyPrecision` asserted `S_tier < 1000` pence,
  an upper bound that was a proxy for "small" and not the precision the test is named after.
  `AbilityRangeParityTest` did NOT move, contrary to the brief's expectation — it asserts the
  published and pricing bands are one table, which this does not touch.

  **Suite, reconciled rather than summarised (amended 2026-11-19 after review).** The suite is
  **3,297** tests with this change — A7's 3,294 plus the three added here, confirmed by
  `--list-tests`, not inferred. **3,225 executed: 4 failures, 0 errors.** The missing 72 are
  `AdminShellCoverageTest` alone, which is OOM-killed on its own **identically on a clean tree**;
  `3,225 + 72 = 3,297` exactly. Every batch reported exactly its planned count except the one
  that exited 137, whose 20 files were re-run split and recovered all but that file. File
  coverage was checked, not assumed: 386 files on disk, 386 across the batches, none duplicated,
  none missed, no `*Test.php` with zero tests listed. Skipped totals **13** across batches —
  A7's single-run figure to the test, which closes the "batched totals count skips differently"
  hypothesis. Working in `docs/economy-loose-ends/a10-run-2026-11-19/suite-batches/_reconciliation.txt`.

  ⚠️ **A SIXTH KNOWN FAILURE, AND IT IS DETERMINISTIC — not a composition flake.**
  `LegacyNewGameFlowTest::testTheEconomyBlockShipsSwitchedOff` ("the turnover table must be
  unauthored") fails because `EconomyAuthoredSizeDriftTest:156` and
  `EconomyTvCountryScalingGuardTest:113` — **both landed today with A7** — write
  `38_866_153_952` into the SHARED `game_config` row inside their own `seed()` and never restore
  it. Default order is alphabetical and `tests/Command/Economy*` sorts before
  `tests/Controller/Api/Legacy*`, so **it will now fail in every full run.** ⚠️ **It is NOT a
  stale test**, which is the tempting reading given R2 authored the turnover table: `wunderkind_test`
  is not migration-tracked, so R2 never reaches it, and `GameConfig::$economyBaselineTurnoverPence`
  still ships as `[0, ...]` with a docblock saying so. Proved three ways — reader alone OK,
  polluter-then-reader FAILS, reader-then-polluter OK — in
  `a10-run-2026-11-19/legacy-newgame-flake-attribution.txt`. **The fix belongs in the two
  polluters, not in the reader**, whose docblock names the real mistake it exists to catch.

  ⚠️ **Two things for the PM.** `last-sync.md`'s R2-era line *"wageScaleMicroPence is calibrated
  against the tier's authored ability band ... so it is only meaningful inside it"* is superseded
  and was left in place as a historical record. And the full suite is being **OOM-killed** on this
  box (`docker inspect ... OOMKilled: true`, 3.83 GiB VM, exit 137) **identically on a clean
  tree** — verified by stash-and-rerun — so it was run in 20-file batches instead;
  `environment.md`'s known-failures section should say a `137` is an environment result, and
  should carry the sixth failure above.

- **A7: authored-size drift made loud, and the `tv_deal` cache unstuck (2026-11-19)**, committed
  on `development` on top of `52c8b3bf`. **Stages touched:** 04_interfaces (`services.md`),
  03_data (`migrations.md`), shared (this file). `Version20261119150000`, a ninth check in
  `app:economy:wage-table`, plus `LeagueTvDealResyncTest` and `EconomyAuthoredSizeDriftTest`.

  **Part one — the gate was the last reader of a stale cache.** `league.tv_deal` caches
  `tvPoolPence / slots` and `syncTvDeal()` runs on an admin save only, so `Version20261119120000`
  moved `slots` and left the cache on the old divisor. Every publication site already resolved
  `derivedTvDealPence() ?? getTvDeal()`, so no client saw it; the gate read the raw column while
  reading the corrected `sizeOf()`, so **England's TV income read 12.5% low** and its row was
  internally inconsistent (`perClub x size != pool`). ⚠️ **The brief said there was ONE raw read;
  there were TWO** — check 3's `$perClub` (`:460`) and check 8's `$tvPerClub` (`:952`), which is
  paired with a `sizeOf()` read eight lines above it. Both now resolve: fixing only one would have
  left the command in the exact cache-staleness class the change exists to remove it from. The
  migration resyncs the column itself, **only where `tv_pool_pence IS NOT NULL`**.

  **Part two — nothing compared the authored size against reality.** New check 9: resolved size
  against the real NPC rows, per league. ⚠️ **It reuses `Version20261119120000::CORRECT_SQL`'s
  reservation DETECTION rather than assuming a universal `+1`** — the generator leaves a place for
  the player's club at a country's deepest tier, so a correct bottom tier holds `slots - 1` rows
  and a naive `slots == rows` check fires on every bottom tier in the world; and the reservation is
  absent in CA, JP, NG, PL, US and SC, where an unconditional `+1` claims a club the division
  cannot field. **Rows over the size FAILS** (money is provably split too few ways, and no benign
  state produces it); **size over the rows WARNS** (a half-filled tier is the normal mid-bootstrap
  state, and a gate that reddens a correct box is a gate nobody reads). The drift table prints
  either way, because on an agreeing box an empty finding list cannot be told apart from a check
  that never ran. **It writes nothing** — pinned by a test — and `sizeOf()` was not changed to
  prefer the row count.

  ⚠️ **TWO CLONE READINGS THAT MUST NOT BE GENERALISED.** The local clone is far behind the
  deployed box: it holds EN `slots = 18` against 18 NPC rows and SC `slots = 3` against 3, and its
  `tv_deal` already equals `pool / slots` everywhere. So **(a)** check 9 is silent on EN and SC
  here, which says nothing about the deployed box, and **(b)** the migration is a verified no-op
  here, which is why `up()` had to be exercised against a temporarily staled copy. The deployed
  gate movement was measured that way: EN TV per club £10,000,000 -> £11,250,000 (+12.5%, and
  `perClub x 16 = pool` exactly), SC £1,666,666.66 -> £2,142,857.14 (+28.57%, `x 14 = pool`).

  **Latent defect reported, deliberately not fixed:** `LeagueSizeResolver::sizeOf()`'s derive
  branch still adds `+1` at the deepest tier unconditionally. Check 9 surfaces it as an
  under-filled tier instead of being broken by it, and that behaviour is pinned.

- **B3: `slots` corrected where an authored value disagrees with the real size (2026-11-19)**,
  committed on `development` on top of `e2c855c6`. **Stages touched:** 03_data (`migrations.md`),
  shared (this file). `Version20261119120000`, plus `LeagueSlotsCorrectionTest`. Carries the
  owner's decision that **`slots` equals the division's real size**; corrects 12 rows on the
  deployed box (EN tiers 1-8 18->16, SC tiers 1-4 18->14) and does not settle 16-vs-20.

  ⚠️ **THE FINDING, and it changed the implementation: `sizeOf()`'s `+1` at the deepest tier is
  NOT universally right.** The brief's rule was "slots = rows + 1 if bottom tier", taken from the
  resolver. Dry-run on the clone showed that rule would change **6 rows** — CA, JP, NG, PL, US at
  tier 8 (8 -> 9) and **SC tier 8 (3 -> 4)**. Cause: the generator reserves a place for the player
  at the deepest tier on some boxes and not others. EN on this clone reserves (18/18/…/17); those
  six countries hold the same count at every tier, so `rows + 1` is one more club than the
  division can field. **The previous session's refusal to overwrite SC's authored 3 was right for
  a better reason than it knew: 3 is correct and the derived 4 is the bug.** The migration now
  DETECTS the reservation (deepest tier holding exactly one fewer row than the tier above) rather
  than assuming it, and both shapes are pinned.

  **Verified a complete no-op on the clone rather than assumed:** the `slots` column fingerprint
  is `d56d40bd232d2d31ebe7342f47e76b16` across migrate -> rollback -> re-migrate, `rows_changed`
  against the stash is 0, the stash table drops on `down()`, SC tier 8 still reads 3, and
  `app:economy:wage-table` is byte-identical (still exit 1 with its 31 pre-existing failures).

  **Left open and reported, not fixed:** `sizeOf()`'s unconditional `+1` is a latent defect for
  any `slots = 0` league on an unreserved box. Inert today — the only deriving leagues are SC
  tiers 5-8 on the deployed box, which do reserve.

- **B1 + B2: `tvDeal` is published resolved, and a false migration docblock corrected
  (2026-11-19)**, committed on `development` on top of `603ea577`. **Stages touched:**
  04_interfaces (`services.md`), shared (this file). No migration, no authored economy figure,
  `WORLD_PACK_VERSION` unchanged at 11.

  **B1 — a pooled league published its NULL column and the game paid £0 of TV.** `tvDeal` is
  derived whenever `tvPoolPence` is authored, but `syncTvDeal()` runs only on an admin save
  (`LeagueAdminController:95`/`:201`) and `LeagueService:82` deliberately skips it at generation,
  so a generated pooled league keeps a NULL column for ever — the live state of **Scottish tiers
  5–8**, the only pooled leagues in the world. **Four** surfaces published that raw column:
  `LeagueImportExportService::export()`, `/api/initialize/leagues`,
  `SyncService::buildLeagueSnapshot()` and `LeagueService`'s own snapshot. The app reads
  `Math.max(0, league.tvDealPence)`, so those tiers earned nothing for a season. All four now emit
  `derivedTvDealPence($l) ?? $l->getTvDeal()`, which is **inert** without a pool — all 8 EN tiers
  and the other 184 leagues are unchanged. The export gains **`tvDealOverride`** (the raw column)
  and `import()` prefers it, keyed on presence not value, so a resolved share can never be written
  into a column; a pre-change backup still restores through the bare key. Third key in that
  contract, after the payout pair.

  ⚠️ **`app:economy:wage-table` is structurally blind to this fix.** It reads
  `$league->getTvDeal()` off the entity (`EconomyWageTableCommand:459`), not a published surface,
  so its output is byte-identical before and after — **verified by stashing the four source files
  and diffing two full runs.** An unchanged gate is NOT evidence that nothing moved here. ALSO
  RECORDED: on this clone the command **exits 1 with 31 pre-existing check failures** (sponsorship
  band at 13 tiers, the bankruptcy ladder's 0.01-week traversal, rows 4/5 unreachable), identical
  before and after. "Exit 0" is not the bar on this box; "unchanged" is.

  **Tests: 3270, 3 failures — baseline items 1, 2 and 3 exactly** (the three empty-content-table
  failures), with item 4 contributing **zero**, which the amended entry #4 says it does. Four new
  tests, all four **proved meaningful by stashing the source fix and re-running**: they fail with
  the exact messages the defect produces and pass with it.

  **B2 — `Version20261119090000`'s docblock asserted something false about the deployed box**
  (*"where `slots` is 0"*). Only **4 of 200** rows are 0 there, all `SC` tiers 5–8; EN reads 18
  against 16 real club rows on all eight tiers and SC tiers 1–4 read 18 against 14. The claim came
  from a task brief whose premise was wrong. **Comment-only — `git diff` shows no code lines
  changed** and the `only where 0` guard means the behaviour was always right. The 12 disagreeing
  rows are a behaviour change and land as their own migration (B3), not folded into a no-op.

- **A3 + A4 + A3b + A5: four numbers stop being side effects (2026-11-19)**, committed on
  `development` on top of `bac1228b`. **Stages touched:** 01_overview (`environment.md`), 03_data
  (`migrations.md`), 04_interfaces (`services.md`), shared (this file). `app:economy:wage-table EN
  SC` is **byte-identical before and after** (full `diff`, no differences) — no authored economy
  figure moved and `WORLD_PACK_VERSION` is unchanged at 11.

  **A3 — the world export now carries RESOLVED payouts, and the key split is the load-bearing
  part.** `LeagueImportExportService::export()` emits `prizeMoney` / `leaguePositionPot` through
  `LeaguePayoutResolver` (one `GameConfig` fetched for the whole export), because that is the key
  the app's gate reads (`scripts/economy/variantGate.ts`, `dispersionGate.ts`:
  `league?.prizeMoney ?? 0`) — since `Version20261118120000` NULLed the columns, the raw-column
  export read £0 and every gate run needed a hand-patch.

  ⚠️ **The raw columns moved to NEW keys `prizeMoneyOverride` / `leaguePositionPotOverride`, and
  those are the ONLY two `import()` reads.** Writing a resolved figure back would convert every
  inherit (NULL) into a per-league override on the first round trip, freezing the ppm ladder into
  196 rows and defeating `5dc3f51b`'s NULL-means-inherit design silently. The legacy fallback to
  the bare `prizeMoney` key is keyed on the override key's **presence, not its value**: every new
  export carries it even when null, so only a pre-change file can reach the fallback — which is
  what keeps an old backup's genuine overrides restorable while making it impossible for a
  resolved figure to reach a column. `EXPORT_VERSION` stays 1 deliberately, so existing backups
  still import.

  `sponsorPot` was NOT added: `sponsorCount` was already exported and is what the gate uses — the
  device rolls the pot itself (`rollLeagueSponsorPot`). A resolved `sponsorPot` would also be
  asymmetric, since `LeagueSponsor` rows are not in the export at all.

  **A4 — `league.slots` is authored, and only where it was 0.** `Version20261119090000` computes
  each league's currently derived size **in SQL** and writes it wherever `slots = 0`, stashing
  every row for an exact `down()`. ⚠️ **It deliberately does NOT author every league**: an
  already-authored value is a decision, and overwriting it with a live club count is the exact
  side effect the change exists to abolish. That is not hypothetical — **this clone holds SC tier
  8 at an authored 3 while it derives 4**, so "author every league to its derived size" would have
  moved a real division on the day it landed. Computed rather than hardcoded because the derived
  size differs per box (clone EN 18/tier, deployed box 16). **It does not pick a division size**;
  16-vs-20 remains open and unprejudiced.

  ⚠️ **On this clone the migration writes NOTHING** — all 120 league rows were already authored
  (verified: 0 rows differ from the stash) — so the gate diff proves the command still runs, not
  that the authoring is right. That property is pinned instead by new
  `LeagueSlotsAuthoringTest`, which runs the migration's OWN statement off
  `Version20261119090000::AUTHOR_SQL` (migrations are not autoloaded, so it requires the file by
  path; `wunderkind_test` is not migration-tracked, so a migration cannot reach it and a re-typed
  copy would test the copy). It pins the bottom-tier `+1`, that an authored value survives, that
  an empty non-bottom tier stays deriving, and that a club whose country disagrees with its
  league's is not counted — the last mirroring `countsByLeagueForCountry()`'s own country filter.

  **A3b — `bankruptcy_deduction_weeks` is authored at last.** `Version20261119091000` writes the
  agreed five-row weeks-of-wages ladder (1–2/2–4/4–8/8–16/16+ at 3/6/10/20/50 points). ⚠️ **The
  backend mechanism already existed** — column, publisher, admin textarea, setter normalisation
  and gate check 5 all shipped 2026-11-13 in `Version20261113120000`, with the column ending up
  `null`; the open item was only ever the figure, which `economy-threshold-review.md` §1 had
  already specified. Nothing was designed here.

  ⚠️ **Authoring it is NOT inert in the usual sense and that was checked rather than assumed:**
  `resolveWeeksLadder` prefers the published ladder over the app's own constant, so authoring
  flips every client onto this. `wunderkind-app`'s `BANKRUPTCY_WEEKS_LADDER`
  (`src/economy/insolvency.ts`) was read read-only and is **byte-identical** to these rows, so no
  client's behaviour moves. No app edit was made. `bankruptcyDeductionTiers` is untouched and
  remains the ladder for `legacy`, so the gate's two "rows 4 and 5 unreachable" problems and the
  five per-tier "no range left" problems **survive by design** — they measure the pence ladder,
  and amending its published bounds is a value change nobody has authorised.

  **A5 — `WorldOverviewServiceTest` was never a production defect.** The failure was
  `EconomyAdminTest` writing base £100,000,000 with a tier-2 ratio of 2x and tier 8 at 424ppm into
  the **single shared `game_config` row** through the real admin save and never restoring it; SE
  tier 2's NULL column then correctly INHERITED and resolved to £200,000,000. Accounted for to the
  penny. The conversion does not coerce null to zero and this test's own fixture had not drifted —
  so the fix was to make it **own the payout config for its duration** (stash both tier-1 bases,
  zero them, restore), which is precisely the discipline it already applied to the live
  `CountryProfile` statuses. The assertion was NOT relaxed. ⚠️ **`EconomyAdminTest` still does
  not restore what it writes**, so the next reader of that row meets the same trap — flagged, not
  fixed, as it is outside this tranche.

- **Check 8's two TV-dependent bounds amended (2026-11-18)**, no commit — still on `development`
  @ `740f2c8e`. **Stages touched:** 04_interfaces (`services.md`), 07_synthesis
  (`current-focus.md`), shared (this file). `PAYOUT_SHARE_MAX_PCT` 50 → 55 and
  `PAYOUT_SHARE_SPREAD` 4 → 5 in `EconomyWageTableCommand`, on the owner's ruling, with the
  reasoning and the costed alternatives in the constants' docblocks; `CHAMPION_SHARE_MAX_PCT` and
  the flatness tolerance untouched. The by-design failing test was renamed to
  `testR2SitsInsideCheck8sAmendedBoundsAndWouldHaveBreachedTheOldOnes()` and now pins the figures
  against BOTH sets of bounds, so reverting either one fails rather than passing silently.

  ⚠️ **NEW FINDING: `bankruptcyDeductionTiers` has no tier dimension** and its harshest band
  (a flat £40,000.01) is reached in 0.01 weeks at tier 1, where the weekly wage bill is £3,238,043
  — ten gate lines at EN and SC tiers 1–5. The bill is `turnover x 0.45 / 46`, so this depends on
  nothing but the turnover row: it is NOT a local divergence, it will read identically on dev, and
  it already reads worse there before R2. Same shape as the flat youth scholarship and staff
  bands. **Nobody's action yet.**

- **R2 authored (2026-11-18)**, no commit — working tree on `development` @ `740f2c8e`.
  **Stages touched:** 03_data (`migrations.md`), 07_synthesis (`current-focus.md`), shared (this
  file). `Version20261118120000` writes the R2 turnover ladder, both payout ratio tables and their
  tier-1 bases, and band 3 of `npc_facility_level_ranges`; `WORLD_PACK_VERSION` 9 → 10;
  `LeaguePayoutResolverTest` re-pointed off the superseded ladder and
  `EconomyBaselineTurnoverAuthoringTest` added.

  Three findings, all about INPUTS rather than the authoring, are written up in `current-focus.md`:
  the local Lando box diverges from dev in four economy inputs (turnover, squad sizes, TV, sponsor
  bands) so its gate output is not a verdict; `S_tier` depends on `npcSquadConfig`'s midpoints as
  well as the ability bands, which nothing had recorded; and R2 breaches check 8's TV-dependent
  constraint at tier 1 (50.4% against a 50% ceiling, spread 4.34x against 4x) because turnover
  falls 15% at tier 1 while TV deliberately does not move. **Not committed** — the brief makes that
  conditional on `app:economy:wage-table` exiting 0.

- **The coverage dials re-derived; candidate C chosen (2026-11-18)**, no commit — working tree on
  `development` @ `740f2c8e`. **Stages touched:** 07_synthesis (`current-focus.md`), shared (this
  file). **No backend code changed**: the deliverable is an authoring run, specified in
  `docs/economy-loose-ends/candidate-c-rollout.md` (outside both repos), and the derivation plus
  its tooling are in `REMAINING-WORK.md` §2 beside it.

  Three findings that belong here rather than in the app's record, because they are about this
  repo's data:

  1. ⚠️ **`5dc3f51b` takes the app's variant gate from PASS to FAIL on today's other inputs** —
     tier 8 insolvent in season 1, tiers 2 and 3 out of the coverage band. Measured by patching the
     landed ladder into the Nov-15 `world.json`, which is the only place that gate reads league
     money. The fix is right; it needs the facility exclusion beside it.
  2. **Its ppm ladder is the current turnover ladder normalised to tier 1**, so re-authoring
     turnover invalidates it — and `wageScaleMicroPence` is DERIVED from the turnover row, so that
     one moves by itself. Three "pays MORE per unit of ability" warnings on the Economy screen are
     expected under the new ladder (tiers 2, 6, 8).
  3. ⚠️ **`app:economy:wage-table`'s check 1 is the constraint the whole derivation missed, and it
     refuses the candidate.** `S_tier` must fall strictly down the pyramid, it is DERIVED from the
     turnover row, and no turnover ladder satisfies both it and the app gate's 85-110% coverage band
     while `league_ability_ranges` flattens at the bottom (`10-25` → `10-15` → `5-10` → `2-7`).
     Lowering `economyWageCurveExponentBp` cannot close tier 7→8 even at k = 1.0. The checks are
     right — tiers 5/6 overlap at 10-15 and 7/8 at 5-7, so check 2's "a promoted player takes a pay
     cut" would be a real defect — so what gives is either the bottom ability bands (a squad-quality
     change, and it moves staff pay through `TierBandScaleResolver`) or the coverage band at tiers 6
     and 8. **Two invariants, and this repo's gate holds the one the app's does not.**
  4. ⚠️ **`npcFacilityLevelRanges` is keyed by BAND, not tier, and has no country dimension**, so
     the bottom-tier facility set is every 8-tier country's tiers 7–8 at once. It is also not
     published to the app, and the world export disagrees with
     `NpcClubGenerationService::FACILITY_LEVELS`' band-3 defaults — read the admin screen before
     editing it.

- **Scouts get the tier band the staff fix skipped; WORLD_PACK_VERSION 8 -> 9 (2026-11-18)**, on
  top of commit `5157fbfb` (branch `development`). **Stages touched:** 01_overview
  (`tribal-knowledge.md`), 04_interfaces (`services.md`), 07_synthesis (`architecture-notes.md`,
  `current-focus.md`), shared (`api-backwards-compatibility.md`, this file).

  The 2026-11-17 staff fix followed `StaffRole` and so never reached `Scout`, a separate entity
  with its own generator. A fresh tier-8 save still showed £4,308/wk of staff against £443 of
  players; one scout rated 33 accounted for £2,230 of it.

  Three things worth carrying forward:

  1. **"Staff" means every staff type AND scouts** — an owner ruling, now in
     `tribal-knowledge.md`. The code is not shaped that way, so any staff rule has to be applied
     to both entities deliberately.
  2. **In the app, a wrong ABILITY is a wrong WAGE.** `ClubWageScaleService` splits a club's
     wage reservation in proportion to ability and ranks that above the backend's
     `weeklySalary`, so for a club's own staff the backend controls the DISTRIBUTION, not the
     figure. The £2,230 was never a backend number — the backend prices experience 33 at £39.85
     and ships no scout wage in a pack at all.
  3. **Ask for the per-row breakdown before theorising from a total.** Two theories were raised
     and withdrawn (a stale claimed set — impossible, the claim filters on version; a replayed
     app cache — real mechanism, not the cause). One screenshot of the staff list settled it.

- **Staff pay gets a tier dimension; WORLD_PACK_VERSION 7 -> 8 (2026-11-17)**, on top of commit
  `8ff2e5fd` (branch `development`). **Stages touched:** 03_data (`entities.md`,
  `migrations.md`), 04_interfaces (`services.md`), 07_synthesis (`architecture-notes.md`,
  `current-focus.md`), shared (`api-backwards-compatibility.md`, this file).

  Reported from a tier-8 save: staff -£1,000/wk against -£165/wk for the whole squad. Two
  pre-existing causes, neither from the world-pack work — that change only made them visible by
  moving the squad onto the tier band.

  1. **Staff ability was never tier-banded.** `StaffGenerationService::build()` rolled
     `random_int(4, 75)` globally while already RECEIVING `$country` and `$tier` — it just never
     used them for the ability roll. Ability drives `staffMultiplier` (2.0-12.0 on dev), so that
     alone was a 6x swing. Now every per-club caller passes the same band its players came from.
  2. **Legacy staff pay had no tier dimension at all.** The role bands are single absolutes, so
     they can only be right at one tier, while a legacy player's wage scales with a per-tier
     ability. New `Economy\TierBandScaleResolver` scales the band by
     `midpoint(tier) / midpoint(tier 1)` from the already-authored `league_ability_ranges`.

  Five things worth carrying forward:

  1. **`EconomyScaleResolver` cannot be used under `legacy`** — it resolves from authored
     turnover and returns `isResolvable === false` on every deployed box. Anything that needs a
     tier scalar under the model actually running has to use the ability ladder instead, which
     is also the axis players scale on, so the two move together by construction.
  2. **The scaling goes on the BAND, never inside `legacyStaffPence()`.**
     `WageModelLegacyParityTest` pins that method byte-for-byte by passing a base straight in;
     scaling beforehand left it untouched and that file passes with only a constructor argument
     added. Moving it inside is the refactor its docblock forbids.
  3. **It needed a `WORLD_PACK_VERSION` bump (7 -> 8)**, because `buildStaffSnapshot()` bakes
     `weeklySalary` into cached packs — a CONTENT change at an unchanged shape. Every
     `WorldPackSet` became unclaimable at once; `app:worldpack:maintain` restocks unattended.
  4. **The kill switch ships ON**, deliberately against this repo's ship-inert habit: that habit
     is for changes which are untuned, and this one is wrong. It stays a column because the
     figure is cached and therefore has to be reversible.
  5. **A constructor argument on a widely-constructed service costs nine test files.**
     `WageModelResolver` is final and cannot be doubled, so every test builds a real one; adding
     the resolver broke 22 tests across files that had nothing to do with staff pay. Grep for
     `WageModelResolver(` — not `new WageModelResolver(` — before assuming the list is complete,
     because several sites use a leading FQCN or split the call across lines.

  Verified: EN staff average £1,116/wk at tier 1, £240 at tier 4, £18.88 at tier 8; a founder's
  staff total £233/wk against £4,852/wk of players. `app:economy:wage-table` reports **18
  findings both with and without the change** (all pre-existing: unauthored turnover, plus the
  two `bankruptcyDeductionTiers` rows already recorded as open), so it gained no new finding.

- **`.context` audit pass over world pack + starter pack generation (2026-11-17), on top of
  commit `e9d81c2a`** (branch `development`). **Stages touched:** CONTEXT.md, 01_overview
  (`deployment.md`), 02_architecture (`structure.md`), 03_data (`schema.md`, `entities.md`,
  `migrations.md`), 04_interfaces (`routes.md`, `services.md`), 07_synthesis
  (`architecture-notes.md`), shared (this file).

  Asked for explicitly: make `.context` describe exactly how the code behaves, for an agent
  with none of this session's context. The incremental edits made while building the feature had
  left real contradictions, so this was an audit rather than more patching.

  **New: `07_synthesis/output/architecture-notes.md`'s "World pack sets" section** — the single
  end-to-end account (what a set is, the TWO generation paths and why they differ, the claim and
  why it is per-set, the buffer, five traps, and a file map). `CONTEXT.md` gained a routing row
  pointing at it, plus one for wage pricing, because a fresh agent reads the routing table first
  and detail nothing routes to is detail nobody finds.

  **Contradictions found and fixed, all of them introduced by patching rather than by the
  feature:**

  1. `services.md`'s `StarterPackService` entry had TWO bodies — the new "generated, consumes
     nothing" text immediately followed by the original "deleted on consume... raises
     `InsufficientStarterPoolException`" text. A reader would have believed either half.
  2. The same entry then claimed the service "assembles the club via `ClubInitializationService`".
     It uses only the static `countryToNationality()`; `POST /api/club/initialize` assembles clubs.
  3. `services.md` still said "only the STARTER squad consumes pool rows" — true when written,
     false an hour later. It also described NPC scouts as pool-drawn when they are generated.
  4. `routes.md` claimed the regenerate endpoint answers `412` on pool supply, which I had
     written and then made false by deleting that catch; and described the starter fallback as a
     "live draw" when nothing draws.
  5. `entities.md` / `migrations.md` described starter pack pool consumption in the present tense.

  **Pre-existing staleness corrected while in there, unrelated to this feature:**

  - `structure.md` said "Messenger now has SIX transports". There are **eleven**, and it had been
    stale across four separate additions (`friendly`, `suggestion`, `clubsale`, `worldpackset`).
    Now enumerated with a date and an instruction to count them in the yaml instead.
  - It stated a crontab job count of 10; there are **27 lines**. Same treatment — the figure has
    gone stale repeatedly, so the doc now says to `grep -c` it.
  - It implied every transport has a 1-minute cron drainer. There are **nine drainers against
    eleven transports**, and both gaps are deliberate: `failed` is the dead-letter store, and
    **`registration` is drained by a long-running supervisord worker** — the single exception to
    this codebase's cron-driven-command convention, which nothing had written down.
  - `deployment.md` described a `WORLD_PACK_VERSION` bump as making packs stale. It now makes
    every SET unclaimable at once, which is a bigger operational fact; the self-healing path and
    the figure actually worth watching are both named.

  **Every symbol, file, command and routing entry the new text names was verified to exist, and
  everything it says was removed was verified gone** — by a script, not by reading, because a doc
  that names a renamed method is worse than silence. Two apparent failures were my own "REMOVED"
  comments matching the grep.

- **The starter squad is generated, not drawn — and the last two economy gaps closed
  (2026-11-17, same session as the sets entry below)**, on top of commit `e9d81c2a` (branch
  `development`). **Stages touched:** 01_overview (`tribal-knowledge.md`), 03_data
  (`entities.md`, `migrations.md`), 04_interfaces (`services.md`), 07_synthesis
  (`current-focus.md`), shared (`api-backwards-compatibility.md`, this file).

  `StarterPackService::generatePack()` builds a founder's players, staff and scouts through
  `WorldPackClubGenerationService` — the same generator every NPC club uses — and touches no
  pool table. Applied to both the pre-generated sets and the live fallback, so a starter squad
  has one provenance. New `Economy\ServeTimePlayerPricer` owns the "shared-pool rows are priced
  at serve time" rule.

  Four things worth carrying forward:

  1. **A band is a POTENTIAL range, not an ability range**, and the two were being conflated
     across populations. `PlayerGenerationService::generate()`'s fourth parameter is
     `$potentialRange`; `currentAbility` is the mean of attributes drawn under
     `abilityTarget x potential` and lands below the band. The draw that was replaced filtered
     on `currentAbility BETWEEN band`, so a drawn founder had its ABILITY in the band while
     every NPC club around it had its POTENTIAL in it — founders were systematically stronger
     than the division they were placed in. EN tier 8 now measures starter ability 1-3-5 /
     potential 2-7 against NPC ability 1-3-6 / potential 2-7. **A real balance change, and NOT
     gated on the wage model**, because it is a generation basis.
  2. **Two refusals became unreachable, which is the rare direction.**
     `InsufficientStarterPoolException` and `InitializeController::MIN_POOL_SIZE` both went:
     generation has no supply to be short of, so a new game can no longer be refused for a thin
     pool — the one failure in that flow with no client-side escape.
     `WorldPackSet::$starterShortfall` went with them, because a column that can never be
     non-zero is a trap.
  3. **"Price pool rows with country and tier" was asked for and deliberately NOT done that
     way.** A pool row is one row served to every country at every tier, so a stored country
     price is wrong for every country but one and would double up with the serve-time pricing —
     `MarketDataService`'s own docblock already says so. The gaps the request was aiming at were
     real, though, and both are closed: the founder's squad is priced at GENERATION with country
     and tier (it previously had neither and was never re-priced, the one squad in the pyramid
     outside the economy), and `GET /api/players/youth` is now priced at SERVE time (it
     serializes through `PlayerSnapshotSerializer`, so it never reached
     `PlayerBrowseSerializer`'s pricing and served the stored legacy figure). Extracting the
     rule into its own class is what makes "which surfaces price pool rows" answerable — living
     inside a serializer is precisely why it reached only two endpoints. **The owner was given
     this reasoning and confirmed the serve-time mechanism**, so it is recorded as a ruling in
     `01_overview/output/tribal-knowledge.md` rather than left as an implementer's judgement.
  4. **`StarterPackDiagnosticService`, `StarterPoolAuditService`, `app:starter-pack:check` and
     `app:starter-pool:audit` were deliberately NOT deleted**, even though all four turn on a
     starter-pack draw that no longer happens. They are the only SCHEDULED thing that tops the
     shared pool up at all (`app:pool:warm` is the general warmer and is unscheduled), and the
     pool still feeds youth, foreign, scout search and market data — deleting them would have
     quietly starved those four surfaces. Their AIM is what is now wrong: floors and triggers
     need re-deriving from what actually consumes the pool. They over-queue rather than under-,
     which is the safe direction.

  Dead code removed with it: `PlayerRepository::findForWorldInitByPositionAndNationality()` /
  `findForeignForWorldInitByPosition()`, `StaffRepository::findInPoolByRoleRandom()` (zero
  callers, and the names encoded a story that is now false — a note stands in their place), and
  the `StarterPoolFixtureTrait` test helper, because the generation tests now run against an
  EMPTY pool, which is a stronger statement than seeding one.

- **World pack SETS — a world and its starter squad as one single-use unit (2026-11-17), on top
  of commit `e9d81c2a`** (branch `development`). **Stages touched:** 03_data (`entities.md`,
  `schema.md`, `state.md`, `migrations.md`), 04_interfaces (`routes.md`, `services.md`,
  `controllers.md`), 07_synthesis (`current-focus.md`), shared
  (`api-backwards-compatibility.md`, this file).

  `WorldPackSet` + `Version20261117120000`. A world pack is now one starter squad plus a payload
  per league tier, generated together, claimed by exactly one club at
  `POST /api/initialize/starter`, and never served twice; each live country keeps two claimable
  sets, refilled by a top-up dispatched on every game initialisation plus an hourly
  `app:worldpack:maintain` sweep. Fixes both the "same world for everyone in a country" and the
  "warm packs but no drawable squad → 412 at the first step of init" problems at once.

  **Two `.context` entries were found stale and corrected in passing**, both in
  `04_interfaces/output/services.md`: `WORLD_PACK_VERSION` was documented as 3 when it is 7, and
  world-pack club generation was documented as pool-deleting on consumption when it has long
  generated NPC players and staff fresh in memory and never touched the shared pool. Only the
  STARTER squad consumes pool rows — which is exactly the fact this change is built on.

  Five things worth carrying forward:

  1. **`(country, tier)` is no longer a usable key for a payload row**, and the methods that
     used it are deleted rather than deprecated. A country holds a buffer of sets, so
     `findOneBy(['country','tier'])` returns an arbitrary world and the damage is silent — a
     client served tier 1 of one world and tier 2 of another, every response a 200.
  2. **A widened unique key can silently drop the guarantee it looks like it keeps.**
     `(run_id, set_id, tier)` does not constrain set-less rows at all, because Postgres treats
     NULLs as DISTINCT; it needed a partial companion on `(run_id, tier) WHERE set_id IS NULL`.
     An existing schema test caught it, which is the only reason it did not ship.
  3. **A guard can be correct and then become wrong by staying still.** The global
     `MIN_POOL_SIZE` check ran before the claim; pre-generating a set CONSUMES its squad from
     the pool, so a healthy box sits with a nearly empty pool and a full buffer — and the guard
     refused to serve those sets, advising the operator to warm a pool the sets had used. It is
     now on the live-draw path only.
  4. **`gen_random_uuid()` in a backfill is a latent bug in this repo.** Every id here is typed
     `UuidV7` and a v4 cannot hydrate into one. This migration mints UUIDs in PHP;
     `Version20261027230000` backfills `competition_entrant_squad.id` with `gen_random_uuid()`
     and is an UNFIXED instance of the pattern. **Checked on dev 2026-11-17: 0 rows in that
     table, so nothing is affected there and the defect is latent rather than live.** Production
     is not checked from here — run
     `SELECT count(*) FROM competition_entrant_squad WHERE id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4'`
     against it; a non-zero result means those rows throw a TypeError on read.
  5. **`wunderkind_test` had never received four `game_config` economy columns** from
     `Version20261116181000`, so every test reading `GameConfig` was failing with
     `column ... does not exist`. Added by hand with the ENTITY defaults (0 / parity ppm), not
     the migration's authored ladder — authoring them turns the economy on and breaks
     `WorldOverviewServiceTest`. The database also held 15,101 leftover clubs, 98 of them
     referencing leagues, which was failing `LeagueImportExportRoundTripTest` and
     `SeasonInvestmentControllerTest` on a `club → league` FK; cleared.

  **`down()` is deliberately not lossless** — `uq_country_tier` cannot coexist with a buffer, so
  the rollback keeps each country's oldest set and drops the rest. That is the rollback, not a
  side effect.

- **The league payout ladders authored relative to the top tier (2026-11-16), on top of commit
  `d06ebd9c`** (branch `development`). **Stages touched:** 03_data (`entities.md`,
  `migrations.md`), 04_interfaces (`services.md`), shared (this file).

  Acts on the finding the entry below recorded and left as an authoring decision.
  `LeaguePayoutResolver` + two migrations + gate check 8 + `WORLD_PACK_VERSION` 6 → 7. Both
  ladders now sit at a flat **0.2573× of each tier's own authored turnover** (tier 1's existing
  ratio), so EN tier 8 goes £5,000,000 → £42,400 and its division's total payout 5.42× → 0.12× of
  combined authored turnover. The `entities.md` warning is marked resolved in place rather than
  deleted, with the original finding kept under it.

  Three things worth carrying forward:

  - **Parts per million, not basis points.** Tier 8 is 4.24 bp, where one integer step is 23.6%
    of the figure. The entity now carries `GameConfig::TIER_RATIO_PPM_PARITY` beside the bp
    tables, and the admin grid labels the unit per column because the two now sit side by side.
  - **The sibling resolvers' single-`intdiv` chain overflows at this unit** and the obvious fix
    (pre-combining the dimensionless factors) is wrong by 0.94% at tier 8. See `services.md`.
    Caught by a test asserting a 20% country resolves to exactly one fifth — the kind of defect
    that reads as a rounding quirk.
  - **NULL now means inherit** on `league.prize_money` / `league.league_position_pot`, and the
    tier-defaults row's own prize/pot cells were cleared too, because that row is what
    `generateLeaguesForCountry()` writes onto a new league — leave it populated and the next
    country added silently opts out of the ladder.

  **Environmental, not ours:** `wunderkind_test` is ~64 schema statements behind this branch, so
  a full-suite run reports 873 errors BEFORE this change (3181 tests) and 877 after (3207). The
  four extra are three new `EconomyAdminTest` cases in a class whose 23 existing tests already
  error on a kernel-boot problem here, plus one order-dependent flake that passes in isolation.
  `tests/Controller/Admin` + `tests/Service` is at exact parity: 527 errors / 88 failures both
  ways, with zero tests newly broken.

- **Economy findings from the app's income-truth batch (2026-11-16), on top of commit
  `5caa68d1`** (branch `development`). **Stages touched:** 03_data (`entities.md`),
  04_interfaces (`services.md`, `routes.md`), shared (this file).

  **No backend code changed.** These are facts about backend-AUTHORED data and the published
  config contract, found while correcting three income defects in the app
  (`economy-income-truth`, app repo @ `4d75a15e`). Recorded here because each one is either a
  backend authoring decision or a contract the client now depends on more heavily than before.

  - **`League::$prizeMoney` / `$leaguePositionPot` look pool-sized but are consumed per club.**
    `tvDeal` has `tvPoolPence` beside it and `pool / slots === tvDeal` holds on all eight EN
    tiers; these two have no such pair and no divisor anywhere in the app. Against authored
    turnover the EN ladder **inverts at tier 3** — a tier-8 champion receives **£5,000,000
    against £164,852 (30.3×)**, where tier 1 is 0.26×. Nothing rescales it. **An authoring
    decision, named and left**; the app's own forecast bug (it paid the flat prize to every
    club) is fixed separately.

  - **`wageModel` is three switches, not one.** The app derives both `incomeAnchoredWages` and
    `ticketPriceElasticity` from `baseline?.wageModel === 'turnover'` when no explicit key is
    published — and neither IS published, so both resolve TRUE on the live export. The app had
    recorded the opposite and read its release gate's two arms the wrong way round for a while.
    Worth knowing before anyone publishes a standalone key for either.

  - **`tiers[].ticketPricePence` now drives shirt income too.** The app replaced a flat £45
    shirt price (compiled in, every division in the world) with `ticketPrice × 0.5625`. So
    re-authoring a division's ticket price moves two revenue lines now. Tier 1 is unchanged by
    construction; tier 8 goes £45 → £7.88. Nothing is published for shirts.

  - **`fanBaseRanges` and `leagueAbilityRanges` are keyed differently on the same payload** —
    the first by tier alone, the second by country then tier. A `[country][tier]` read of the
    first type-checks, misses every time and returns a plausible zero.

  - **`wageScaleMicroPence` is calibrated against the tier's authored ability band**
    (`referenceSquadAbilitySum`, EN tier 8 spans **2–7**), so it is only meaningful inside it.
    With `k = 2.5` an ability-20 reading extrapolates ~115× past an ability-3 one. The app's
    debug table samples a fixed `[10,20,40,60,80,95]` ladder at every tier and so shows £3,200
    for a tier-8 "ability 20". **That is an app display fix, not an authoring error** — recorded
    so nobody re-authors the scale to make that table look sane and breaks every real squad.

- **Backlog review (2026-11-15), on top of commit `c057106`** (uncommitted, branch
  `development`). **Stages touched:** 04_interfaces (`routes.md`, `controllers.md`),
  06_documentation (`index.md`), shared (this file, `token-savings.md`).

  Ran the staleness check the session should have opened with: `a93bb1d..HEAD` was **10
  commits**, and reviewing them found **exactly one real gap**. The 2026-11-14 club-buyout pass
  had documented itself properly — `ClubSale` appears across five stage files — so eight of the
  ten were already covered.

  The gap was **`packVersion`, which had zero coverage anywhere in `.context/`** despite
  landing on two API payloads and an admin screen. Now in `routes.md` (both
  `/api/initialize` surfaces, including the point that the tier pack's version and `/leagues`'
  version are DIFFERENT FACTS that legitimately disagree mid-regeneration), in `controllers.md`
  (the world-pack cache screen showing `payload_version`, and the starter-pack check's
  LIVE-countries filter), and in the docs index.

  Also recorded in the docs index: **`wageModel` is the only economy switch** — income
  anchoring and elasticity derive from it rather than carrying flags of their own, so a client
  gating on an `incomeAnchoredWages` or `ticketPriceElasticity` key backend-side is gating on
  nothing.

- **New pass (2026-11-15), on top of commit `c057106`** (uncommitted, branch `development`).
  **Stages touched:** 03_data (`entities.md`, `migrations.md`), 04_interfaces (`services.md`,
  `routes.md`, `controllers.md`), shared (this file, `api-backwards-compatibility.md`,
  `token-savings.md`, `usage-savings-log.md`).
  **Code:** steps 1-2 of the economy loose-ends pass. Sponsor bands and ticket prices expressed
  as a tier-1 base plus a ratio table (`economySponsorTierRatioBp`,
  `economyTicketPriceTierRatioBp`, `economyTicketPriceTier1Pence`, `Version20261115190000`), a
  new `TicketPriceResolver`, and the `app:economy:wage-table` gate's two country-independent
  checks moved out of the per-country loop.

  Three things worth carrying beyond the feature:

  - **The explicit per-tier tables and the ratio tables are both live, and the explicit one is
    the override.** Not a replacement: 48 authored sponsor figures per country is a ladder
    nobody can retune in one place, but a hand-authored division must not move when a ratio is
    retuned either. `SponsorBandResolver::bandFor()` reads explicit → ratio → flat, country
    factor once and last, one `intdiv` per figure.
  - **A gate whose finding count moves with its arguments is not a gate.** Two
    country-independent emitters ran inside the per-country loop, so `wage-table EN SC`
    double-reported every deduction-ladder defect and nothing on screen explained the jump from
    10 findings to 13. Pinned now by COUNTING occurrences, not by looking for the string.
  - **`0` had to keep meaning "unauthored" through a whole new resolver.** There is no flat
    ticket-price column to fall back to, so a published 0 is the only way to say "use your own
    figure" — which is why `TicketPriceResolver` propagates zero from every input that means
    off, and floors a real price at 1p so a very cheap division cannot truncate into reading as
    an unauthored one.

  **Staleness check run this pass:** `a93bb1d..HEAD` was **10 commits** — the admin club buyout
  UI, the world-pack version work and the starter-pack check — none reviewed into `.context/`
  before this pass began. The 2026-11-14 entry below was written against `a93bb1d` and those ten
  landed after it.

- **New pass (2026-11-14), on top of commit `a93bb1d`** (uncommitted).
  **Stages touched:** 02_architecture (`structure.md`), 03_data (`entities.md`,
  `migrations.md`), 04_interfaces (`routes.md`, `services.md`), shared (this file).
  **Code:** the admin club buyout — an operator picks a user's club, is shown a generous
  valuation, adjusts it, and authorises a sale that credits the owner's `UserLedger` and then
  permanently deletes the club and all of its history. Queued from day one so the go-live
  batch is the same handler. New: `src/Service/Club/`, `ClubSale` + `club_sale`,
  `UserLedgerEntryType::CLUB_SALE`, the `clubsale` Messenger transport,
  `app:clubs:sell-batch`, and the `Est. Value` column + overlay on `/admin/user/{id}`.

  Four things worth carrying beyond the feature:

  - **Most of the money half already existed and was unwired.** `ClubValuationService`,
    `PlotSellBackService`, `ClaimReleaseReason::CLUB_DELETED` (already `paysOut()`), and
    `PlotClaimRepository::releaseActiveForClubs()` — the last of which documents itself as
    "so the caller can pay out" and had **no production caller at all**. Reading the land
    economy's own services first turned a from-scratch feature into a wiring job plus a
    valuation model. The stale forward-reference to a never-written
    `detachClubScopedLedgerEntries()` in `AccountDeletionService`'s docblock was the clue
    that a club-only delete path had been anticipated and never built.
  - **A club-only delete is a different job from an account delete, because the owner
    survives.** That one difference is the whole of `ClubScrubService`: `user_ledger` rows
    must be DETACHED not deleted (the balance is a before/after chain, and deleting a club's
    recent rows rolls the owner's balance backwards — already shipped once), and an active
    `plot_claim` must be RELEASED first or `uq_plot_claim_active_plot` leaves the plot owned
    by nobody and permanently unbuyable. The same latent bug was live in
    `DeleteAdminController`'s club delete, now pointed at the shared service.
  - **Order the two irreversible steps so the failure you can have is the recoverable one.**
    Credit then scrub, not in one transaction: a crash between them leaves a paid player who
    still has their club, and the retry pays nothing further (idempotent on the sale id). The
    reverse order destroys the club and everything a payout could be recomputed from. Same
    reasoning `PlotSellBackService::execute()` already documents.
  - **`$user->getClubs()` can hand back an empty collection that never lazy-loads**, because
    the inverse side is not synced when a `Club` is constructed. It made the new admin column
    render "No clubs associated" under test. `AccountDeletionService` already avoids that
    collection for the same reason; `UserCrudController::detail()` now queries too. Worth
    assuming for any screen that lists a user's clubs.

- **New pass (2026-11-12), on top of commit `3ecbc31`** (uncommitted).
  **Stages touched:** 02_architecture (`structure.md`), 03_data (`entities.md`,
  `migrations.md`), 04_interfaces (`routes.md`, `services.md`), shared (this file).
  **Code:** player and staff name pools move from a 2,515-line PHP constant to two
  `country_profile` json columns, authored and AI-topped-up from the locations dashboard.
  Also catches up the 2026-11-11 club-naming pass, which wrote three stages in `864a775`
  without recording an entry here.

  Four things worth carrying beyond the feature:

  - **Lift the giant constant out FIRST, as its own no-behaviour-change commit.** Moving
    2,415 lines into `NamePoolRegistry` with `getNamePools()` left as a one-line delegate
    touched no tests and changed nothing, which decoupled the unreviewable diff from every
    design decision after it. The commit that then changed `NameGeneratorService`'s shape
    broke exactly two test files and zero src files — the predicted blast radius, and the
    measure of whether the design held.
  - **"The identity map already caches this" was a false precedent, and copying it would have
    been a performance incident.** `CountryNamingContentProvider` deliberately has no memo on
    that reasoning. Inside the name path it does not hold: `MarketPoolService` calls
    `$em->clear()` every fifty players — ORM 3's `clear()` is `clear(): void`, so the
    `Player::class` argument is silently discarded and it is a FULL identity-map clear — and
    a 71-country warm reads the pools ~116,000 times. Naive, that is ~116,000 SELECTs.
    `NamePoolProviderTest` pins it to ≤71 with `expects($this->once())`, so the decision is a
    contract rather than a comment. *(The discarded `clear()` argument is a separate latent
    bug from the ORM 3 upgrade, still unticketed.)*
  - **Do not normalise people's names.** The plan said to dedupe through
    `ClubNameNormalizer::normalize()`; it runs `NFD; [:Nonspacing Mark:] Remove; NFC`, so it
    would have collided `Güneş`/`Gunes`, `Müller`/`Muller` and `Łukasz`/`Lukasz`.
    Accent-insensitive collision is correct for club names and silently destructive here. The
    dedupe is `mb_strtolower(trim())` and the validator is deliberately separate from
    `NamingContentValidator` rather than a relaxed version of it.
  - **A duplicate array key costs no syntax error and no test failure.** `'South Korean'` was
    declared twice in the pool literal; PHP keeps the last, so an 88-line block was
    unreachable and anyone editing it changed nothing. Nothing going through PHP can
    resurrect it — only a text-parsing extractor could, so any export must be built from
    `NamePoolRegistry::all()`. The coverage test now pins the key count and asserts every key
    is a demonym some `Country` case carries.

- **New pass (2026-11-02), on top of commit `fde3c60`** (uncommitted).
  **Stages touched:** 03_data (`entities.md`), 04_interfaces (`routes.md`),
  06_documentation (`index.md`), shared (this file).
  **Code:** the `tutorial` block on `GET /api/starter-config` is implemented — column,
  migration, admin editor, validator, CLI check, 26 tests.

  Three things worth carrying beyond the feature:

  - **A DB-level default on a new NOT NULL column is drift, but you still need it for one
    statement.** `starter_config` has exactly one row, so `ADD ... JSON NOT NULL` with no
    default fails outright; the default populates that row and then has to be DROPPED in the
    same migration, because every other JSON column here has none and leaving it makes the
    next `migrations:diff` emit `ALTER ... DROP DEFAULT`. The first draft left it in and did
    exactly that — caught by re-running `diff` after applying, which is the check worth
    making a habit.
  - **A validator exists here only because the consumer reports nothing.** The app's
    `parseTutorial` cannot throw (setup is the one route a new account cannot skip), so it
    drops bad items in silence. `SetupTutorialValidator` mirrors its rules so an admin sees
    them. It is deliberately NOT in `deploy-dev.yml`: it exits 1, the workflow runs under
    `set -e`, and an admin saving a bad payload breaking the next deploy of everything else
    is a worse failure with a less obvious cause.
  - **The 34 `ngs.tut.*` strings were deliberately NOT seeded from a PHP array.** The app's
    `locales/en/translations.json` is their single source and `/admin/translations/import`
    already ingests it; re-typing them here would give the same copy two homes. The check
    command names the missing rows and points at that route.

- **New pass (2026-11-02), on top of commit `e12738d`** (uncommitted).
  **Stages touched:** 06_documentation (`index.md`), shared (this file).
  **No code change** — one doc registered, plus a correction to the entry below.

  Registered `docs/api/setup-tutorial.md`, the work brief for the optional `tutorial`
  block on `GET /api/starter-config`. It is listed under **New since the last
  human-confirmed pass** with two explicit exceptions attached, because it breaks the
  rule the pass below had just established: it is a work log living in `docs/api/`,
  which is the genre that pass deleted `tournament-sealed-results-backend-work.md` for.
  It is there because a backend session has to find it, and it is **prunable the moment
  it is actioned** — the entry and the file's own header both say so.

  Two things worth carrying forward from registering it:

  - **The prune's own criterion has a blind spot for work that has not started.**
    Inbound references decided what survived, which is right for a contract and wrong
    for a brief: a brief for unimplemented work cannot be cited from source, because
    the source does not exist yet. So the genre needs an explicit expiry instead of a
    reference count, which is what the entry gives it.
  - **A doc that is not in this index is invisible to the staleness check**, which is
    how `05_ui`-equivalent drift happens in the app repo. The brief sat unregistered
    across two commits (`e12738d`, and the `dev` merge `d1a552e`) before this.

  Also corrected the entry below: it still read "(uncommitted)" after being committed
  as `b1c7da0`, which would have had the next session hunt for uncommitted work that
  was already in history.

- **Pass (2026-11-02), on top of commit 448a160** — since committed as `b1c7da0`.
  **Stages touched:** 06_documentation (`index.md`), 03_data (`migrations.md`),
  04_interfaces (`routes.md`, `services.md`, `push-notifications.md`), shared
  (`stack.md`, this file). **No code change** — a prune of `docs/`, 1.4MB to 408KB.
  What went and why is in `06_documentation/output/index.md` under **Pruned
  (2026-11-02)**; the shape of the decision is worth keeping here:

  - What survived was decided by *inbound references*, not by age. Source docblocks
    cite 20 `docs/api/*.md` files by name, so `docs/api/` is load-bearing and stayed
    whole (bar one work log misfiled there). Three `docs/superpowers/` files stayed
    for the same reason — notably
    `plans/2026-10-28-tournament-concurrency-and-queues.md`, whose §3.4 is cited from
    `CompetitionDrawService`, `CompetitionResultsService` and
    `CompetitionBenchmarkCommand`. The other 23 had zero.
  - **A dated plan left in the tree after its feature ships turns into a lie.**
    `docs/2026-10-28-periodic-leaderboard-awards-plan.md` still read "planned, not
    started" while `LeaderboardAwardDefinition`, `LeaderboardAwardDeliveryService`,
    the period-archive entities and `docs/api/leaderboard-awards.md` were all in
    place. That is the one category worth deleting on sight rather than archiving.
  - Pointers in this tree into one-off episode docs were **replaced by the substance,
    not by a link** — `routes.md`'s retry table and `migrations.md`'s retry-safety
    section now stand alone, because the 2026-10-24 Sentry sweep's conclusions were
    already recorded in this file's 2026-10-24 entries. Check that before deleting a
    doc something here points at.
  - `.DS_Store` was never in `.gitignore` and `docs/.DS_Store` was **tracked**; both
    fixed. Five `docs/...` paths that actually live in the app repo are now written
    `wunderkind-app/docs/...`, so a bare `docs/` path in this tree means this repo.

- **New pass (2026-11-01), on top of commit 5333732** (uncommitted).
  **Stages touched:** 01_overview (`tribal-knowledge.md`), 04_interfaces (`controllers.md`),
  07_synthesis (`current-focus.md`). Second pass over the `ROLE_CLUB` lockout (commit
  `5333732`, recorded in the entry below), adding the *reasoning* the first pass only
  recorded as facts. `tribal-knowledge.md` gains a Q4 entry: a stored role column is not
  where a role comes from, written up there because the natural assumption is the opposite
  one and both obvious things to do with `user.roles` — backfilling it, or adding a case and
  expecting a route to honour it — are now wrong, the latter because there is **no
  `role_hierarchy` at all**. Same entry records the two traps that look like one-line fixes
  (a role gate lives in both `#[IsGranted]` and `access_control`, so relaxing one changes
  nothing; `ClubMismatchException` is a 403 on purpose and wrong on exactly one endpoint) and
  the generalisable rule — an endpoint whose purpose is to answer about absence must not be
  gated on the thing being absent.
  **Also corrected a wrong instruction:** the root pointer's "EasyAdmin admin grant" gotcha
  said to `UPDATE "user" SET roles = '["ROLE_ADMIN"]'`. The `admin` firewall's provider is
  `app_admin_provider` (the `Admin` entity), so that cannot grant `/admin` access at all, and
  before this week's fix it silently removed the account's game access. Fixed in `CLAUDE.md`
  **and** recorded in `tribal-knowledge.md`, because the claim lived only in the disposable
  pointer and a regeneration could otherwise bring it back.
  Counts in `current-focus.md` were measured, not estimated (14 `access_control` rules, 30
  `#[IsGranted('ROLE_CLUB')]` attributes, 2026-11-01) — an earlier draft guessed "roughly 25"
  and was wrong.

- **New pass (2026-11-01), on top of commit 75ae1b0** (uncommitted).
  **Stages touched:** 03_data (`entities.md`), 04_interfaces (`routes.md`), 06_documentation
  (`index.md`). Answered `wunderkind-app/docs/backend-notes/2026-11-01-role-club-403.md`:
  an authenticated account 403'd from every `ROLE_CLUB` endpoint. Two independent causes, both
  fixed. (1) `ROLE_CLUB` was read out of `user.roles`, a column that has twice held a value
  granting nothing — `[]` before `6dc85ab`, the since-deleted `ROLE_ACADEMY` until `2a9e6cd` —
  with no `role_hierarchy` to save it; `User::getRoles()` now guarantees the role the way
  `Admin::getRoles()` hardcodes `ROLE_ADMIN`, so no backfill is needed and existing tokens start
  working (roles resolve from the user provider per request, verified in
  `JWTAuthenticator::loadUser()`). (2) `GET /api/club/check` is now authentication-only — its
  own 404 was the answer for an account with no club and `ROLE_CLUB` made that branch
  unreachable for exactly those callers. **Both of that endpoint's gates had to move** (the
  `#[IsGranted]` and `access_control`'s `^/api/club/` rule, which also matches it); either alone
  changes nothing — **and `/check` now catches `ClubMismatchException` too**, since that is an
  `AccessDeniedHttpException` and so a stale `X-Club-Id` was the second door to the same
  unreadable 403 (the app's own report describes the half-finished club creation that leaves
  one behind). Regressions reproduced before fixing and guarded by
  `tests/Entity/UserRolesTest.php` + `tests/Controller/Api/ClubCheckAccessTest.php`. The one
  open question — how that specific guest account came to store the legacy shape, since guest
  registration postdates both eras — needs a deployed-DB query, which is in
  `docs/api/role-club-403.md` rather than answered here.

- **New pass (2026-10-24), on top of commit 265c3b0** (uncommitted).
  **Stages touched:** 05_ui (`design-system.md`). Added a live UTC server clock under the
  admin sidebar's "Build My Club" title, via a new `header_logo` override in
  `templates/bundles/EasyAdminBundle/layout.html.twig` plus a "Server clock (sidebar)"
  section in `public/admin-theme.css`. Server-rendered first paint + skew-corrected JS
  ticker pinned to UTC. Guarded by `tests/Controller/Admin/SidebarServerClockTest.php`,
  which renders the real EasyAdmin layout — the override restates EasyAdmin's anchor
  verbatim, so an upstream markup change could otherwise desync it silently.

- **New pass (2026-10-24), on top of commit 0bc0832** (uncommitted).
  **Stages touched:** 04_interfaces (`services.md`). Closed the one open ask in
  `wunderkind-app/docs/api/match-detail-club-fields.md` §4a: an unplayed cup fixture or
  accepted friendly now returns both clubs' registered snapshot XIs as
  `homeLineup`/`awayLineup` rather than empty arrays, via a new
  `CompetitionRegistrationService::formatSnapshotXiForClient()` projection onto the played-
  lineup key set (counters `0`, `rating` null). Showing **both** XIs — rather than only the
  viewer's, or neither until the XI locks — was an explicit product decision by the human,
  taken knowing the resubmission window stays open after the draw, so each club can change
  the XI that actually plays after seeing the other's.

- **New pass (2026-10-24), on top of commit 883f731** (uncommitted).
  **Stages touched:** 03_data (`entities.md`, `migrations.md`), 04_interfaces
  (`services.md`, `routes.md`). Backend half of the production Sentry sweep
  (the brief/answers/reply docs are gone; what they concluded is this entry). Every item exists because the
  client gained a retry queue and started treating an empty/truncated 2xx as retryable,
  so requests that were never safe to retry now are:
  1. `ApiExceptionSubscriber` — every `/api/*` error is `application/problem+json` with
     `violations[]`, regardless of `Accept` (it was Symfony's HTML error page).
  2. `LeagueService::concludeSeason()` idempotent per club+season — `UNIQUE(club, season)`
     on both archive tables, first response stored on `SeasonSnapshot::$responseJson` and
     replayed, `currentSeason` advanced only for the current season; new optional
     `ConcludeSeasonRequest::$season`.
  3. `POST /api/initialize/starter` replays `Club::$starterPackJson` instead of 409 (the
     pack was previously unrecoverable once the response was lost).
  4. `WorldPackCacheService::getOrBuild()` serializes a cache miss behind a Postgres
     advisory lock and re-checks under it.
  5. `IdempotencySubscriber` allowlist adds `/api/club/initialize` and
     `/api/league/conclude-season`.
  6. `OwnerAvatarRequest::$dob` accepts an ISO-8601 datetime, and `""` clears rather than
     meaning today.
  **Round two, after the app side replied** (same pass):
  the client's outbox *drops* a 4xx rather than retrying it, which reversed two
  decisions — `/api/league/conclude-season` was **removed** from the
  `IdempotencySubscriber` allowlist (its body-hash check can only false-positive now
  that club+season is authoritative, and a spurious 422 would permanently lose a
  season), and the "season the club has not reached" 422 was **removed** in favour of
  `currentSeason = max(current, season + 1)` (a 422 there cascaded into every season
  queued behind it); `ConcludeSeasonRequest::$season` gained a `<= 1000` bound so an
  out-of-range SMALLINT is a 422 rather than a retried-forever 500;
  `/api/initialize/starter` stays permissive on `enabledCountries` **by design** —
  `club.country` is persisted per save, so a check there would brick every existing
  save in a country later removed from the list; `/api/initialize/league/{tier}` logs
  `initialize.league_tier.served` (country/tier/counts/bytes) so the client's
  country-less `db: slow write` warnings can be placed. Also **bumped
  `WORLD_PACK_VERSION` 2 → 3**: it was missed when the trophy-sprite work landed, so 84
  of 85 cached world packs were still serving the retired `trophyImage`/`trophyColour`
  shape instead of `trophy` — needs `app:worldpack:warm <CODE> --force` at deploy.

  Two unrelated bugs surfaced by running the suite rather than reading it:
  `CompetitionSpoofEntrantService::generateSpoofEntrantsForCompetition()` started
  `$usedNames` empty, so `spoofAllEntrants()`'s separately-generated bootstrap club name
  (and any real entrant's name) was never excluded — a bracket could hold two clubs with
  the same name; it now seeds from the competition's existing entrants. And
  `PlayerGenerationServiceTest::testGoalkeeperReceivesHeightBias` compared two 50-sample
  means ~2.3 standard errors apart (≈1% failure rate) — now 400 a side, asserting the
  magnitude rather than just the direction.

  **Unactioned findings** (deliberately left for the human — production data/ops
  decisions, see the answers doc): `StarterConfig.npcSquadConfig.coachCount` is 7–13 per
  club against a code default of 1, inflating NPC staff 7–13×; `app:worldpack:warm` is not
  in the deploy sequence and JP/US/NG/CA have only tiers 1–2 cached; JP is not in
  `enabledCountries` yet clients are initializing into it.

- **New pass (2026-10-24), on top of commit 68331a5** (uncommitted).
  **Stages touched:** 03_data (`entities.md`, `migrations.md`), 04_interfaces (`services.md`).
  1. Aligned the competition & friendly snapshot pipeline with `wunderkind-app`'s `TournamentSnapshotBuilder.ts` (`wunderkind-app/docs/api/competition-registration-snapshot.md`) and `wunderkind-app/docs/api/match-detail-club-fields.md`: added `CompetitionRegistrationService::normalizeSnapshotClub()`, `normalizeClubData()`, and `formatLineupForClient()`, preserved `players[].appearance` in `DeterministicEngine::calculatePerformance()`, explicitly validated `playingStyle` against `PlayingStyle`, omitted `stadiumName` and `playingStyle` (rather than emitting `null`) when unset, merged entrant `snapshotJson['club']` + `Club` entity fallbacks under frozen `homeClubJson`/`awayClubJson` on completed matches, and returned `identity`, `stadium`, `stadiumName`, `playingStyle`, `reputation`, `tier`, `badgeShape`, kit/badge configs, and lineup `appearance` across all competition, match, and friendly endpoints.
  2. Added engine-agnostic match derivation & computation auditing (`MatchEngineResult::$computationAudit`, `CompetitionResult::$computationAuditJson`, migration `Version20261024012500`): `DeterministicEngine` records the full step-by-step computation breakdown (`dominance`, `regulation`, `extraTime`, `penaltyShootout`, `goalDistribution`, `cardDistribution`, `playerRatings`, `outcome`) without altering the RNG sequence, `AiEngineStub` records delegation/fallback metadata, `CompetitionResultsService::buildComputationAudit()` guarantees an audit envelope regardless of engine and stamps round/winner/tiebreak context, and `templates/admin/competition/active_competition_detail.html.twig` renders the audit table and raw JSON in the admin detail view while keeping it out of client payloads.

- **New pass (2026-10-23), on top of commit 68331a5** (uncommitted).
  **Stages touched:** 03_data (`entities.md`, `migrations.md`), 04_interfaces (`routes.md`, `controllers.md`). Implemented the 7-milestone Tournament / Competition API overhaul & Friendlies Challenge Board (`docs/superpowers/plans/2026-10-22-tournament-and-friendlies-api.md`):
  1. Enriched competition club identity payloads (`clubId`, `name`, `abbreviation`, `ovr`, `reputation`, `reputationTier`, `homeKitConfig`, `awayKitConfig`, `badgeConfig`), fixed `CompetitionSpoofEntrantService` to emit valid `BadgeShape` cases (`round`/`shield`, not `circle`/`badge`), and added `viewer` block + `serverTime` across competition reads.
  2. One-off `entryFeePence` atomic deduction on `POST /api/competitions/{id}/register` with `insufficient_funds` eligibility check, `POST /api/competitions/{id}/withdraw` (`CompetitionEntrantStatus::WITHDRAWN` excluded from `registeredCount`, refunds `entryFeePence`), and automatic `entryFeePence` refund on competition cancellation.
  3. Full bracket rounds (`rounds[]` always contains `log2(entrantCapacity)` rounds with `TBD` placeholders, `bracketSize`, `byeCount: 0`, `champion`, `ETag` 304 support) and first-class Match & H2H endpoints (`MatchQueryService`, `MatchController`: `GET /api/matches/{matchId}`, `GET /api/club/matches?state=upcoming|recent`, `GET /api/club/h2h/{opponentClubId}`).
  4. Required `X-Club-Id` (`ClubResolver::resolveRequiredFromRequest()`, `400 club_id_required`) on competition/friendly writes, stale `snapshotLockedAt` check (`409 round_already_drawn`) on resubmit, and 24h `Idempotency-Key` support (`IdempotencyRecord`, `IdempotencySubscriber`).
  5. Hardened `/api/inbox` (`MessageStatus::DISMISSED`, `POST /api/inbox/{id}/dismiss`, `type`/`link`/`requiresAccept` fields, single combined `COMPETITION_WON` reward message in `RewardApplierService`, `409 already_processed` on repeat accept, and persistent inbox notifications on tournament lock, draw, and match resolution).
  6. Friendlies Challenge Board (`FriendlyChallenge`, `FriendlyChallengeService`, `FriendlyController`, `DeterministicEngine::resolveFriendly()`, `app:friendly:resolve-matches`, `app:friendly:expire-challenges`) with reputation-tier + stake filtering, home/away venue selection, stake escrow/payout, and migration `Version20261023133500`.
  7. Added `GET /api/competitions`, `GET /api/competitions/champions`, `GET /api/club/competitions`, and `GET /api/club/hub` (`ClubHubController`).

- **Doc-only pass (2026-10-21), on top of commit 68331a5** (uncommitted).
  **Stages touched:** 01_overview, 02_architecture, 04_interfaces, 07_synthesis,
  plus `shared/stack.md`. The human spotted that the new telemetry endpoint's
  documented base URL was wrong — and the root cause was a `.context/` gap: the
  public hostnames were recorded NOWHERE in `.context/`, only in
  `deploy/proxy/Caddyfile` and a comment in `docker-compose.dev.yml`. Caddy serves
  **four** hostnames — prod `buildmyclub.co.uk` + `www.` + **`api.`**, dev `dev.` +
  **`api.dev.`** — all reverse-proxied to the same container with **no path
  rewriting**, so the `api.` hosts (the intended client entry points) still require
  the `/api` prefix: `api.buildmyclub.co.uk/api/telemetry` is 200,
  `api.buildmyclub.co.uk/telemetry` is 404 (both verified live). Recorded in
  environment.md, structure.md's proxy bullet, routes.md's header (so every path in
  that file has a stated host), stack.md and the synthesis overview, and added as a
  Base URL table to `docs/api/live-telemetry.md`. No code changed.

- **New pass (2026-10-21), on top of commit 0f1e0c3** (uncommitted).
  **Stages touched:** 01_overview, 02_architecture, 04_interfaces (routes.md,
  controllers.md, services.md), 06_documentation, 07_synthesis. Added the public
  `GET /api/telemetry` endpoint (`Api\TelemetryController` +
  `docs/api/live-telemetry.md` + 6 functional tests), exposing the existing
  `LiveTelemetrySnapshot` aggregate as JSON for the React Native app — scoped by the
  human as public / scalars+feed / no dossier. Not a verbatim dump of the snapshot:
  the stored feed items carry `at` (a real `DateTimeImmutable` persisted into the json
  column, which json_encodes to a PHP `{date,timezone_type,timezone}` artifact), `time`
  (a relative string frozen at generation, so stale on arrival and permanently frozen in
  a client cache) and `dossier` (web boardroom-overlay bulk) — all three stripped, with
  tests asserting they never reach a client. `capitalDeployedPence` is cast because the
  `bigint` column hydrates as a string. Note `attachClubDossiers()` runs inside
  `refresh()`, so dossiers ARE persisted — excluding them is a serializer strip, not an
  avoided query. Known gap left deliberately: `amountExact` is a pre-formatted GBP
  string and the raw pence is not stored per event, so a client cannot localise it;
  fixing that needs a generator change + snapshot rebuild.
  Same pass also suppressed the deployed container's version-disclosure headers
  (`expose_php = Off` in a new `docker/php.ini`, `server_tokens off` in
  `docker/nginx.conf`) — every response had been publishing `PHP/8.5.12` and
  `nginx/1.30.4`. Verified from a real booted container: `X-Powered-By` gone
  entirely, `Server` reduced to a bare `nginx`. Local Lando is unaffected (different
  images, neither reads these files). Updated `01_overview/output/environment.md` and
  `02_architecture/output/structure.md` for both.

- **Doc-only pass (2026-10-21), on top of commit 0f1e0c3** (uncommitted).
  **Stages touched:** 04_interfaces. Scoping a telemetry endpoint for the React
  Native app surfaced two gaps in `04_interfaces/output/routes.md`: the
  `DashboardController` bespoke-route list omitted `/admin/telemetry-config` and
  `POST /admin/telemetry-config/rebuild`, and nothing recorded the **negative**
  fact that there is no `/api/telemetry` route at all — `LiveTelemetryService`,
  `LiveTelemetrySnapshot`, a generator command and a 15-min cron all exist, so the
  natural assumption is that an endpoint does too. `LandingController` (Twig) is
  its only consumer. Both now stated in routes.md. Also corrected a **stale** fact
  in `04_interfaces/output/services.md`: it claimed some `LiveTelemetryService`
  feed entries (sackings, contract disputes, youth intake) are illustrative with no
  backing data. Those six static lines were removed by product decision and "real
  events only" is now a binding constraint on every category — the note said the
  opposite of the rule. No code changed.

- **New pass (2026-10-21), on top of commit c8f4d80** (this pass's own changes
  are uncommitted on top of it). **Stages touched:** 01_overview, 02_architecture,
  07_synthesis, plus `shared/stack.md`. A security-patching pass, user-initiated
  off the 2026-10-21 PHP advisories (GHSA-w8hw-w9fx-j79w / GHSA-6wmf-4rv5-p3rp).
  Two separate pieces of work: (1) **PHP 8.4 -> 8.5**, deployed images pinned to an
  exact `php:8.5.12-fpm-alpine` instead of a floating tag, local Lando moved onto
  `devwithlando/php:8.5-fpm-7` (PHP 8.5.7) via an `appserver` image override
  because Lando 3.25.6's `symfony` recipe rejects `php: '8.5'` outright — so local
  dev now deliberately trails deployed by five patches, recorded in `.lando.yml`'s
  own comments. (2) **43 Composer advisories -> 0**, which required Symfony
  8.0.* -> 8.1.* (not optional: Composer's advisory policy made the entire 8.0
  branch unloadable), dragging EasyAdmin 5.0.1->5.7.0, Twig 3.23->3.30, API
  Platform 4.2.19->4.4.5, PHPUnit 13.1.1->13.4.3. Four real breaks found and
  fixed, each verified rather than assumed: PHP 8.5's statically-compiled opcache
  failing `docker build` (`cp: can't stat 'modules/*'`), the
  `ReflectionMethod::setAccessible()` deprecation (3 call sites), Symfony 8.1's
  new static `KernelTestCase::runCommand()` colliding with a test helper and
  killing the whole suite at exit 255, and EasyAdmin moving `ea-clickable-row`
  from server-rendered markup to runtime JS. Also fixed a pre-existing unrelated
  breakage: `lando composer` had no composer binary in the image at all. Updated
  `shared/stack.md` (framework/PHP versions + a new dependency-security-floor
  bullet), `01_overview/output/environment.md` (Lando PHP override, opcache trap,
  new "Keeping PHP and dependencies patched" section),
  `02_architecture/output/structure.md` (Dockerfile pin + opcache), and
  `07_synthesis/output/current-focus.md` (new cluster 4).

- **New pass (2026-10-20), on top of commit bf037a2** (this pass's own changes
  are uncommitted on top of it). **Stages touched:** 05_ui. Took the v2 facility
  sprite drop (`assets/Components/facilitiesV2/facilities/`, dated 2026-10-20)
  and rebuilt the landing page's stadium render on it, per explicit user
  request. `public/assets/stadium-compositor.js` was the last hand-ported
  compositor — viable when v1's `isoEngine.ts` was ~19KB and only the helpers
  the stadium touched had to be transcribed, not against v2's ~32KB engine (new
  `paintPixels` lighting, shared massing, five tree kinds) and its 301 pixel
  hashes. It is now GENERATED by a new `scripts/build-stadium-compositor.sh` +
  `scripts/stadium-compositor/entry.ts`, the fourth sibling of the avatar/kit/
  trophy build scripts, parity-gated on **all four** sites in the drop (301/301
  matching) even though only the stadium is bundled, since `isoEngine.ts` is
  shared. The `look="v1"` legacy tree is neither checked nor shipped. Visible
  changes: the image grows 276x216 -> 324x240 (so `.spotlight-stadium svg`'s
  pinned height goes 160px -> 178px, the same ratio, to keep the bowl itself the
  size it has always been rather than ~10% smaller), cast shadows and window
  highlights appear, and trees are mixed and jittered rather than a grid ring. `ClubSpotlightService::buildStadiumConfig()` now doubles the
  shop/museum/car-park levels as it already did the stands — v2 redrew those
  three for 10 levels, so the old raw 1-5 passthrough would have halved them —
  and `landing_v` is bumped to `20261020` so the cached old bundle cannot
  survive the deploy. Updated `05_ui/output/design-system.md` (the "three
  generated compositors, stadium predates the rule" bullet is now four, plus
  two new bullets on the multi-file drop and the 1-5 -> 1-10 level mapping).
  Full suite green (1281 tests), including two new/updated assertions in
  `ClubSpotlightStadiumConfigTest`. **Not done, deliberately:** the other three
  facility sites (training ground, medical centre, scouting) are still unused
  backend-side, and the drop's `selected` dimming option is forwarded but unused
  — it mixes toward `BACKDROP[time]`, which the spotlight card's green pitch
  band is not.

---

- **New pass (2026-10-18), on top of commit 8eae79a** (this pass's own changes
  are uncommitted on top of it). **Stages touched:** 03_data, 04_interfaces,
  05_ui, 06_documentation. Replaced the trophy SVG system with a sprite
  component matching the avatar and kit/badge ones, per explicit user request.
  `League` and `CompetitionTemplate` each dropped `trophy_image` (a
  `trophy-1`..`trophy-15` slug naming a static black silhouette in
  `public/images/trophies/`) and `trophy_colour` (`App\Enum\TrophyColour`,
  applied as a CSS filter chain duplicated across the admin leagues screen and
  the landing honours board) for a single nullable `trophy` JSON column holding
  the sprite's own `TrophyConfig` — migration `Version20261018202041`, which
  **converts** rather than resets (finish maps 1:1 bar `gold_silver` →
  `twotone`; the 15 slugs spread across the 10 new styles in order, there being
  no artwork correspondence). New `TrophyStyle`/`TrophyFinish` enums in
  `src/Enum/Appearance/` (ribbon colours reuse `KitColor`, the sprite's ribbon
  palette being the shared kit palette), new all-static `TrophyConfigFactory`
  (defaults/validate/legacy mapping — the single validator in front of admin
  posts, the world import and the backfill), new `TrophyConfigType` admin
  widget + form theme + `trophy-config-widget.js`, and a new GENERATED
  `public/assets/trophy-compositor.js` built from the shared
  `assets/Components/trophy16/trophy16.ts` by
  `scripts/build-trophy-compositor.sh` — parity-gated on that sprite's own
  pixel-hash fixture (24/24 cases matching), the third sibling of the avatar
  and kit build scripts. `GameConfig::leagueTierDefaults` rows now normalise
  through `normaliseTierRow()` on read and write so stored JSON and old config
  export files keep their trophies. Updated
  `03_data/output/{entities,migrations}.md`,
  `04_interfaces/output/services.md`, `05_ui/output/design-system.md` (the
  "keep two filter chains in step" rule is gone, replaced by a note on the
  three generated compositors), `06_documentation/output/index.md`. New client
  contract doc `docs/api/trophy-config.md`. Full suite green (1278 tests) bar
  one pre-existing unrelated failure
  (`AdminStatsControllerTest::testLeaderboardsCoverEveryCategoryCappedAtTen`,
  drift from the two preceding leaderboard-kit/flag commits — confirmed failing
  identically on a clean `8eae79a` checkout). New coverage:
  `TrophyConfigFactoryTest` (32 tests, incl. 4 that read the generated bundle's
  own exported vocabulary via node to catch PHP↔sprite drift, skipping where
  node is absent as it is inside Lando) plus two world-pack trophy assertions
  in `WorldInitializationTierPackAgentTest`.

---

- **Commit:** 570d2d7 (as of writing — this pass's own changes are
  uncommitted on top of it, pending the user's usual explicit commit
  request). **Stages touched:** 03_data, 04_interfaces (two linked changes
  in one session — adding America/Canada/Japan/Nigeria as generation-capable
  countries, and a repeatable "add a country" process, per explicit user
  request):
  1. `App\Enum\Country` gained `US`/`CA` cases (`isGenerationCapable()` now
     covers 13 countries: the original 9 plus US/CA/JP/NG) and two new
     helpers, `fromNationality()`/`labelMap()`. New
     `App\Service\CountryContent\CountryContentRegistry` consolidates what
     used to be 4 independently-drifting per-country consts
     (`NpcClubGenerationService`'s place/suffix/stadium data,
     `WorldRegion::NATIONALITY_REGIONS`, `MarketPoolService::NATIONALITY_MAP`)
     into one file keyed by `Country` — `NameGeneratorService`'s actual name
     pools stay where they are (too large to move safely) but are now keyed
     off `Country::nationalityMap()` via `NameGeneratorService::nationalities()`
     instead of a 4th hand-duplicated list; only `'Polish'` (no `Country`
     case) remains a standalone addendum in both. New `WorldRegion::
     NORTH_AMERICA` case. Authored real content (places/suffixes/stadium
     formats, American/Canadian name pools, business cluster) for all 4 new
     countries via web research. Two new commands,
     `app:country:bootstrap`/`app:country:check`, replace what used to be
     1 admin click + 8 admin clicks + 2 separate CLI commands with one
     command plus a read-only audit. Fixed the 7 previously-hardcoded
     admin-template country/nationality lists (2 generate-screen dropdowns,
     the enabledCountries checkboxes, 2 display-only country-label maps, 2
     nationality chip lists) to read from `Country`/`NameGeneratorService`
     dynamically, closing the exact drift class documented in
     `Country`'s own docblock and
     `docs/superpowers/specs/2026-07-06-club-name-options-canonical-source-design.md`.
     New runbook: `docs/world-generation/adding-a-country.md`.
  2. Separately-requested: a new admin "League Tier Defaults" bulk-edit
     screen on `admin_leagues_overview` (one row per tier, shared across
     every country — promotion spots, TV deal, prize money, position pot,
     sponsor count, trophy design/colour) that writes to new
     `GameConfig::$leagueTierDefaults` (json, keyed `"1"`-`"8"`) — the new
     single source of truth `LeagueService::generateLeaguesForCountry()`
     reads, replacing the old hardcoded `LEAGUE_TIER_DEFAULTS` const. A
     league-picker (checkbox-per-league + "select all") lets the same submit
     cascade the new values onto selected **existing** `League` rows too
     (`LeagueAdminController::bulkEditTierDefaults()`, new route
     `admin_league_tier_defaults_bulk_edit`). "Relegation spots" was
     explicitly dropped from scope per the user — the backend has no stored
     relegation count at all, only `promotionSpots` (relegation is decided
     by client-reported flags). Per-country league cards on that same page
     now default-collapsed (new Expand/Collapse-all controls) so the new
     form isn't buried.
  Migration `Version20261004085641` (the auto-generated `doctrine:migrations:diff`
  also surfaced a large amount of pre-existing, unrelated schema drift —
  DROP DEFAULT clauses, index renames, and critically DROP INDEX on raw-SQL
  partial unique indexes not representable in ORM metadata, see CLAUDE.md's
  Testing section — none of that was included; the migration was hand-trimmed
  to just the one `game_config.league_tier_defaults` column). `wunderkind_test`
  needed the same manual column add documented in CLAUDE.md's Testing
  section (schema:update's own diff would have dropped those same unrelated
  partial indexes, so it wasn't used — the column was added directly via
  `lando psql -d wunderkind_test`). Full suite green (1108 tests) after.
  CLAUDE.md's Architecture section gained a new "World Generation:
  Countries & League Tier Defaults" subsection; Key Services/Key Entities
  tables updated to match.

---

- **Commit:** feff081 (as of writing — this pass's own changes are uncommitted
  on top of it). **Stage touched:** 03_data (sync v2: nullable
  `player_career_stat.appearance_config` and new `staff_career_profile` table
  — see `entities.md`'s `PlayerCareerStat`/`StaffCareerProfile` entries,
  `schema.md`'s table index, `migrations.md` entry 25. New
  `SyncRequest::$staffStats` field and `SyncService::processStaffCareerProfiles()`.
  CLAUDE.md's new "Sync v2: In-Club Player/Staff Appearance" section explicitly
  distinguishes this client-authoritative data from the backend-generated
  Avatar Appearance system above it. Covered by
  `tests/Service/SyncServicePlayerStaffStatsTest.php`. No new docs/api/*.md —
  the contract is already documented frontend-side per the request
  (`wunderkind-app/docs/api/sync-v2.md`), backend is just conforming to
  an already-agreed spec here.)

---

- **Commit:** fa58cd2 (development/dev/master, pushed). **Stage touched:**
  01_overview (`environment.md` — documented `scripts/pull-prod-db.sh`,
  the reusable production-DB-pull-and-restore-locally workflow, plus a
  `CONTEXT.md` routing-table row mapping the human's "pull prod DB" trigger
  phrase directly to running it — explicit instruction, per the human, to
  run it rather than just describe it when that phrase is used. Raw/
  unscrubbed PII handling was an explicit human decision made via
  `AskUserQuestion` when the script was built this same session, recorded
  here so a future session doesn't second-guess or silently "fix" it.)

---

- **Commit:** b153556 (as of writing — this pass's own changes are
  uncommitted on top of it, pending the user's usual explicit commit
  request, same as the UserLedger pass below it this stacks on top of).
  **Stage touched:** 04_interfaces (`controllers.md` — admin User edit page
  now shows a per-club summary panel: kit/badge previews, last sync/league
  position/form, earnings vs. dividends drawn, and the user's overall
  cross-club balance. `UserCrudController::edit()` override +
  `admin/user_edit.html.twig`; extracted `resultBadge` macro from
  `club_profile.html.twig` into `admin/_macros.html.twig` for reuse; new
  `SyncRecordRepository::findLatestValid()` /
  `UserLedgerRepository::getTotalDividendsByClub()`. CLAUDE.md's Centralized
  User Ledger section updated to match. Covered by
  `tests/Controller/Admin/UserCrudControllerTest.php`.)

---

- **Commit:** b153556 (as of writing — this pass's own changes are
  uncommitted on top of it, pending the user's usual explicit commit
  request). Per the staleness check, `.context/` was 3 commits behind HEAD
  going into this session (`b153556` dedupe squad bonds / resolve player
  names, `2a1c108` positive bonds + telemetry feed categories, `ea2c2dd`
  tiered excursion cost/effect progression) — the user chose to proceed
  without reviewing those first, so they remain **unreflected** in
  `.context/` and are a follow-up, same as the admin club-profile/landing-
  page gap noted in the entry below from 2026-09-28.
- **Date:** 2026-10-02
- **Stages touched this pass:** 03_data, 04_interfaces (new `UserLedger`
  entity/`user_ledger` table — a centralized, cross-club financial audit
  trail for dividend draws; see `entities.md`'s `UserLedger` entry,
  `schema.md`'s `user_ledger` constraint note + the `dividend_draw`
  `ledger[].category` addendum, `migrations.md` entry 24, and
  `services.md`'s `UserLedgerService` entry. New read-only
  `UserLedgerCrudController`, documented in `controllers.md`/`routes.md`.
  Also widened `LiveTelemetryService::ledgerAmountToPence()` from private
  to `public static` so `UserLedgerService` could reuse the existing
  100x-pence correction instead of adding a third implementation — see
  `schema.md`'s "`sync_record.payload` field conventions" section.)
  CLAUDE.md's Architecture section synced with a new "Centralized User
  Ledger" subsection; Key Services/Key Entities tables updated to match.

---

- **Commit:** df082fe (development, merged into dev)
- **Date:** 2026-09-28
- **Stages touched this pass:** 01_overview (corrected the git branching
  policy in `output/deployment.md`, and synced `CLAUDE.md`'s Git Workflow
  section to match, per explicit user correction: `development` is the
  working branch for most tasks — commit directly there, it's a direct
  child of `master` — not a mandatory feature-branch + PR workflow as
  previously documented. `dev`/`master` are still only ever reached by
  merging `development` in, propagated as two separate merges in that
  order. Also recorded as a standing feedback memory outside this repo, as
  a backstop in case this doc drifts again.)

---

- **Commit:** 5cf9851 (also merged into `dev` and `master` this pass — see
  the merge-tagged entry immediately below)
- **Date:** 2026-09-28
- **Stages touched this pass:** 03_data, 04_interfaces (targeted fix + doc
  pass, not a full stage regen — `.context/` was ~28 commits stale going
  into this session per the staleness check, including two admin
  club-profile commits (`2cb67c4`, `aa07cb9`) and the landing-page
  Boardroom Incident Feed (`0ebf4e4`) that `04_interfaces/output/
  controllers.md` still doesn't document; flagged to the user as a
  follow-up rather than regenerated unprompted). Fixed a real financial
  display bug found via user-supplied evidence (in-game ledger screen vs.
  admin club profile vs. raw sync payload for the same events, all three
  compared side by side): `payload.ledger[].amount` is 100x true pence, not
  real pence as every consumer assumed — confirmed by cross-referencing
  `excursions[].costPence` against its matching `fan_initiative` ledger
  line (always exactly 100x). Added `LiveTelemetryService::
  ledgerAmountToPence()` (corrects `capitalDeployedPence` and
  `buildLedgerEvents()`'s `amountPence`) and the `ledgerCurrency()` Twig
  macro (corrects `templates/admin/club_profile.html.twig`'s Ledger table,
  which previously ran raw `entry.amount` through the pence-only `currency()`
  macro). `LiveTelemetryServiceTest` updated for the corrected values (44
  tests green). Documented the convention in `03_data/output/schema.md`
  (new "`sync_record.payload` field conventions" section) and linked it
  from `entities.md`'s `SyncRecord` bullet and `04_interfaces/output/
  services.md`'s `LiveTelemetryService` bullet.

---

- **Commit:** 160ac7a (development, merged into dev)
- **Date:** 2026-09-28
- **Stages touched this pass:** 02_architecture (World Pack Cache generation
  rewritten to generate every club's players/staff/scouts fresh, in-memory,
  rather than drawing from the shared pool — new `WorldPackClubGenerationService`,
  a tracking schema (`WorldPackGenerationRun`/`TierRun`/`ClubRun`, migration
  `Version20260928151627`), and Messenger-driven tier/club batching
  (`WorldPackGenerationOrchestrator`, `WarmWorldPackTierMessageHandler`,
  `WarmWorldPackClubMessageHandler`, `WorldPackTierAssemblyService`); admin UI
  (`worldpack_cache.html.twig`) reworked to trigger regenerates and poll live
  per-country/tier/club progress instead of a client-side synchronous loop;
  removed the now-unneeded `pool-warm.sh`/`worldpack-warm.sh` cron jobs; split
  World Pack messages onto their own `worldpack` Messenger transport, separate
  from `async` (push notifications) — a country regenerate's 100+ club-generation
  messages were sharing one queue with push sends and delaying each other, so a
  new `docker/worldpack-consume.sh` cron entry drains it independently). Also
  fixed the pre-existing PHPUnit "mock without expectations" notices across the
  suite (`createMock()` → `createStub()` where no `->expects()` is used).

---

- **Commit:** 790e98f (development, merged into dev)
- **Date:** 2026-09-27
- **Stages touched this pass:** 03_data, 04_interfaces (added owner identity
  — `name`/`nationality`/`gender`/`dob`/`appearance` — to `User`, replacing
  the redundant `User::$managerProfile`/`Club::$managerProfile` blobs;
  new `AppearanceRole::OWNER` case wired into `AppearanceLifecycleSubscriber`
  and `app:backfill-appearances`; new `POST /api/owner-avatar` partial-update
  endpoint; `UserCrudController` EDIT enabled with an Owner Identity
  fieldset + the appearance widget; migration `Version20260927120000`)

---

- **Commit:** daea03a (sprites, merged into dev)
- **Date:** 2026-09-26
- **Stages touched this pass:** 03_data, 04_interfaces, 05_ui (avatar/kit
  generation demographic + rarity rules: age-banded `hairStyleWeights()`,
  rare ginger `hairColorWeights()`, facial hair extended to players,
  player `face` fixed to neutral, player `headband` at 1-in-1000,
  skin-linked `LipColor::DEEP_BROWN` for dark skin tones, NPC club
  `badgeCentre` generation now excludes `INITIALS` too, and kit
  primary/secondary contrast-checked (WCAG >= 3.0) per variant)

---

- **Commit:** 36c6d31562c41bb91f27f352f7180df4e85f52e6
- **Date:** 2026-09-25
- **Stages touched this pass:** 03_data, 04_interfaces, 05_ui (restructured
  NpcClub's `identity` to nested `home`/`away` kit variants + shared badge;
  `NpcClub::setIdentity()` now syncs `primaryColor`/`secondaryColor` from
  `identity.home`, replacing `NpcClubGenerationService`'s old separate
  color-pair generator; `primaryColor`/`secondaryColor` no longer
  independently admin-editable; also fixed the shorts/socks/trousers picker
  in both this widget and the player appearance widget to render color chips
  instead of a full kit thumbnail, which couldn't show the selected part
  color at all)

---

- **Commit:** 36c6d31562c41bb91f27f352f7180df4e85f52e6
- **Date:** 2026-09-25
- **Stages touched this pass:** 03_data, 04_interfaces, 05_ui, 07_synthesis
  (NpcClub kit+badge `identity` config — new `KitIdentityType`/admin widget,
  `NpcClubGenerationService::generateIdentity()`, `LeagueImportExportService`
  export/import coverage; also synced `CLAUDE.md`'s NpcClub entry and added a
  "Kit & Badge Identity" section)

---

- **Commit:** 36c6d31562c41bb91f27f352f7180df4e85f52e6
- **Date:** 2026-09-25
- **Stages touched this pass:** 01_overview (added `output/deployment.md` — the
  three-branch `development`→`dev`/`master` git merge/propagation policy; also
  synced `CLAUDE.md`'s Git Workflow section to match)

---

- **Commit:** 9a5f72f34f7e02b092413285b761463ed08bb91c (as of writing — this pass's own changes are uncommitted on top of it, pending the user's usual explicit commit request)
- **Date:** 2026-09-20
- **Stages touched this pass:** 04_interfaces (new `push-notifications.md`, moved in from `docs/api/push-notifications.md` by explicit request — that file has been deleted; also documents the new `MATCH_RESULT` push type, fired per-fixture on `CompetitionRoundProcessorService::resolveFixture()`, to both sides), 06_documentation (index updated to point at the new location), 07_synthesis (current-focus.md repointed). `01_overview`/`02_architecture`/`05_ui` untouched this pass.
- **Follow-up pass, same session (2026-09-20):** notification observability + admin debug
  tooling, prompted by a real incident (missing `DEV_FIREBASE_SERVICE_ACCOUNT_JSON`/
  `PROD_FIREBASE_SERVICE_ACCOUNT_JSON` GitHub secrets caused push sends to fail silently, with
  zero trace, because `config/packages/messenger.yaml` had no `failure_transport`). Added:
  `NotificationLog` entity/table + `NotificationLogStatus` enum (`03_data/output/entities.md`,
  `schema.md`, `migrations.md` — migration `Version20260920075918`), `NotificationLoggingSubscriber`
  (Messenger `WorkerMessageHandledEvent`/`WorkerMessageFailedEvent` listener — the single place
  that uniformly catches both handler-body and handler-construction failures),
  `NotificationLogCrudController` (read-only), `NotificationDebugController` (force-trigger all 4
  push types at a chosen club, force-process the queue, validate the Firebase connection via new
  `FirebaseConnectionValidator`), and enabled `failure_transport: failed` in
  `messenger.yaml` (reuses the `messenger_messages` table via `queue_name`, no new migration for
  that part). Updated `04_interfaces/output/routes.md`, `controllers.md`, `services.md`. Full test
  suite green (859 tests) as of this pass, including new coverage:
  `NotificationLoggingSubscriberTest`, `NotificationDebugControllerTest`,
  `FirebaseConnectionValidatorTest`.
- **New pass (2026-09-22), on top of commit f770b15a518685a89b788ca1e05c046e3a3dae82 (this pass's own changes are uncommitted on top of it):** decoupled the competition round lifecycle's draw and resolve phases (previously fused into one `CompetitionRoundProcessorService::processRound()` pass) into two independently-scheduled, independently-claimed steps, per an explicit refactor request. `CompetitionRoundStatus` collapsed to `DRAW_PENDING|DRAWN|RESULTS_PUBLISHED|CANCELLED`; `CompetitionRound` gained `matchesResolveAt` and split its single claim column into `drawLockedAt`/`resolveLockedAt` (migration `Version20260922095155`, includes a data-only status-value remap); `CompetitionTemplate` gained `intermissionRatio` (default 0.3). New pure `CompetitionScheduleCalculator` service; `CompetitionRoundProcessorService` deleted and replaced by `CompetitionDrawService` + `CompetitionResultsService`, each behind its own new command/cron entry (`app:competition:draw-rounds`/`app:competition:resolve-rounds`, both every 1 min, replacing `app:competition:process-rounds`). Round 1 no longer draws synchronously at lock time — `CompetitionLockService` now only schedules it (making `ActiveCompetitionStatus::SCHEDULED` a real, observable window for the first time). `CompetitionRoundReminderService` repurposed around the resolve phase (`ROUND_RESOLVING_SOON` on a `DRAWN` round nearing `matchesResolveAt`, not `ROUND_STARTING_SOON` before a draw). Added an admin "Force Draw" action (`CompetitionDrawService::forceDrawRound()`) alongside the existing "Generate Result" one. Updated `03_data/output/{entities,schema,migrations}.md`, `04_interfaces/output/{services,push-notifications}.md`, `02_architecture/output/structure.md` (cron table). Full Competition-area test suite green (273 tests, 2473 assertions) except one pre-existing, unrelated failure (`RewardApplierServiceTest::testApplyingTwiceDoesNotDoubleGrantTheReward` — confirmed failing on the pre-refactor baseline too, not touched by this pass). New test coverage: `CompetitionScheduleCalculatorTest`, `CompetitionLockServiceTest`, `CompetitionDrawServiceTest`, `CompetitionResultsServiceTest`, `CompetitionLifecycleE2ETest`.

---

- **Commit:** dfc8035a8cb9f014abcf5777da6840bc1f18f7ef
- **Date:** 2026-09-18
- **Stages touched this pass:** 01_overview, 02_architecture, 03_data, 04_interfaces, 05_ui, 06_documentation, 07_synthesis (full warm — rebuilt from scratch after retiring the old generator-script-based `.context/`, previous content preserved in this repo's `git stash`)

- **New pass (2026-10-28), on top of commit 8b0403a** (step 3 uncommitted).
  **Stages touched:** 03_data (`schema.md`, `entities.md`, `migrations.md`),
  04_interfaces (`services.md`, `routes.md`).
  Competition and friendly snapshot restructure, prompted by
  `GET /api/competitions/{id}` dying with an `OutOfMemoryError` in Doctrine's json
  hydration on dev. Root cause was a data-model fault, not tuning: single JSON columns
  holding several concerns with unrelated read profiles, which ORM 3 cannot narrow since it
  removed `PARTIAL`. Five new tables (`competition_entrant_squad`,
  `competition_result_audit`, `competition_response_cache`, `friendly_challenge_squad`,
  `friendly_challenge_audit`), the match engines decoupled from entities behind
  `MatchEngineSide`/`MatchEngineContext`, a size cap at the registration door, and the
  bracket response prebuilt rather than reassembled per request. Instance data for both
  domains was cleared by explicit authorisation to keep the migrations simple; the
  admin-authored template catalogue was not touched. New `schema.md` section "The split
  rows" states the two rules that keep this from regressing — no inverse `OneToOne`
  association, and batch-fetch wherever more than one row is on screen.

- **New pass (2026-10-28), on top of commit e96f791** (uncommitted).
  **Stages touched:** 04_interfaces (`services.md`). Phase 1 §2.1 of
  `docs/superpowers/plans/2026-10-28-tournament-concurrency-and-queues.md`: round claims are
  now leases rather than one-way flags, and a round's status transition commits with the work
  it describes. The second half was not in the plan — implementing the lease surfaced that
  `claimDraw()` committed `status = DRAWN` before `drawRound()` created any fixture, so a
  failed flush orphaned the round from `findDueForDraw()` *and* `findDueForResults()` at once
  (the latter because `matches_resolve_at` stayed NULL). No schema change: the lock columns
  were already mapped.

- **New pass (2026-10-28), on top of commit 7e22416** (uncommitted).
  **Stages touched:** 04_interfaces (`services.md`). Phase 2 §3.1/§3.2 of
  `docs/superpowers/plans/2026-10-28-tournament-concurrency-and-queues.md`: the competition
  draw and resolve cron ticks now only dispatch, one message per due round onto a new
  `competition` Messenger transport drained by `docker/competition-consume.sh`. The claim is
  taken in the handler rather than at dispatch, which is what makes over-dispatching free and
  keeps the lease from expiring while a message waits in the queue — and is why, unlike
  `app:starter-pool:audit`, dispatch is not gated on an undrained queue. §3.3 (inline
  provisioning) and §3.4 (queued cache prewarm) remain open.

- **New pass (2026-10-28), on top of commit 8efc555** (uncommitted).
  **Stages touched:** 04_interfaces (`services.md`). Phase 2 §3.4: response-cache prewarming
  is back, as `WarmCompetitionResponseMessage` on the `competition` transport dispatched by
  the draw and resolve handlers after their work commits. Phase 0 had removed the inline
  prewarm because a cron tick cannot read fresh state; a message can, since
  `DoctrineClearEntityManagerWorkerSubscriber` clears the EntityManager between messages
  (verified in the container rather than assumed). Deliberately not dispatched on
  register/withdraw/resubmit — the document is cheap before any round exists, and that would
  queue one rebuild per registration. §3.3 (inline provisioning) is the only open Phase 2
  item.

- **New pass (2026-10-28), on top of commit 883922b** (uncommitted).
  **Stages touched:** none (test-only plus the plan doc). §2.3 audited: the capacity-fill →
  lock transition is correctly serialized and atomic — `register()` takes
  `SELECT ... FOR UPDATE` on the competition row first and calls
  `CompetitionLockService::lock()` inside that transaction, and `lock()` only persists, so
  seeds/rounds/inbox messages commit with the entrant insert and the fee. No bug. One real gap
  closed: `registration_closed` had no coverage, and the meaningful path is the
  *in-transaction* check rather than the controller's (possibly stale) pre-flight read. Also
  recorded in the plan: the final registrant pays the latency of locking the whole bracket,
  acceptable and deliberately not queued, because splitting it would leave a SCHEDULED
  competition with no rounds — the orphaning shape §2.1 removed. Plan gains a Phase 4:
  nothing in it has been load-tested, and the 15-minute lease window is a guess.

- **New pass (2026-10-28), on top of commit 0e26e97** (uncommitted).
  **Stages touched:** none (new command, tuning, plan doc). Phase 4 of the concurrency plan:
  `app:competition:benchmark` times the real pipeline and reports payload composition.
  Two settings that were guesses are now measured — `CLAIM_LEASE_SECONDS` 900 → 300 (slowest
  full round ~8.4s at capacity 64, so 15 min was ~107x and cost 15 min to recover a crashed
  tick), and `competition-consume.sh`'s `--limit=10` replaced by `--memory-limit=256M` because
  ten worst-case resolves are ~75s against a 50s time limit, so the count never bound.
  Two of my own assumptions were corrected by the numbers: filling via `spoofAllEntrants()`
  conflated club creation with registration (a ~25x overstatement — registration is ~35ms
  median), and `narrativePayload` is **not** the dominant share of the bracket payload —
  lineups are (41.5% vs 32.6% at capacity 32). Together ~74% of the ~805KB response is
  per-fixture detail that `GET /api/matches/{id}` already serves, which makes the case for the
  bracket not embedding `result` at all rather than merely dropping narrative. Still not
  measured: contention (single-process harness) and production instrumentation.

- **New pass (2026-10-28), on top of commit 3ff2159** (uncommitted).
  **Stages touched:** 04_interfaces (`routes.md`). Acted on Phase 4's payload finding:
  `GET /api/competitions/{id}` no longer embeds a per-fixture `result` block. Measured 397KB →
  **59KB** on the same capacity-32 bracket (85%). `CompetitionResult::toClientSummary()` and
  its private helpers are deleted — no callers once the bracket stopped embedding them, and
  `MatchQueryService::serializeCupMatchDetail()` was always the real client shape. The admin
  "what the device receives" panel now links `/api/matches/{id}` instead of inlining a payload
  the device no longer gets. **Requires an app change** — client contract and key-mapping
  table in `docs/api/competition-bracket-no-embedded-result.md`; the `MATCH_RESULT` push
  already carries the `fixtureId` to fetch with. Test coverage moved rather than deleted: the
  payload-shape assertions now run against the match endpoint.

- **Note (2026-10-28), no stage change.** §5 of the concurrency plan is closed as **moot**.
  The cloud review flagged, and I repeated, that the competition truncate destroys entry fees
  already deducted from `club.balance` with no refund. It does not: the backend's
  `club.balance` is a sync mirror, overwritten absolutely from the device
  (`SyncService::process()` — *"The client is the game engine… accepted as authoritative
  snapshots from the device"*), so the backend never held the authoritative value. Recorded
  for the next reader because the same mistake is easy to make: a balance column in this
  schema does not imply the backend owns that balance. Side observation left in the plan: the
  server-side fee deduction in `register()` is transient for the same reason, so if the client
  does not deduct locally an entry fee is effectively free — an app-side question, not this
  repo's.

- **`01_overview/tribal-knowledge.md`, `03_data/entities.md`, `07_synthesis/architecture-notes.md`
  — the authority boundary is now written down (2026-10-28).** Stated by the human: the device
  is the master for club data, and the backend only learns per-club state through sync data.
  `tribal-knowledge.md` gains a section (its first real entry — it previously held only the
  "nothing to add" placeholder), `entities.md`'s `Club` entry gains the note at the point a
  reader meets `balance`, and `architecture-notes.md` gains an "Authority" section listing what
  the device owns versus what the backend owns, including the split inside competitions (the
  backend owns the bracket; each entrant's squad is a client snapshot it stores verbatim).

- **`04_interfaces/output/services.md` — `splitSnapshot()` is now shared (2026-10-28).**
  Phase 3 of the concurrency plan (the review's hygiene nits) is done. The friendly copy of
  the split is gone, along with a third copy in `tests/Support/FriendlyFixtureTrait`; the
  duplication had been hiding a real divergence in the OVR rule, recorded in `services.md`.
  Friendly squad rows are now pruned at full-time in `resolveSingleMatch()`, the same way
  and in the same order as competition squads on completion, so the friendly admin detail
  page shows `—` for both squad fields on a COMPLETE challenge by design (its help text says
  so). `prewarmResponse()` had already gone when prewarm became a queued message.

- **`03_data/output/migrations.md` — `Version20261028141656` (2026-10-28), comments only.**
  Drops the leftover `(DC2Type:...)` column comments on `friendly_challenge` and
  `idempotency_record`, written by pre-DBAL-4 migrations. No column, index or data touched.
  Worth doing because those eleven empty strings were the whole reason
  `doctrine:schema:validate` reported the schema out of sync — a red that hides real
  structural drift, which is exactly the signal that caught an entity silently losing its
  `use Doctrine\ORM\Mapping as ORM` import earlier in this work. Round-trips: down restores
  all eleven, up clears them, validate green either side.

- **Note (2026-10-28), no stage change — the bracket contract is closed client-side.** The
  removal of `fixtures[].result` needed **no behavioural app change**: the tournament hub
  already rendered a tie from `score`/`winnerEntrantId`/`entrants[]` and passed `matchId`
  onward, its `Fixture` type never declared `result`, and the `MATCH_RESULT` push already
  routed to the match href. What the change invalidated was documentation and an
  already-dead type block. So the last outstanding cross-repo item from the tournament work
  is resolved, and `docs/api/competition-bracket-no-embedded-result.md` is now a reference
  rather than a migration task.

  Worth keeping: **a cup fixture's own id is its match id** —
  `MatchQueryService::findMatchDetail()` resolves the id against
  `CompetitionFixtureRepository` (falling through to `FriendlyChallengeRepository` for a
  friendly; distinct UUIDs, so that is a cheap miss, not an ambiguity). App routing sends a
  push's `fixtureId` straight to `GET /api/matches/{id}` on that basis, so the invariant to
  preserve is that a fixture id is accepted there at all.

- **`04_interfaces/output/services.md` — the competition pipeline now reports on itself
  (2026-10-28).** New `CompetitionHealthService` + `app:competition:health`
  (`--json`/`--strict`), scheduled every 5 minutes by `docker/competition-health.sh`: queue
  depth, consumer lag, round claims held vs past the lease, rounds overdue past a 180s grace,
  and response-cache coverage — all read-only DBAL derived from existing rows, so there is no
  metrics store to keep. `CompetitionResponseCacheService` additionally logs each build as
  `competition.cache.build` with `elapsedMs` and `bytes`; hit rate is deliberately derived from
  builds against access-log request counts rather than by logging every hit on the hottest read
  path. This closes the last real gap in the concurrency plan — every bound in the pipeline came
  from a benchmark on one box and nothing in production said whether it still held.

  Two findings worth carrying forward. **Writing the check found a bug in the check**:
  `messenger_messages` is created lazily by the Doctrine transport, so on a fresh database it
  does not exist and the first suite run errored on every case — the absence is now reported
  rather than swallowed into a zero, because a health check must never be the thing that fails.
  And **§3.3 (inline provisioning) is audited rather than outstanding**:
  `CompetitionProvisionInstancesCommand`'s work is bounded by the admin-authored template
  catalogue, not by load — one `INSERT … ON CONFLICT DO NOTHING` per active template, no
  hydration beyond the templates — and the one part that scales with club count
  (`ResolveNewCompetitionAudienceForPushMessage`) is already routed to `async`. Left inline
  deliberately; don't re-open it as unfinished.

- **`01_overview/output/tribal-knowledge.md` + `07_synthesis/output/architecture-notes.md` +
  `04_interfaces/output/{routes,services}.md` + `01_overview/output/deployment.md` — ALL MONEY
  LEAVES THE WORLD PACK, `WORLD_PACK_VERSION` 10 → 11 (2026-11-18).** Owner's design the same
  day: the pack becomes *"truly what it needs to be — a test of teams, players and staff"* —
  identities, squads, abilities, facilities, personalities — and everything monetary is a
  function of live config, resolved on the device at init.

  Removed from the pack: `tvDeal`/`sponsorPot`/`prizeMoney`/`leaguePositionPot` (league),
  `startingBalance` + `land.valuePence` (club), `contractValue` + `agent.commissionRate`
  (player), `weeklySalary` (staff). `leaguePositionDecreasePercent` STAYS — it is a rule, not an
  amount, and the app has no other source for it at init. The scout block already shipped no
  wage and is the pattern the rest now follows. `WorldPackClubGenerationService::buildPlayer()`
  no longer prices at all, and its `$country`/`$tier`/`$clubReputation` plumbing went with the
  pricing call rather than being left as an invitation to put it back.

  **The ruling that changed.** `tribal-knowledge.md`'s two-sided pricing ruling is now
  one-sided: "generated populations are priced once at generation and stored" is superseded —
  **nothing is priced at generation.** The shared-pool half is untouched and still live (stored
  legacy, priced at serve time through `Economy\ServeTimePlayerPricer`). The superseded text is
  kept in place above the new rule rather than deleted, per the house convention.

  **Three things worth carrying forward.**

  1. **The strip had exactly one correct seam, and the obvious one is wrong.**
     `PlayerSnapshotSerializer` is shared with `PlayerBrowseSerializer` and `/api/players/youth`,
     which serve shared-pool rows priced at SERVE time — deleting `contractValue` there would
     silently un-price every scout-search result. And it must not RE-price there either
     (architecture-notes' trap 4). So the money is unset at the PACK boundary, in
     `WorldPackSnapshotBuilder::buildPlayerSnapshot()`. `WorldPackCarriesNoMoneyTest` pins the
     invariant from the pack side *and* pins that the shared serializer still prices, so the
     plausible wrong fix fails a test.

  2. **The sponsor pot needed the COUNT to arrive, not the pot.** `GET /api/initialize/leagues`
     was already the device's source for the other three league figures but has never sent a
     pot — so dropping the pack's key alone would have zeroed league sponsorship. The device
     already rolls a pot (`rollLeagueSponsorPot`, every season boundary after the first), it
     just lacked the census, so that endpoint gained **`sponsorCount`** and **`sponsorSizes`**
     (counted off `LeagueSponsor` rows, upper-cased at the boundary, `null` rather than `{}`
     when none are sourced). Uncached, so no bump. A count is not a price — same category as
     the `clubCount` it already returned.

  3. **`rollLeagueSponsors()` is still called at both pack-build sites, for its side effect
     only.** The pot left the payload, but the call mutates each `LeagueSponsor::$rolledValue`,
     which is country-wide server state `SyncService` sums for an existing save. Deleting the
     call to tidy away an unused variable would leave every sponsor rolled at 0 on the sync
     surface — a different bug in a different payload. Both call sites say so in a comment.

  Tests: new `WorldPackCarriesNoMoneyTest` (recursive money scan over club/league/player/staff/
  scout, plus a named-key list and a `*Pence|*Salary|*Balance|…` suffix scan, so a key nobody
  has thought of yet also fails) and new `InitializeLeaguesSponsorCensusTest`. Six existing
  tests were deliberately inverted with the reason written into each: the two that asserted "a
  generated player must be priced", `WorldPackLandSnapshotTest`'s club key list and three
  `valuePence` assertions, and the two agent-shape pins. ⚠️ **`PlayerSnapshotShapeConsolidation
  Test`'s docblock says its key arrays are historical records that must never be edited — one
  key was removed from `LEGACY_WORLDPACK_KEYS` anyway, and the exception is documented in that
  docblock as a one-off rather than a precedent.** `LEGACY_BROWSE_KEYS` is untouched.

  `app:economy:wage-table` is byte-identical before and after (verified by md5 across a stash)
  — this touched no authored figure.

---

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
