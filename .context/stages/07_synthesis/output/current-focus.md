# Current Focus

## DONE in the working tree, 2026-11-18 — R2 is AUTHORED (not committed, not pushed)

`Version20261118120000` authors resolution R2 end to end, `WORLD_PACK_VERSION` goes 9 → 10,
check 8's two TV-dependent bounds are amended per the owner's ruling (finding 3), and
`LeaguePayoutResolverTest` plus a new `EconomyBaselineTurnoverAuthoringTest` pin it. The briefs are
`docs/economy-loose-ends/r2-economy-authoring-brief.md` and `r2-finish-and-commit-brief.md` (outside
both repos); the migration's own docblock and `03_data/output/migrations.md` carry the detail.

✅ **COMMITTED in this commit, after the owner amended two of the three commit conditions
(review, 2026-11-18).** The brief asked for the full suite passing, the bounds amended, and every
remaining gate failure attributed to a named input divergence. The middle one was done; the other
two were unmeetable **as written** on any box without dev's data, and the implementing session
stopped and reported rather than improvising around them, which was the right call and is why the
amendment is recorded rather than assumed:

- **condition 1 becomes "no regression against a full-suite baseline at `740f2c8e`"**, which the
  artefacts in `docs/economy-loose-ends/r2-run-2026-11-18/` demonstrate: the baseline and the
  post-change run fail in the SAME five classes, and the only asymmetry is a second method of the
  one class shown to be non-deterministic;
- **condition 3 gains "or to a named pre-existing defect shown independent of R2"**, which the ten
  `bankruptcyDeductionTiers` lines are — finding 4 below, already documented in this file and in
  `docs/economy-threshold-review.md` §1, and provably a function of the turnover row alone.

⚠️ **`NpcClubForeignClubsCitySizeTest` is a KNOWN FLAKE and should be treated as one until someone
fixes it** — three identical isolated runs gave 1, 1 and 2 failures with the failing method
alternating. Recorded here because an intermittent failure that nobody has written down is
indistinguishable from a regression the next time it fires, and it was within one test of masking
this one.

The two conditions as originally written, and what they ran into:

- **The full suite does not pass, and does not at `740f2c8e` either** — 5 failures on a clean tree
  (`NotificationLogCrudControllerTest`, `CompetitionControllerTest`, `FriendlyResolveSingleMatchTest`,
  `WorldOverviewServiceTest`, and `NpcClubForeignClubsCitySizeTest`, which is **non-deterministic**:
  three identical isolated runs gave 1, 1 and 2 failures with the failing method alternating). The
  economy suites are green (30 + 20 tests). Tests run against `wunderkind_test`, a different
  database from the one the migration touched, so the migration cannot have influenced any of them.
- **10 of the 31 gate failure lines cannot be tied to an input divergence** — they are finding 4
  below, and they would read identically on dev.

Four findings, the first three about INPUTS this authoring does not own.

⚠️ **1. The local Lando box is not the dev box, and four of its economy inputs diverge.** This is
the first thing to re-check before reading any local gate output as a verdict:

| input | local box | dev / the derivation |
|---|---|---|
| `economy_baseline_turnover_pence` | `[0,0,0,0,0,0,0,0]` (unauthored) | £388.7m … £164,852 |
| `starter_config.npc_squad_config` midpoints | 33, 30, 28, 26, 26, 26, 23, 22 | **26, 23, 21, 20, 19, 18, 17, 17** |
| `league.tv_deal` tier 1 | £10,000,000 (3.0% of turnover) | £136,000,000 (35.0%) |
| sponsor bands | tier 1 0.7%, tier 8 53.8% | inside the agreed 8–30% |

The squad row is not a guess: it is the ONLY row that reproduces the live published scale
(`390404650 … 178885951`, recorded in `REMAINING-WORK.md` §2f.1 against `game-config-en.LIVE.json`)
from the live authored turnover, to the integer. `scripts/pull-prod-db.sh` is forbidden by the
brief, so the local gate cannot be made to exit 0 whatever is authored.

⚠️ **2. `S_tier` depends on the SQUAD ROW as well as the ability bands, and that was not on
anyone's list.** `referenceSquadAbilitySum()` sums `ability^k` over a reference squad whose SIZE is
`npcSquadConfig`'s midpoint, so the turnover ladder, `league_ability_ranges` AND the squad sizes
decide check 1 jointly. On the dev squad row R2 publishes
`332484503 · 331618346 · 303799192 · 290519761 · 258711870 · 257021938 · 235402484 · 233303081` —
strictly decreasing, exactly as the brief asserts, which `EconomyBaselineTurnoverAuthoringTest`
pins through the real resolver. On the LOCAL row the same authored turnover yields tier 8 at
179.3699 against tier 7's 173.6417 and check 1 fails. **If the dev row is ever changed, check 1 is
back in play with no turnover edit at all.**

