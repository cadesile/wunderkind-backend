# API backwards compatibility — non-negotiable

**Read this before changing any API response, request DTO, or generated payload.**

## Why this is a hard rule here, not a nicety

**The backend goes live well ahead of the frontend.** The land/location economy needs a large
amount of curated content before the app can use any of it — 865+ towns seeded, AI-enriched one
billed call at a time, then a human review pass over every land valuation. That work is measured
in weeks. Throughout it, a **shipped app build is older than the deployed backend**, and dev is
where everything is proven first.

So the deployed API must keep working for a client that predates whatever you just added. Not
"probably works" — **proven**, with a diff and a test.

## The rule

Every API change is **additive**. You may add keys, add optional request fields, and add
endpoints. You may not remove a key, rename one, make a request field required, or change what
an existing key means.

> **A changed VALUE under an unchanged key is a breaking change too.** `/api/world/overview`
> once returned `startingBalancePence` at 100× the true figure. Fixing it was correct, but it
> was a breaking change to that endpoint and had to be checked for consumers before shipping
> (it had exactly one, server-side, so it was safe).

## Two surfaces are frozen

Treat these as closed unless you are deliberately versioning them:

| Surface | Why |
|---|---|
| Cached world-pack club snapshot — `WorldPackSnapshotBuilder::buildClubSnapshot()` | Gated by `WorldInitializationService::WORLD_PACK_VERSION`. Adding a key forces a bump, which invalidates **every** country's cache and makes the next client request per country/tier rebuild it (~2–4s). That constant's own docblock is a post-mortem of forgetting: a shape change shipped without a bump left "84 of 85 rows on the production restore" serving a retired shape indefinitely. |
| Sync response — `SyncService::buildLeagueSnapshot()` and friends | The hot path every save goes through, and a second, divergent NPC club shape. Nothing should be added here speculatively. |

**If a new field is needed, put it on an uncached endpoint instead.** That is why the NPC
club → location link surfaced on `GET /api/club/foreign`, `GET /api/scout/foreign-clubs` and
`GET /api/locations/{id}`, and deliberately **not** in the world pack.

## Checklist for any API-touching change

1. **Diff for removals and alterations, not just additions.**

   ```bash
   git diff <last-release> -- src/Controller/Api src/Service/SyncService.php \
       src/Service/WorldPackSnapshotBuilder.php src/Repository/NpcClubRepository.php \
     | grep -E "^-" | grep -v "^---" | grep -E "=>|'"
   ```

   Added lines are safe. Every removed or altered line needs a consumer check.

2. **New request fields are optional, always.** `locationId` on
   `POST /api/club/initialize` is the pattern: omit it and the behaviour is byte-for-byte what it
   was before.

3. **Explicit column lists are invisible to new entity fields.**
   `NpcClubRepository::findForeignClubs()` is raw DBAL with a hardcoded `$columns` string — a new
   mapped property does **not** appear there until it is named. This was missed once and surfaced
   only as a PHPUnit warning (`Undefined array key "location_id"`), not a failure.

4. **A new unique index must be PARTIAL where legacy rows would violate it.**
   `uq_club_name_per_location` is partial on `(home_location_id IS NOT NULL)` precisely so clubs
   founded before the land economy stay free to share a name. There is a test asserting exactly
   that, and if it ever starts failing, every existing save is at risk.

