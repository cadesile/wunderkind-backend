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

Both are behaviour changes to **deployed** endpoints, which is why they are logged even though
the blast radius is zero. The window where that is true is closing: once the app ships against
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