✅ **3. RULED 2026-11-18 (owner): check 8's two TV-dependent bounds are amended.** R2 put EN
tier 1 at **50.38%** against constraint (b)'s 50% ceiling and the tier-1/tier-8 spread at **4.34x**
against its 4x cap, so `PAYOUT_SHARE_MAX_PCT` went **50 → 55** and `PAYOUT_SHARE_SPREAD`
**4 → 5** in `EconomyWageTableCommand`. `CHAMPION_SHARE_MAX_PCT` stays 100 and nothing else in
that file moved.

The reasoning, which the constants' own docblocks now carry: the payout half is innocent and
provably so — prize and pot are a flat **9.29% of turnover at BOTH ends**, and the flatness check
passes with 0.0065pp against its 0.5pp tolerance — while the whole breach is TV's SHARE of a
turnover figure that moved beneath it. `league.tv_deal` is an authored absolute, R2 cuts tier-1
turnover 15% and deliberately does not re-derive TV, so TV goes 35.0% → 41.1% with no TV figure
changing. The spread cap was additionally measuring the pyramid rather than a mis-authoring, since
EN tier 8's TV deal is £4,950 a year. Every alternative was costed and is worse: £334.1m clears the
ceiling but still fails the spread at 4.31x and drops tier-1 coverage to 84.5%; £366.8m clears the
spread at 77.2% coverage; re-deriving tier-1 TV clears both but drops coverage to 79.2%, and
holding coverage forces T1 ≈ £290m, which tightens the check-1 chain to T2 ≤ £100.2m and re-opens
every tier.

⚠️ **This is the one place the "do not relax any check" rule was deliberately set aside**, by the
owner and for these two constants only. `LeaguePayoutResolverTest::testR2SitsInsideCheck8sAmendedBoundsAndWouldHaveBreachedTheOldOnes()` pins the two FIGURES and BOTH sets of bounds, so moving
either bound back fails rather than passing silently.

⚠️ **4. `bankruptcyDeductionTiers` has no tier dimension and collapses at tiers 1–5 under ANY
authored turnover.** Not R2's doing, and R2 slightly improves it.

**NOT a new finding — corrected in review, 2026-11-18.** This file already carried it, in the
2026-11-12 overhaul section's "Still open" paragraph below ("two rows that cannot fire … the ladder
is traversed in under half a week at the top five tiers"), with the analysis and a weeks-of-wages
proposal in `docs/economy-threshold-review.md` §1. It was labelled NEW here, which would have
grown the backlog a duplicate. **What this pass genuinely adds** is the measurement and the
attribution: the tier-1 figure (£40,000.01 against a £3,238,043/wk bill), and the proof that the
bill depends on nothing but the turnover row — which is what makes these ten gate lines a
pre-existing defect rather than a local-box divergence, and that is the part that mattered for the
commit decision. The harshest band
begins at a flat **£40,000.01** of deficit while a tier-1 weekly wage bill is **£3,238,043**, so
the gate reports the band "reached in 0.01 weeks of unpaid wages — the ladder has no range left
here" at EN and SC tiers 1–5, ten lines in all.

The bill is `turnover x 0.45 / 46` and so depends on NOTHING but the turnover row — not on the
squad midpoints, not on TV, not on the sponsor bands. It is therefore **not** a local-box
divergence: it will read identically on dev after R2, and it already reads WORSE there before R2
(£388.7m gives £3,801,742/wk). The same column also has rows 4 and 5 unreachable behind row 3's
null `deficitMax`, which the gate has been reporting all along.

This is the same shape of defect as the flat youth scholarship and the flat staff role bands, both
already fixed by giving them a tier dimension. **It is not in any R2 brief and is nobody's action
yet.**

**Still to do, and deliberately not done here:** regenerate the bottom two tiers (band 3 reaches no
existing NPC club until then), `app:worldpack:maintain`, and hand back
`GET /api/game-config?country=EN`, `GET /api/starter-config` and a world export from a box whose
inputs are dev's. None of those is meaningful against the local box above.

## Superseded 2026-11-18 by the entry above — the derivation record

**Kept for the reasoning, not the figures. The authored figures are R2's, above:** turnover
£331m · £114m · £34.6m · £20.7m · £7.32m · £2.69m · £686k · £215k, tier-1 payout base
**£85,166,300**, both ladders `[1000000, 344411, 104532, 62538, 22115, 8127, 2073, 650]`.