5. **A new NOT NULL column needs a backfill in `postUp()`**, and the test database needs the same
   treatment by hand — `doctrine:schema:create` (the README's setup path) does not run migrations,
   so an existing test DB needs the column added nullable, backfilled, then tightened.

6. **New error codes are a client-visible change.** An old build will not recognise
   `409 town_sold_out`. Say so in the handover; do not assume it degrades gracefully.

## Prove it with a test

`tests/Controller/Api/LegacyNewGameFlowTest.php` is the guard and the pattern: it drives the
new-game flow using **old payloads only** — no new fields, no new parameters — and asserts the
keys an old client reads are still present and still mean the same thing.

**Extend that file rather than starting a new one.** Three of its assertions are load-bearing and
worth copying the style of:

- a club can still be created with no location fields, and ends up with `home_location_id` null;
- creating a club does **not** touch account credit (land is a separate, opt-in purchase);
- two clubs with no plot can still share a name — the partial-index guard from point 4.

## The log of deliberate breaks

Additive is the rule; a break is allowed only when it is **recorded here with its consumer
check**. The point of the log is that "nothing consumed it" is a claim somebody can audit later,
not a thing you assert once and forget.

| Date | Change | Consumer check |
|---|---|---|
| 2026-11-04 | `GET /api/locations`, `/facets`, `/{id}` moved from `PUBLIC_ACCESS` to `IS_AUTHENTICATED_FULLY`. An unauthenticated caller now gets `401`. | No shipped build calls them — the whole locations surface postdates the last app release. Requested directly by the product owner: the catalogue is unreleased curated content, and the detail read was the doorway to a billed AI call. |
| 2026-11-04 | `GET /api/locations/{id}` no longer queues enrichment as a side effect. Response shape unchanged; the write is gone. Replaced by `POST /api/locations/{id}/enrich`. | Same: no shipped consumer. A GET that spends money on a metered external API is the defect being removed, so leaving it in place additively was not an option. |
| 2026-11-12 | `POST /api/club/fund` → `projection.facilityUpkeepPerWeekPence` and `projection.facilityUpkeepPence` rise exactly **50%**. Shape unchanged; `FacilityCostCalculator::weeklyUpkeep()` moved from `base × 1.5^(level-1)` to `base × 1.5^level`, aligning the server to the charge the app makes. | **Server-side, exhaustive:** `weeklyUpkeep()` has exactly one caller, `ClubFundingService::facilityUpkeepPerWeekPence()`, and its result reaches exactly two keys, both inside the `projection` block of `POST /api/club/fund`. Nothing gates, caps or refuses on either — the stake floor is `starterConfig.clubFundingMinimumPence`, which this does not feed, and `ClubFundingService`'s own docblock says the projection is "returned for the UI to warn with rather than to refuse on". `ClubValuationService` and `ClubFacilityBuildService` use `costBetween()`/`costToReach()`, not this method, so no *charged* price moves. **Client-side is not verified from this repo** (see "What backwards compatible does and does not cover"); the change was made because the app is the game engine and the server's figure was 1.5× under what it charges, which understates a running cost in the direction that lets a player underfund a club. Shipped on its own, not batched with the economy scaffolding, so the value change is attributable. |
| 2026-11-15 | `GET /api/game-config?country=` and the sync `gameConfig` block → `economyTierBaseline.tiers[].ticketPricePence` **keeps its name, type and position and changes its source**: it was the raw `economyTicketPricePerTierPence` row and is now the figure `TicketPriceResolver` resolves — the authored per-tier price as an override, else `economyTicketPriceTier1Pence` × that tier's `economyTicketPriceTierRatioBp`, with the country factor applied once, last. The practical change for a box that HAS authored the per-tier column is that the published price is now country-scaled where it previously was not. | **Server-side, exhaustive:** nothing in this repo consumes the key — `grep` finds it only in `EconomyConfigPublisher::tierBaseline()` (the one write), its tests, and the two docs. Nothing gates, caps or refuses on it. **No released client reads it either**: the key postdates the last app release, and the app still starts every club at a compiled-in 2500p — making the app read this is `02-frontend.md`'s F1, in the same pass. **On every deployed box the published value is unchanged**, twice over: the per-tier price column is unauthored (eight zeros) and every `country_profile.economy_factor_bp` is at parity, so both the override path and the factor are no-ops. Verified before/after by hashing `clientBlock()` on a default-config box: all five argument combinations byte-identical. The value can only move once an operator authors a tier-1 base, a ratio, or a country factor — each an admin action with a form in front of it. |
| 2026-11-17 | World pack **sets**. `POST /api/initialize/starter` now claims a pre-generated set (starter squad + every tier, built as one unit) instead of drawing the squad live, and `POST /api/initialize/league/{tier}` reads that club's claimed set. **Two value changes under unchanged keys, no shape change on any of the three `/api/initialize/*` endpoints.** (1) Every device in a country used to receive the byte-identical pack; each now receives a distinct one — that is the feature. (2) The `fixtures` array for a player's OWN tier no longer contains their club's id: `buildTierPack()` added it when a club was passed, a pre-generated set has no club, and the async assembly path never added it either. | **Uniqueness:** `data` is written to the device's local SQLite and never compared across devices; the only cross-device equality this repo relies on is `NpcClub` ids, which are shared rows and identical across sets. Nothing server-side reads a pack back. **Fixtures:** exhaustive and definitive — `wunderkind-app` types the field as `fixtures: unknown; // delivered by backend, not consumed client-side (generated locally)` (`src/api/endpoints/initialize.ts`) and generates its own schedule in `useInitFlow`'s `fixtures_generated` step from the league list plus its own `ampLeagueId`/`ampClubId`; `data.fixtures` is read nowhere in that repo. The divergence ALREADY existed for every admin-regenerated pack, so this makes the two generation paths agree rather than introducing a difference. **No new error codes, and that is load-bearing:** a country with no stock falls back to the old live draw and an inline uncached tier build, keeping the old `412`/`409` behaviour, because the init flow has no client-side escape and a shipped build cannot be taught a new refusal. Guarded by three new cases in `LegacyNewGameFlowTest`, which previously did not reach `/api/initialize/*` at all. `WORLD_PACK_VERSION` stays 7 — the payload shape is untouched. |
| 2026-11-17 | **The starter squad is GENERATED, not drawn from the shared pool.** `POST /api/initialize/starter` builds the founder's players, staff and scouts through `WorldPackClubGenerationService` — the same generator every NPC club goes through — instead of querying `player`/`staff` by band, position, age and nationality and deleting what it took. **Shape unchanged. Two value changes under unchanged keys, and the first is NOT inert.** (1) **A founder's squad is weaker, and deliberately so.** The draw filtered on `currentAbility BETWEEN band`, so a drawn starter player had its ABILITY in the tier band while every NPC club around it had its POTENTIAL in that band — two bases for one number, which made a founder systematically stronger than the division it was placed in. Measured on EN tier 8 after the change: the starter squad reads ability 1-3-5 / potential 2-7 against its NPC opponents' ability 1-3-6 / potential 2-7, i.e. identical. This takes effect immediately, under `legacy` too, because it is a generation basis and not a wage model. (2) `contractValue` on those players is now priced with the club's country and tier like an NPC club's, where a pool-drawn starter player was priced with neither; inert until `economyWageModel` is `turnover`. | **Server-side, exhaustive:** nothing reads a starter pack back — it is written to `club.starter_pack_json` and returned once. **Client-side, the squad is the point of the endpoint**, so (1) is a real gameplay change and is flagged as such rather than claimed safe: it is what makes a founder start level with its division instead of above it, which is the behaviour that was asked for. No key added, removed or re-meant, and **no new error codes — the opposite**: `412 InsufficientStarterPoolException` and the `MIN_POOL_SIZE` 412 are both now UNREACHABLE, because generation has no supply to be short of. A new game can no longer be refused for a thin pool, which was the one failure in this flow with no client-side escape. `WORLD_PACK_VERSION` stays 7: the starter pack is not part of the versioned tier payload. |
| 2026-11-17 | `GET /api/players/youth` → `players[].contractValue` is now re-priced at serve time from the requesting club's country and tier, via the new `Economy\ServeTimePlayerPricer`. It previously served the STORED legacy figure. | A youth player is a shared-pool row, so it is stored legacy and must be priced against the browsing club's economy on the way out — the rule `/api/scout/search` and `/api/players/foreign` have followed since 2026-11-12. This endpoint serializes through `PlayerSnapshotSerializer` rather than `PlayerBrowseSerializer`, so it never reached that pricing: it was the last surface where a pool player escaped the economy. Extracting the rule into its own class is what makes "which surfaces price pool rows" answerable. **Inert on every deployed box** — `economyWageModel` is `legacy`, under which the pricer returns the row untouched, so the published figure is byte-identical until the model is flipped. Guardians and every other key are untouched; only `contractValue` can move. |
| 2026-11-17 | **Staff pay now scales with the club's tier, and staff ability is drawn from the tier band.** Affects `staff[].weeklySalary` and `staff[].coachingAbility` in every world-pack club snapshot and in the starter pack, plus `GET /api/market/data` indirectly (pool staff are unchanged — they have no tier). **Shape unchanged; values move, and NOT inertly.** Two causes were fixed together: ability came from one global range (4-75 on dev) whatever the tier, and the legacy role bands are single absolutes with no tier dimension at all. Measured on EN after the change: staff average £1,116/wk at tier 1, £240 at tier 4, £18.88 at tier 8; a founder's starter staff total £233/wk against £4,852/wk of players, where they had been SIX TIMES the squad. | **This is the fix for a reported defect**, not a silent reprice: a tier-8 Clydebank save showed staff −£1k/wk against −£165/wk of players. **Client-side:** `wunderkind-app/src/utils/staffWages.ts` reads `Staff::$weeklySalary` verbatim and states the backend is the authority, so the app needs no change and will simply show the corrected figure. **Server-side, exhaustive:** nothing refuses or gates on a staff wage — `ClubFundingService`'s projection is explicitly "returned for the UI to warn with rather than to refuse on". **Paired with `WORLD_PACK_VERSION` 7 → 8 in the same commit**, because `buildStaffSnapshot()` bakes the figure into cached packs; without the bump every warm country would have served pre-fix wages indefinitely, which is the failure that constant's docblock records twice. `economyStaffTierScalingEnabled` is a kill switch shipping ON — unusual here, and deliberate: the old behaviour is wrong rather than untuned. `WageModelLegacyParityTest` passes **unmodified**, which is the proof the legacy arithmetic itself is untouched — the scaling is applied to the band before `legacyStaffPence()` sees it. |
| 2026-11-18 | **Scout experience is drawn from the club's tier band.** Affects `scouts[].experience` and the `scouts[].tier` derived from it, in every world-pack club snapshot and in the starter pack. Shape unchanged; values move, and NOT inertly. It was one global range (4-34 on dev) whatever the club, so a tier-8 side got a scout rated 33 beside coaches rated 2-7. Measured on EN after the change: 44-88 at tier 1, 15-35 at tier 4, 2-7 at tier 8. Pool scouts (`GET /api/market/data`) are unchanged — they have no tier. | **The completion of the 2026-11-17 staff fix, which had skipped scouts** because a `Scout` is its own entity and never passes through `staffWeeklyPence()`. **Client-side, this is what actually fixes the reported figure:** the app does not read a scout wage from a pack at all (`buildScoutSnapshot()` ships none) — `ClubWageScaleService` splits the club's wage reservation across staff in proportion to ability, so the rating-33 scout was paid £2,230/wk of a £4,308 bill. With the scout in band the split is even. No app change needed. **Server-side, exhaustive:** nothing gates or refuses on scout experience. Paired with `WORLD_PACK_VERSION` 8 → 9 in the same commit, since the value is baked into cached packs. |
| 2026-11-21 | **A club scout's judgements are banded by tier** (A32b, `WORLD_PACK_VERSION` 14 → 15). Affects `scouts[].judgements` and the `scouts[].ability` derived from them in world-pack clubs and the starter pack. Shape unchanged; values move. Shared-pool scouts unchanged. |
| 2026-11-22 | `GET /api/game-config` and the sync `gameConfig` block gain `economyTierBaseline.countryFactorBp` (England 10000, 10000 on a no-country read) and two top-level keys, `playerTransferOutOfReachTierGap` and `playerTransferWagePremiumPerTier`. **Additive**: an older app ignores them, and their defaults equal what it hardcodes. |
| 2026-11-22 → 2026-11-23 | `POST /api/initialize/league/{tier}` briefly deleted the tier row it served, so a retry was rebuilt from another generation. **Reverted 2026-11-23:** the row is stamped `served_at` and a retry replays the same bytes, as the app's client assumes. New: a claimed set missing the tier answers **410** `{"error":"world_pack_tier_unavailable"}` instead of a rebuilt tier; an older app shows its generic tier-load error. `worldInitializedAt` is set once every tier of the set has been served. |

All of these are behaviour changes to **deployed** endpoints, which is why they are logged even
though the blast radius is zero. The window where that is true is closing: once the app ships against
any of this, the rule above applies with no exceptions.

### The near-miss that is worth copying: the enrichment quota

The per-account valuation cap (2026-11-04) added a ceiling to an endpoint that had none. Done
carelessly that is a break — every request past the ceiling starts failing — so note what made
it additive instead:

- **The new config column's default means "behave as before".**
  `starter_config.max_enrichment_requests_per_user` is `0 = unlimited`, and the migration sets
  the existing row to `0` explicitly. A default of 25 would have been the obvious choice and
  would have silently started refusing requests that worked the day before, as a side effect of
  running migrations. **Zero-means-unlimited is not a quirk; it is the compatibility mechanism.**
  There is a test asserting it (`LocationControllerTest::testACapOfZeroMeansUnlimited`) whose
  docblock says so, because the asymmetry looks like a bug to anyone who finds it later.
- **The new counter defaults to 0 with no backfill**, so no existing account is retroactively
  billed for valuations it already triggered. There was nothing to backfill from in any case —
  enrichment attempts were never attributed to a user.
- **`429 enrichment_quota_exhausted` is a new error code**, which is client-visible per point 6
  below. It is unreachable while the cap is 0, which is how a cap can ship ahead of any client
  that understands the refusal.

The general shape: **when adding a limit to something that was unlimited, the migration's job is
to preserve "unlimited", and turning the limit on is an operator action with a form in front of
it.**

## What "backwards compatible" does and does not cover

It means: **the old request shapes still work, and the old response keys still mean the same
thing.** It does not mean the app has been verified against the change — that lives in
`wunderkind-app`, which is out of scope for work in this repo (see
`stages/01_overview/output/tribal-knowledge.md`). Dev-environment testing closes that gap; say so
plainly in any handover rather than implying end-to-end verification.