⚠️ **The tables in the section below are candidate C's, which R2 replaced** — C was the derivation's
first answer and failed `app:economy:wage-table` check 1 at tiers 2, 6 and 8. Nothing below is an
instruction; read it as the record of how the dials were derived and what was ruled out. This
header replaces a warning label that left both sets of numbers standing in one file
(review, 2026-11-18).


**The next economy deliverable here is authoring, not code**, and it is specified end to end in
`wunderkind-app/../docs/economy-loose-ends/candidate-c-rollout.md` (outside both repos, beside
`REMAINING-WORK.md` §2, which carries the derivation and the tooling that reproduces it). The
implementation prompt is `candidate-c-authoring-prompt.md` beside them.

⚠️ **ONE DECISION BLOCKS IT, and it is this repo's gate that says so.** The candidate's turnover
ladder **fails `app:economy:wage-table` check 1 at tiers 2, 6 and 8** — `S_tier` is derived from the
turnover row, so the ladder and `starter_config.league_ability_ranges` decide jointly whether it
falls, and **no turnover ladder satisfies both check 1/2 and the app gate's 85-110% COVERAGE band**
while the EN bands flatten at the bottom (`10-25` → `10-15` → `5-10` → `2-7`) faster than the
grounds' income does. Lowering `economyWageCurveExponentBp` is ruled out: it cannot close tier 7→8
even at k = 1.0. Either the bottom bands are re-authored (tier 6 → 10–20, tier 8 → 4–9 — a
squad-quality change that also moves staff and scout pay, since `TierBandScaleResolver` reads those
midpoints) or tiers 6 and 8 stay out of the coverage band on a chain-maximal ladder (£331m, £114m,
£34.6m, £20.7m, £7.32m, £2.69m, £686k, £215k — verified PASS, check 1 clean, no tier insolvent).
✅ **The owner ruled on 2026-11-18: the second (R2).** The implementation brief, self-contained with
R2's tables substituted throughout, is `r2-economy-authoring-brief.md` beside the other two; the
ability-band question stays open as a BALANCE decision, not an economy one. Checks 1 and 2 are
correct and must not be relaxed: tiers 5/6 overlap at 10–15 and 7/8 at 5–7, so "a promoted player
takes a pay cut" would be real.

What it asks for: a new EN turnover table (tier 1 £388.7m → £288m, tier 8 £164,852 → **£361,000**,
spread 2,357× → 798×); the prize and position ppm ladders re-derived onto it (tier-1 base
£100,000,000 → **£74,102,400**, ladder `[1000000, 345486, 104514, 62500, 22083, 13264, 2069,
1253]`); band 3 of `npcFacilityLevelRanges` set to stands `0–1` and **nine exclusions**; then
regenerate the bottom two tiers, `WORLD_PACK_VERSION` 9 → 10 and re-warm.

Four things to know before touching it:

- ⚠️ **`5dc3f51b` — the prize ladder, landed 2026-11-17 — takes the app's variant gate from PASS to
  FAIL on today's other inputs.** Measured 2026-11-18, not predicted: tier 8 insolvent in season 1
  (bill 100.7% of modelled income) because the fix removed 118 points of income while upkeep stayed
  put, and tiers 2 and 3 fall OUT of the 85–110% coverage band they were inside. The facility
  exclusion is what repairs it. Not an argument for reverting; an argument for finishing.
- ⚠️ **The ppm ladder authored by that commit IS the current turnover ladder normalised to tier 1**,
  so re-authoring turnover silently invalidates it. They move together or not at all.
- **`wageScaleMicroPence` and the youth scholarship need no authoring** —
  `EconomyScaleResolver::wageScaleMicroPence()` derives the scale from the turnover row, so step 1
  moves both. Expect `EconomyAdminController::buildPreview()` to warn three times ("Tier N pays MORE
  per unit of ability than tier N−1", at tiers 2, 6 and 8): correct and deliberate, because the new
  turnover ladder is far flatter than the ability bands it is divided by. Average authored wage per
  player still falls monotonically, £144,482/wk at tier 1 to £277/wk at tier 8.
- ⚠️ **`npcFacilityLevelRanges` is keyed by BAND (tiers 7–8 together) and has no country
  dimension**, so band 3 is every 8-tier country's bottom two divisions, and the candidate was
  re-solved in that form. The one input nobody has is what the column holds TODAY: it is not
  published to the app, and the Nov-15 world export disagrees with
  `NpcClubGenerationService::FACILITY_LEVELS`' own band-3 defaults (4.89 total stand levels against
  a `0–1` band that would give 2.0). Either overrides are authored or it is the NPC upgrade drift
  §13.3 describes — **read `/admin?routeName=admin_npc_clubs_content` before editing it.**

## Fixed 2026-11-18 — the staff fix below had skipped scouts

Reported from a fresh tier-8 Workington save, the day after the staff fix deployed: staff wages
still £4,308/wk against £443 of players. The screenshot was decisive — twelve coaches were
correctly scaled at £13-266 and rated 2-7, and **one scout was £2,230/wk, rated 33**.

`ScoutGenerationService::build()` rolled experience from a single global range (4-34 on dev)
with no tier input, exactly the defect staff ability had. It was missed because the staff fix
followed `StaffRole` through `staffWeeklyPence()`, and a `Scout` is a separate entity that never
goes near either. The owner's ruling, now in `tribal-knowledge.md`: **"staff" means every staff
type and scouts.**

**The £2,230 was never a backend figure.** `buildScoutSnapshot()` deliberately ships no wage,
and the backend's own formula prices experience 33 at £39.85. The app computed it:
`ClubWageScaleService` divides a club's wage reservation across its staff in proportion to
ability, so a rating-33 scout among rating-2-7 coaches takes most of the budget. A wrong
ability is a wrong wage in the app even when every backend wage is right — which is why the fix
is to band the experience, not to touch a wage.

Scout experience now takes the club's tier band at all three per-club call sites. EN after the
fix: 44-88 at tier 1, 15-35 at tier 4, 2-7 at tier 8. `WORLD_PACK_VERSION` 8 → 9, because
`buildScoutSnapshot()` bakes `experience` and its derived `tier` into cached packs.

**Two wrong theories were raised and withdrawn on the way, and both are worth not repeating:**
that the game had claimed a stale pre-fix set (impossible — the claim filters on
`payload_version`), and that the app was replaying a cached starter pack (it is account-scoped
and does survive a new game, but was not the cause here). The screenshot of the actual staff
list settled in one look what three rounds of reasoning from totals had not. **Ask for the
per-row breakdown before theorising from an aggregate.**

## Fixed 2026-11-17 — staff cost more than the players they coached

Reported from a tier-8 Clydebank save: **staff −£1,000/week against −£165/week for the entire
squad**. Two independent causes, both pre-existing and neither created by the set work — it
merely made them visible.

1. **Staff ability came from one global range** (`PoolConfig::$coachAbilityMin/Max`, 4-75 on
   dev) regardless of tier, so a local club could field a world-class manager — and ability
   drives `staffMultiplier`, 2.0 up to 12.0, so that alone was a 6x swing in pay. Players never
   had this problem; their band has always been per-tier. `StaffGenerationService::build()` now
   takes the club's band, and every per-club caller passes the SAME array its players came from.
2. **Legacy staff pay had no tier dimension at all.** The role bands are single absolutes, so
   they can only be right at one tier, while a legacy player's wage scales with a per-tier
   ability. `staffWeeklyPence()` now scales the band by `Economy\TierBandScaleResolver` —
   `midpoint(tier) / midpoint(tier 1)` over the already-authored `league_ability_ranges`, which
   is the only tier ladder that exists under `legacy` and is the same axis players scale on.

EN after the fix: staff average £1,116/wk at tier 1, £240 at tier 4, £18.88 at tier 8; a
founder's staff total £233/wk against £4,852/wk of players.

**`TierBandScaleResolver` exists because `EconomyScaleResolver` cannot be used here** — it
resolves from authored turnover and returns `isResolvable === false` on every deployed box, so
everything built on it is inert under the model every box is actually running.

Three things to carry forward:

- **The scaling is applied to the BAND, never inside `legacyStaffPence()`**, which
  `WageModelLegacyParityTest` pins byte-for-byte. That test passes unmodified, which is the
  proof `legacy` still means what it meant.
- **`WORLD_PACK_VERSION` went 7 → 8** in the same commit, because `buildStaffSnapshot()` bakes
  `weeklySalary` into every pack. Every set became unclaimable at once; `app:worldpack:maintain`
  restocks live countries without an operator.
- **The kill switch ships ON**, against this repo's ship-inert habit, because the old behaviour
  is wrong rather than untuned — and it stays a column because the figure is cached.

**Still open:** the overall staff SHARE of a wage bill is a separate calibration question this
does not answer. At tier 1 staff now come to ~2.4% of a player's wage and at tier 8 ~6.6%, so
the ladder is sane and slightly favours the bottom, but whether those shares are right is the
turnover model's business (`economyStaffWageShareBp`), not legacy's.

## Landed 2026-11-17 — world packs become single-use SETS

**Every world a player is given is now unique, and it can no longer exist without a squad.**
A `WorldPackSet` is one starter squad plus a payload per league tier, generated together,
claimed by exactly one club at `POST /api/initialize/starter`, and never served twice. Each
live country keeps **two** claimable sets (`StarterConfig::$worldPackSetTarget`); every game
initialisation claims one and queues a replacement, with `app:worldpack:maintain` sweeping
hourly as a backstop.

What it fixes, both of which were real rather than theoretical:

- `country_world_pack_cache` was keyed `(country, tier)` with no owner, so every player in a
  country got the byte-identical world.
- The starter squad was a separate live draw that could fail `412` against a country whose
  packs were perfectly warm — a gap between "the world exists" and "a squad to play it with
  exists", at the first step of game init, with no client-side escape.

**No API shape changed, and `WORLD_PACK_VERSION` stayed 7 for the set work itself** (it moved to 8 later the same day for the staff-pay fix below). Two values moved under unchanged
keys (packs are now per-device-distinct, and a player's own tier's `fixtures` no longer carries
their club id); both are logged in `shared/api-backwards-compatibility.md` with their consumer
checks, the second one definitive — the app types that field as not consumed and generates its
own schedule. The compatibility property the whole change rests on is the **fallback**: a
country with no stock behaves exactly as before, live draw and an uncached inline tier build.
No new error codes, deliberately.

Three things to know before working on it:

- **`WorldPackCacheService` is entirely set-scoped and the old `(country, tier)` lookups are
  deleted, not deprecated.** `findOneBy(['country','tier'])` would return an arbitrary one of a
  country's sets, and the damage would be silent — tier 1 of one world and tier 2 of another,
  every response a 200.
- **Top-ups do not go through `WorldPackGenerationRun`.** That table is single-flight per
  country and a top-up fires on every init, so they build inline on their own `worldpackset`
  transport instead. Guards: a dispatch-side gate, a deficit re-read on drain, and a
  per-country `pg_try_advisory_lock`.
- **`app:worldpack:warm` now means "rebuild this country's buffer"** and `--tier` is a hard
  error: a set has to be whole to be servable. `app:worldpack:topup` adds one without clearing.

**And the squad is GENERATED, not drawn from the pool** — the change that removed the pool
from this picture entirely. `StarterPackService::generatePack()` builds the founder's players,
staff and scouts through `WorldPackClubGenerationService`, the same generator every NPC club
goes through, so a set consumes nothing (verified: two full EN sets left `player`/`staff`
counts unchanged) and can never be short. `InsufficientStarterPoolException` and the
`MIN_POOL_SIZE` 412 are both unreachable — **a new game can no longer be refused for a thin
pool**, which was the one failure in that flow with no client-side escape.

**One deliberate balance change rides with it, and it is not gated on the wage model.** The
tier band is a POTENTIAL range (`PlayerGenerationService::generate()`'s parameter is
`$potentialRange`; ability is derived below it). The old draw filtered on `currentAbility`, so a
drawn founder had its ABILITY in the band while its opponents had their POTENTIAL in it —
founders were systematically stronger than the tier they were placed in. EN tier 8 now reads
starter ability 1-3-5 / potential 2-7 against NPC ability 1-3-6 / potential 2-7. Logged in
`shared/api-backwards-compatibility.md`.

**Economy coverage closed at the same time.** `Economy\ServeTimePlayerPricer` now owns the
"shared-pool rows are priced at serve time" rule, which previously lived inside
`PlayerBrowseSerializer` and so reached only the two endpoints using it —
`GET /api/players/youth` serialized through `PlayerSnapshotSerializer` and served the stored
legacy figure. With the founder's squad priced at generation and youth priced at serve, no
player surface is outside the model.

**Still open — and the aim is now wrong rather than the code:**
`StarterPackDiagnosticService`, `StarterPoolAuditService`, `app:starter-pack:check` and
`app:starter-pool:audit` all turn on "can a starter pack be drawn for this country", which is
now always yes. They were **deliberately not deleted**: they are the only SCHEDULED thing that
tops the shared pool up at all (`app:pool:warm` is the general warmer and remains unscheduled),
and the pool still feeds youth, foreign, scout search and market data. Deleting them would have
quietly starved those four. Their floors and triggers need re-deriving from what actually
consumes the pool now. Until then they over-queue rather than under-, which is the safe
direction.

## Shipped inert, 2026-11-12 — the economy overhaul

**Deployed is not live.** Eight migrations landed (`Version20261112160000` through `...210000`)
with every country factor at parity, the wage model on `legacy`, inflation at 0, the turnover
table unauthored and the season-investment ceiling at 0. The deploy was a provable no-op —
verified against a full clone of the dev dataset each step. Turning it on is an operator
procedure: **`docs/deploy/economy-rollout.md`**.

What exists now:

- **`EconomyScaleResolver`** — the ONLY place country and tier compose into money. Integer basis
  points, one division at the end, one `pow()` for the ability curve. Raw row indexing: tier *t*
  reads row *t*, which reversed the old `scaleTierToEight()` stretching (removed).
- **`WageModelResolver`** — replaced FOUR copies of the wage arithmetic, one of which
  (`GenerateMarketDataCommand`) ignored both the live config and the tier multipliers and priced
  players two orders of magnitude low, and left every scout it made on wage 0.
  `legacy` is a supported mode that must stay byte-identical; `WageModelLegacyParityTest` holds
  the pre-unification expressions independently and must never be "refactored" to call the
  resolver.
- **Two populations, two treatments** — the distinction that governs everything: per-country
  world-pack rows are priced at generation and stored (hence the `WORLD_PACK_VERSION` bump at
  flip time), while shared-pool rows are one row served to every country and are priced at
  **serve time**.
- **`LeagueSizeResolver`** — the TV pool's divisor, authored rather than counted, because a
  `League` row is a template shared by every save (England tier 8 had **147 player clubs**
  pointing at one row).
- `app:economy:wage-table` — the gate, five checks, exits non-zero. It has already caught two
  real defects in this work.
- `GET/POST /api/club/season-investment`, World → Economy admin screen, and the
  parachute passthrough. ⚠️ **"Verbatim" was struck 2026-11-20** — `c11c7f2d` moved resolution into
  `LeaguePayoutResolver::parachuteFor()`, which scales the one documented `perSeasonPence` key by
  the country factor and passes every other key through. See `03_data/output/entities.md`.

**Fixed 2026-11-12, then fixed properly 2026-11-13:** the youth scholarship was wrong at BOTH
ends of the turnover ladder. The flat £250 was 1.97x an average tier-8 wage *and* 0.19% of an
average tier-1 wage — a single absolute figure cannot be right across a 1,047x spread.

Pass 1 (`economyYouthScholarshipCapBp`, ships ON at 50% — the one switch in this series that
does) capped the end that inverted and therefore never looked at tier 1. Pass 2
(`economyYouthScholarshipTier1Pence` + `…FloorPence`, both default 0 = off) makes it an anchored
ladder: author what a scholar costs at tier 1, every lower tier is the same proportion of its own
average wage, floored so it does not decay to £1.43, capped last so the invariant always wins.
The cap's anchor moved from the tier's WORST wage to its AVERAGE, because tier 8's cheapest
professional earns £12.84/wk and half of that is a free scholar, not a cheap one. Still a
provable no-op: everything applies only under `turnover`.

**Found by running the gate, not by reading it** — which also turned up a defect in the gate
itself, reporting a scholarship problem that no authoring action could clear. See
`docs/economy-threshold-review.md` §2 and §2b.

**Calibrated on dev 2026-11-13, model still OFF.** The turnover ladder and the scholarship anchor
are authored (both gated on `economyWageModel`, so no price moved — verified by byte-identical
checksums on player, staff, scout, npc_club and all 200 pack payloads). The TV table was
re-authored across all 196 leagues, and **that one DID move a published value**: `league.tv_deal`
is a stored absolute with no model gate, and the old figures were 102%-3,674% of a club's season
turnover. Tested on a real device against the shipped app afterwards: the economics are
noticeably more stable, which is direct evidence the old TV figures were breaking client finances
rather than merely failing a gate.

Two things came out of it. **`league.tv_deal` is the one money figure the country factor never
reaches** — now guarded by the gate's check 6 (`c6fba41`), because check 3's 100%-of-turnover
bound cannot see a country at twice its intended share. And **cached packs bake `tvDeal` while the
version gate only moves when code does**, so 60 of 200 packs are silently stale; a rebuild is with
the operator. The rollout doc's own derivation method was also wrong — it specified a geometric
turnover ladder, which fails checks 1 and 2 against real bands; `S_tier` is the right primitive
and makes both checks structural.

**Also fixed 2026-11-12:** the sponsor bands scale by country, so a cheap country is cheap on
both sides of the ledger rather than earning full English sponsorship on a fifth of the wages.
Country factor ONLY, not country x tier — a league's tier variation is already expressed by its
`sponsorCount` and size mix. It collapsed a FIFTH byte-identical duplication on the way.

**Still open:** `bankruptcyDeductionTiers` has two rows that cannot fire under the resolution its
own docblock documents, and its ladder is traversed in under half a week at the top five tiers.
That is a client-side mechanic gated by its own flag, and it predates this work — see
**`docs/economy-threshold-review.md`** for the analysis and the weeks-of-wages proposal.


## In flight, 2026-11-11 → 2026-11-12 — taking world content off the deploy path

One theme across both days: **everything a new country needs, authored from the admin instead
of from a PR.** Adding Ghana was the forcing case — it exposed that a country the place
registry has never heard of could not be seeded at all.

- **Club-naming content** (`864a775` and after) — `prestige_suffixes`, `generic_suffixes`,
  `stadium_formats` on `country_profile`, with an AI suggest. Clears the CONTENT readiness
  blocker, which used to name a 2,364-line PHP file.
- **Ability ranges** — `StarterAbilityRangeService` behind a dashboard button. Fixing this
  surfaced a live bug: the Starter config save wrote the whole `league_ability_ranges` column
  from a grid built only of countries that already have `League` rows, **deleting every
  unrendered country's band on any save**. It merges now.
- **Country profile enrichment** — the `country_profile` row's own fields (bbox, land band,
  reference codes), not just its towns.
- **Town pull for a country with no registry entry** — `resolveCountry()` →
  `resolveAnyCountry()` + `ensureCountryProfile()`, and a prompt that asks for the biggest
  places first when nothing is held rather than for "further" towns beyond an empty list.
- **Player and staff name pools** (2026-11-12) — two json columns, an authoring card and an
  AI top-up, plus the crash they exposed: `generateName()` threw `Unknown nationality` where
  the four sibling generators fell back, so a full `app:pool:warm` reported 39 failures on a
  run that had generated 500 players per country successfully.

**What is left of the PHP-only world data:** places, world region, suffix order and business
cluster in `CountryContentRegistry`. The name pools and the three naming lists have both moved
to a DB-override-over-const layer; nothing was migrated, and the consts remain the fallback so
a fresh database can still name people and clubs.

## Deployed 2026-11-02 — the bracket gap is CLOSED

The two commits this section recorded as waiting are **live on `dev` and `master`**, pushed
2026-11-02 23:51 and deployed in runs `41548564308` (dev) and `41548582109` (prod), 2
migrations executed on each:

- `85372c4` — a competition entry now outlives its club
  (`competition_entrant.club_id` nullable / ON DELETE SET NULL,
  `Version20261102140000`), plus `serializeFixtureSide()` repairing brackets already damaged.
  The app half was already out, so a finished cup reading "To be decided" for every opponent
  was that gap rather than a client regression. **Resolved** — do not re-diagnose it as one.
- `bb607f3` — `app:clubs:purge-spoof` removed from both deploy workflows. Purging is now an
  operator action via the admin's "Clear Spoof Clubs".

There was no sequencing hazard in either direction: the client degraded quietly while the
backend was behind, and resolves now the migration has landed. Full writeups in
`03_data/output/migrations.md` and `04_interfaces/output/services.md`.

Two landing-page features shipped in the same push and are gated on real data, so **both stay
invisible in production until a cup exists** — that is the designed empty state, not a broken
deploy:

- `cbdcaf6` — the `#cup-results` board (`CupResultsBoardService`), hidden with its nav link
  until a cup has a resolved final.
- `2f54476` — the CUPS band in `#telemetry` (`CupTelemetryService`) plus the Table Turnover
  ▲/▼ split (`Version20261102160000`) and `CUP // WINNER` feed lines. The band appears as soon
  as a cup is live or open, which is sooner than the board.

**Remember `wunderkind_test` is not migration-tracked** — see
`01_overview/output/environment.md` before running the suite against this work.

From `.context/stages/02_architecture/output/git-activity.md`'s
`git log --oneline -30` / hotspot analysis:

1. **"Phase 1" Competition framework rollout** — entities → eligibility/
   registration API → round processing/match engines/cron provisioning,
   landing in sequence over recent commits. This is the single biggest
   active area of the codebase right now.
2. **Admin panel polish** — ongoing crash fixes, click-through fixes,
   and menu-ordering changes across `Controller/Admin/*CrudController.php`
   and `templates/admin/*`, paired consistently with test changes.
3. **Match-engine/notification depth pass on Competition (2026-09-19)**
   — `DeterministicEngine` fully replaced with a port of
   `wunderkind-app`'s `ResultsEngine.ts`, plus extra-time/penalty-shootout
   resolution so a knockout fixture can never end level; a new
   `MATCH_NARRATIVE` chain-graph content system
   (`MatchNarrativeGeneratorService`) generates the full match commentary
   server-side; and a first-time push-notification subsystem (FCM via
   `kreait/firebase-bundle`, Symfony Messenger — the first use of
   Messenger in this codebase) fires OS notifications for competition
   round-draws/new-registrants/individual match results and, optionally,
   admin broadcasts. See
   `.context/stages/04_interfaces/output/push-notifications.md`,
   `docs/api/competition-registration-snapshot-v2.md`.

4. **Platform security upgrade (2026-10-21)** — a dependency/runtime pass rather
   than a feature one, and the reason the stack's version numbers moved. PHP
   8.4 -> 8.5 (deployed pinned at an exact `php:8.5.12-fpm-alpine`; local Lando
   capped at 8.5.7 by Lando's own image family) for the 2026-10-21 Core
   advisories, then Symfony 8.0.* -> 8.1.* plus EasyAdmin 5.0->5.7, Twig
   3.23->3.30 and API Platform 4.2->4.4 to clear 43 Composer advisories down to
   zero. Touched almost no application logic — the real work was four
   compatibility breaks the upgrade exposed (PHP 8.5 static opcache failing the
   image build, `ReflectionMethod::setAccessible()` deprecation, Symfony 8.1's
   `runCommand()` trait collision killing the whole suite, and EasyAdmin moving
   `ea-clickable-row` to client-side JS). See `.context/shared/stack.md`'s
   dependency-floor bullet, `01_overview/output/environment.md`'s "Keeping PHP
   and dependencies patched", and root `CLAUDE.md`'s Testing section.

5. **First JSON exposure of the live telemetry aggregate (2026-10-21)** —
   `GET /api/telemetry` (`Api\TelemetryController`,
   `docs/api/live-telemetry.md`). Notable less as a feature than as a pattern:
   the data, its generator command and its 15-min cron had existed for a while
   with **no endpoint at all**, because `LandingController` renders it
   server-side into HTML — so "the service exists" did not imply "the app can
   read it". The endpoint projects rather than dumps the snapshot, stripping
   three stored keys (a persisted `DateTimeImmutable`, a generation-time
   relative string, and the web-only dossier bundle). Same pass suppressed the
   container's version-disclosure headers. Expect more of this shape as the app
   grows: landing-page-only aggregates needing a client contract, where the
   stored shape is not the servable shape.

6. **Authorization contracts the app had to infer from a status code
   (2026-11-01)** — a lockout fix (`ROLE_CLUB` 403s, commit `5333732`,
   `docs/api/role-club-403.md`), and like item 5 it is more interesting as a
   pattern than as a feature. Three separate faults all presented to the app as
   one bare `403`, which it could only read as "this account is refused": a
   role resolved from a column that had held two obsolete values, an endpoint
   whose own 404 was shadowed by its role gate, and a resolver exception that
   is deliberately a 403 everywhere except the one endpoint asking whether a
   thing exists. None of them were visible from outside without a token, and
   the suite had no coverage of the *negative* case — an account that is
   authenticated but not yet entitled — so all three shipped.

   What to expect more of: this API's authorization surface is now broad
   (14 `roles: ROLE_CLUB` rules in `access_control` and 30 `#[IsGranted(
   'ROLE_CLUB')]` attributes in `src/`, counted 2026-11-01) and a single status
   code carries the whole story to a client. Those two layers overlapping is
   itself the hazard — most endpoints are gated twice, so a gate relaxed in one
   place is not relaxed. The generalisable rule from this pass is that **an endpoint whose
   purpose is to answer about absence must not be gated on the thing being
   absent** — and that any such endpoint needs a test fixture representing the
   not-yet-entitled caller, not just the happy-path owner. The two new test
   files (`tests/Entity/UserRolesTest.php`,
   `tests/Controller/Api/ClubCheckAccessTest.php`) store no roles at all for
   exactly that reason. Reported by the app team rather than found here, via
   `wunderkind-app/docs/backend-notes/`, which is becoming the normal inbound
   channel for this class of issue.

Clusters 1-2 are corroborated by migration history
(`.context/stages/03_data/output/migrations.md`: the large Competition
migration is the most structurally significant recent change) and by
stage 04's routes/services findings (the Competition subsystem is the
newest, most internally-consistent module in the interface layer).
