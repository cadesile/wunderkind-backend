# Backend answers to the production Sentry sweep — 24 Oct 2026

Replies to `docs/2026-10-24-sentry-sweep-backend-brief.md`, in the brief's own order, plus
the four open questions at its end. Everything below was read or measured in this repo
rather than inferred; where a number comes from a database it says which one, because the
local database is a `scripts/pull-prod-db.sh` restore and may lag production.

Client-facing contract changes are in `docs/frontend-integration.md` under **Error
responses** and **Retries and idempotency**.

> **Read the round-two section at the bottom before acting on this one.** The frontend reply
> (`docs/2026-10-24-sentry-sweep-frontend-reply.md`) reversed two decisions recorded below —
> conclude-season came **off** the `Idempotency-Key` allowlist, and the `422` for "a season
> the club has not reached" was **removed** — both because the client's outbox drops a 4xx
> rather than retrying it. Round two also bumped `WORLD_PACK_VERSION`. Everything else here
> still stands.

---

## B2 — `POST /api/league/conclude-season`

### 1. Is the non-idempotency claim accurate?

**Yes, and it was worse than the comment said.** `LeagueService::concludeSeason` persisted a
`SeasonRecord` and a `SeasonSnapshot` unconditionally and called
`setCurrentSeason(+1)` on every call. Three consequences the comment did not cover:

- `hallOfFamePoints` is **summed from `SeasonRecord` rows** with `finalPosition = 1`
  (`HallOfFameScoreService`), so a duplicated title counted twice on the leaderboard,
  permanently.
- `season` came from `$club->getCurrentSeason()`, not from the payload, so a late retry
  would have filed the archive under the *wrong season number* as well as duplicating it.
- `SeasonRecord` and `SeasonSnapshot` had no uniqueness constraint at all.

So: the client's decision not to enqueue it was correct, and keeping the guard was the right
call.

### 2. It is idempotent now

Keyed on **club + season**, with three independent guards:

- `uq_season_record_club_season` / `uq_season_snapshot_club_season` — a duplicate archive
  row is impossible even if two requests race past the check.
- The first call's **response body is stored** on `season_snapshot.response_json` and
  replayed byte for byte. Stored rather than recomputed, because concluding is not a pure
  read: it re-rolls league sponsors and emits a fresh fixture list, so a recomputed reply
  would describe a *different* world than the one the first call committed.
- `currentSeason` advances **only** when the season being concluded is the one the club is
  actually on, so a late retry archives without skipping a season forward.

Confirmed none of these happen twice: second `SeasonRecord`, second `SeasonSnapshot`,
sponsor re-roll, second `setCurrentSeason(+1)`. Covered by
`tests/Service/LeagueServiceConcludeSeasonIdempotencyTest.php`, including the awkward
middle case — the first attempt committing the archive and then dying before the pyramid
was built, which leaves a record with no stored body. That rebuilds once, stores the
result, and replays from then on.

**Please send `season` in the payload.** It is a new optional integer: the season the
archive describes. Omitted, it falls back to the club's current season (what every shipped
build does today), which is exactly the case that is unsafe for a *late* retry — "the
current season" is the wrong thing to key on once the request can arrive after the next
season has started. Concluding a season the club has not reached is now `422`.

A side note while you are here: **the sponsor re-roll in the response is not persisted
today**, and never was. `rollLeagueSponsors()` mutates `LeagueSponsor::$rolledValue` on
managed entities and nothing flushes afterwards, so the values exist only in the reply. We
left that behaviour alone — changing it would change the game's economy, not fix a bug —
but it is worth knowing that the numbers you receive are not what the next reader of those
rows will see. `storeConcludeResponse()` deliberately writes with raw SQL rather than a
flush so that adding the new column did not start committing them as a side effect.

### 3. Duration against the 30 s client timeout

Measured against the local prod restore, Germany's full eight-tier pyramid, with a payload
shaped like the real one (8 leagues × 18 clubs of standings, top-10 scorers/assisters/rated
per tier, a 38-fixture season):

| | |
|---|---|
| Request payload | 52.2 KB |
| Response | 65.2 KB |
| First conclude | **65 ms** |
| Replayed retry | **4.9 ms** |

Server time is three orders of magnitude inside the 30 s budget, and PHP-FPM in production
runs on better hardware than Lando. **Sentry `154751485` was transport, not server
slowness** — "Fetch request has been canceled" on a poor connection, with 52 KB still to
upload. The 30 s timeout is generous for the server and about right for the upload; the fix
that matters is the retry queue, not the timeout.

### 4. Go ahead and enqueue it

`POST /api/league/conclude-season` is also on the `Idempotency-Key` allowlist now, so a
retry carrying the same key replays the exact bytes with `X-Idempotent-Replay: true`. That
is the cheap layer; the club+season guarantee above is the durable one and holds with or
without a key — which matters, because a request killed mid-flight never gets as far as
caching a response.

---

## B3 — `POST /api/owner-avatar` returned HTML for a 422

### 1. Reproduced. The field is `dob`

Not `gender` — an absent `gender` is valid, confirmed by test. The cause is that
`Assert\Date` accepts **only** `YYYY-MM-DD`, and rejects an ISO-8601 datetime:

| Payload | Before | Now |
|---|---|---|
| `{"dob": "2008-05-12"}` | 200 | 200 |
| `{"dob": "2008-05-12T00:00:00.000Z"}` | **422** | 200 (time discarded) |
| `{"dob": ""}` | 200, DOB set to **today** | 200, DOB cleared |
| `{"dob": "12/05/2008"}` | 422 | 422 |
| `{"name", "dob", "nationality", "avatar"}`, no `gender` | 200 | 200 |

`"2008-05-12T00:00:00.000Z"` is `Date.toISOString()` output, so if anything in the
onboarding path hands a `Date` to `toOwnerAvatarPatch()` rather than a pre-formatted
`YYYY-MM-DD` string, that is the 422. The endpoint now accepts `YYYY-MM-DD` optionally
followed by a time and reads only the date part — a DOB has no time of day. No client
change is needed either way.

The empty-string row is a second bug found on the way: `""` reached
`new DateTimeImmutable("")`, which is *now*, so clearing the field set the owner's birthday
to the day they signed up. `""` now means "clear it", same as `null`.

`nationality` as a demonym is correct and was never the problem (max 60 chars, free text).
The DTO has not changed since build 121 apart from this widening.

### 2. Every `/api/*` error is JSON now

`App\EventSubscriber\ApiExceptionSubscriber` converts any uncaught exception on an `/api`
path into RFC 9457 `application/problem+json`, regardless of `Accept`. It carries `error`
(a stable machine slug), `detail`, and — the part that was missing entirely —
`violations[]`, one entry per failed field with its `propertyPath`. Violations are logged
server-side too, under `api.client_error` / `api.exception`.

It runs at priority -48, between the security firewall's exception listener (1) and
Symfony's HTML error renderer (-128), so lexik's existing JSON 401 is left untouched and
the HTML renderer never sees an `/api` path. `/admin` keeps its HTML error pages — it is a
browser UI. A `5xx` detail is always the fixed string `"An internal server error
occurred."`; the real message is logged, not returned. Controllers that catch their own
errors are unaffected: those were already JSON and never reached the exception listener.

Shape and guarantees: **Error responses** in `docs/frontend-integration.md`.

### 3. Club registration retry — not idempotent, but it does not block the player

Two findings:

- **It will not 409 or 422 on the player's own name.** The duplicate-name check in
  `ClubInitializationService::initializeClub` is against **`NpcClub` rows only** — it exists
  so the pyramid cannot contain two identically-named clubs. It never looks at other
  `Club` rows, so a half-completed first attempt cannot make the player's own name
  unavailable. The permanent name in the client's draft is safe.
- **But a retry is not a no-op** — it creates an *additional* `Club` for the account. That
  is legal (a user may own several; `GET /api/club/all` lists them, `ClubRepository::findByUser()`
  resolves the most recent, so play continues on the new one), but it silently burns a save
  slot and leaves a ghost club behind. We did not add a server-side guard, because
  multi-club is a real feature and we cannot tell a retry from a deliberate second club.
  `POST /api/club/initialize` is on the `Idempotency-Key` allowlist instead — send a key
  and a retry replays the first response.

Worth noting for the mislabelled Sentry events: since the avatar POST runs *before*
`registerClub`, in `155644075` the club was never registered, so there is no half-created
club to collide with at all.

---

## B1 — `/api/initialize/*`

### 1. ~6,992 staff rows is not one tier of staff

The per-tier numbers, counted directly out of the cached payloads for Germany (18 clubs per
tier, the largest configured pyramid):

| Tier | Clubs | Staff | Players | Scouts |
|---|---|---|---|---|
| 1 | 18 | 306 | 612 | 54 |
| 2 | 18 | 270 | 523 | 54 |
| 3 | 18 | 252 | 505 | 54 |
| 4 | 18 | 234 | 479 | 36 |
| 5 | 18 | 216 | 476 | 36 |
| 6 | 18 | 216 | 480 | 36 |
| 7 | 18 | 198 | 425 | 18 |
| 8 | 17 | 187 | 368 | 17 |
| **Total** | **143** | **1,879** | **3,868** | **305** |

So the maximum staff in a single `/league/{tier}` response is **306**, not 6,992. The
whole-pyramid total across all three entity types is **6,052** for Germany and higher for a
20-club country — which is within arm's reach of 6,992, so `staff.batchUpsertEntities`
writing 6,992 rows looks like a cumulative figure across tiers and/or across entity types
rather than one tier's staff. Worth checking on your side what that counter actually spans;
if it is cumulative, the number is expected and the chunking is the right response.

**But the per-club staff count is almost certainly wrong, and that is ours.** Staff per club
is 11–17 depending on tier, and the dominant term is `coachCount`, which production
`StarterConfig.npcSquadConfig` has set to **7 (tier 8) through 13 (tier 1) coaches per
club**. The code's own fallback (`WorldInitializationService::defaultTierConfig`) is
`coachCount: 1`. So someone edited this through the admin and inflated NPC staff volume
7–13× over the design default. Dropping tier 1 from 13 coaches to 2 would take the German
pyramid from 1,879 staff to roughly 700 — the single largest saving available on the wire
and on the device.

We have **not** changed it: `starter_config` is live production game data, it is
admin-owned, and 13 coaches per club may be a deliberate design decision we are not party
to. It is one admin edit on the Starter Config screen whenever you want it. Note it only
affects tiers generated *after* the edit — existing `CountryWorldPackCache` rows need
`app:worldpack:warm <CODE> --force` to pick it up.

### 2. Cold-cache cost, and Japan

Cold build of one Japanese tier, measured: **1.5 s** for 8 clubs / 234 players, 129 MB peak.
An 18-club tier scales to roughly 3–4 s. Cold generation is **not** the problem — it is well
inside any client timeout.

`app:country:check JP` reports complete content: a Japanese name pool, all 8 `League` rows,
8 NPC clubs per tier, a `leagueAbilityRanges` entry. Two things are off, though:

- **Only tiers 1 and 2 are cached for JP** (and tier 1 only for US, NG and CA), against all
  8 for ES/EN/DE/IT/FR/BR/AR/NL/PT/PL. So a JP init builds six or seven tiers from scratch,
  one slow mobile request at a time. That is consistent with JP appearing in three of the
  four B1 events while the fully-warmed countries appear once.
- **JP is not in `StarterConfig.enabledCountries`** — that list is
  `["ES","EN","DE","IT","FR","BR","AR","PL"]`. Neither is US, CA, NG, PT or NL. But
  `/api/initialize/starter` only validates that `?country=` is a known `Country` case; it
  never checks `enabledCountries`. So a client can initialize into a country the operator
  has not enabled and has not warmed, which is exactly the state those JP events are in.
  Left as-is pending your answer to the question below — if the app is deliberately
  offering JP, the backend list should be updated to match rather than the endpoint
  tightened.

(Both read from the local prod restore; worth confirming against production before acting.)

### 3. `getOrBuild` was not race-safe. It is now

Two concurrent `POST /api/initialize/league/{tier}` calls for the same country+tier both
missed the cache, both ran the generator — which creates and then DQL-deletes a whole tier
of Player/Staff rows — and both tried to insert, so one died on `uq_country_tier` with a
**500 after doing all of that work**. Exactly the case your change to retry empty/truncated
2xx makes more likely.

Fixed: a cache miss now takes a Postgres advisory lock on (country, tier) and re-checks
under it, so the second caller waits and then gets the first one's payload for free. The
lock is released in a `finally` and by connection close at the latest; if it cannot be
taken, the build proceeds unlocked rather than failing, with the unique index still there as
a backstop. No double generation, no race on insert. Covered by
`tests/Service/WorldPackCacheServiceTest.php`.

### 4. `app:worldpack:warm` in production

It is **not** part of the post-deploy sequence (`.github/workflows/deploy-dev.yml`), which
is why the coverage above is patchy. Recommended, and the brief's instinct is right:

```bash
# Per enabled country, all 8 tiers, skipping whatever is already cached:
lando php bin/console app:worldpack:warm DE
# Or one tier at a time, which is what the --tier flag is for in cron:
lando php bin/console app:worldpack:warm DE --tier=3
```

`app:country:bootstrap <CODE>` already chains league + club generation, pool warm and
worldpack warm, and is the right tool when standing a country up. For the ongoing case, a
warm pass over `enabledCountries` on deploy would remove cold-build latency from the
client's critical path entirely. Not wired up here — it changes deploy timing and belongs
in a deploy change, not this one.

### 5. Response sizes and compression

Cached payload sizes, straight from `country_world_pack_cache`:

| | |
|---|---|
| Largest single tier | 777 KB (DE tier 1) |
| Typical tier 1 | 670–780 KB |
| Full 8-tier country | 4.3–4.9 MB |

**Compression is already on**, and it does a lot of work: `deploy/proxy/Caddyfile` carries
`encode zstd gzip` on both the production and dev hostnames. Measured on the DE tier-1
payload, 795 KB of JSON gzips to **116 KB — 6.9×** — because it is mostly repeated keys and
UUIDs. So the largest tier is ~113 KB on the wire, not 777 KB, and there is no win left
there.

That leaves two levers. The first is item 1: cutting `coachCount` shrinks every tier's
staff array directly, and it is the only change that reduces device-side write volume as
well as bytes. The second is chunking, which you already do — the 3.6 s / 6,992-row write in
`153689847` is a device-side cost that compression does nothing for.

### 6. Token TTLs — the 401 is not a design problem

| | |
|---|---|
| Access token TTL | **3600 s (1 hour)** (`config/packages/lexik_jwt_authentication.yaml`) |
| Refresh token TTL | **30 days** (`config/packages/gesdinet_jwt_refresh_token.yaml`) |
| `single_use` | **false** — a refresh token is *not* consumed by use |
| `ttl_update` | **false** — using it does not extend it |

So: the access TTL is an hour, far longer than even a pathological init, and a refresh token
can be redeemed repeatedly for 30 days. **Neither rotation nor consumption can explain
`147350837`'s failed refresh.** The remaining backend-side explanations are that the refresh
token was over 30 days old, or that `User::$tokenVersion` had moved — `JwtTokenVersionSubscriber`
stamps a `tv` claim into every access token and rejects tokens whose claim no longer matches
the account, which is how "kill session" works on a stateless firewall. That is also the one
thing that invalidates *all* of an account's tokens at once. Given the brief notes this was
a **dev build** and that dev-channel builds were arriving tagged `environment: production`,
a dev build pointing at a server with different JWT keys would also present exactly this
way. Our read: a flake or an environment mismatch, not a design problem — but if you can
tell us the age of the refresh token in that event we can be certain.

A 401 on `/starter` is already JSON with status 401 — lexik's authenticator answers
`{"code": 401, "message": "JWT Token not found"}` and the firewall's exception listener runs
*before* the new JSON subscriber, so that is unchanged. `/api/initialize/*` is covered by the
same `^/api` firewall and `ROLE_CLUB` access rule as every other authenticated route; no
difference there.

### 7. Retry after a partially completed init — this was the serious one

`POST /api/initialize/starter`'s docblock claimed "Idempotent — returns starter data again
if already initialized." **It did not.** It returned `409 "Starter already initialized."`

That is unrecoverable, not merely annoying. The starter squad is drawn from the Player/Staff
pool, snapshotted, and the pool rows are then **deleted** (Pool Lifecycle, CLAUDE.md), so
the HTTP response was the only copy of that squad that ever existed. Any player whose
starter response was lost in transit — a cancelled request, a backgrounded app, or exactly
the native SIGSEGV in `155675759` during "Assembling your squad…" — ended up with a club
marked initialized, no squad, and a 409 on every retry forever.

Fixed: the pack is stored on `club.starter_pack_json` in the same flush that consumes the
pool, and an already-initialized club is served it back as `200` with `"replayed": true`.
The replay check also moved *ahead* of the `?country=` override, so a retry carrying a
different country cannot rewrite the club out from under the squad it already drew. Clubs
initialized before this shipped have no stored pack and still answer `409`, now with a
machine-readable `"error": "starter_already_initialized"` — their squads really are gone.

`/api/initialize/league/{tier}` was already retry-safe via the cache, and is now race-safe
too (item 3). `worldInitializedAt` is set idempotently once all tiers are cached.

---

## B4 — contract hygiene

`docs/frontend-integration.md` gained two additive sections — **Error responses** and
**Retries and idempotency** — covering the problem+json shape, the `error` slugs, the
`Idempotency-Key` allowlist, the per-endpoint retry guarantees, and the new `season` field.
`docs/api/owner-avatar.md` records the widened `dob` and the 422 shape. Nothing was removed
or renamed.

---

## The four open questions

**1. Is `concludeSeason` idempotent per club+season today?**
It was not; it is now. Ship the retry queue. Please include `season` in the payload — see
B2.2 for why the fallback is not good enough for a *late* retry.

**2. JWT access-token TTL, and does a refresh consume a token the next refresh needs?**
1 hour, and no. Refresh tokens last 30 days, are not single-use and are not rotated. B1.6
has the remaining candidate explanations.

**3. Is ~6,992 staff rows per tier the intended volume?**
No tier carries anywhere near that — the maximum is 306, and 6,992 looks like a cumulative
or all-entity-type count on your side. But per-club staff *is* inflated: production has 7–13
coaches per club against a design default of 1. That is an admin config edit, ours to make
once you confirm you want it; keep chunking either way. See B1.1.

**4. Does any `/api/*` endpoint legitimately answer 200 with an empty body?**
**No.** Every `/api` route answers via `$this->json(...)`; there is not a single `204` or
empty `Response` among them. Treating an empty 2xx as a retryable transport failure is safe,
and the init endpoints it causes extra retries on are now both idempotent (B1.7) and
race-safe (B1.3).

---

## What changed in this repo

| Area | Change |
|---|---|
| `src/EventSubscriber/ApiExceptionSubscriber.php` | New. Problem+json for every `/api/*` error, with `violations[]` |
| `src/Service/LeagueService.php` | `concludeSeason` idempotent per club+season; stored-response replay; `resolveNewLeague` shared by both paths |
| `src/Dto/ConcludeSeasonRequest.php` | New optional `season` |
| `src/Entity/SeasonSnapshot.php` | New `responseJson`; `UNIQUE(club, season)` |
| `src/Entity/SeasonRecord.php` | `UNIQUE(club, season)` |
| `src/Entity/Club.php` | New `starterPackJson` |
| `src/Service/StarterPackService.php` | Stores the pack in the flush that consumes the pool |
| `src/Controller/InitializeController.php` | `/starter` replays the stored pack instead of 409; replay checked before the `?country=` override |
| `src/Service/WorldPackCacheService.php` | `getOrBuild` serializes a miss behind an advisory lock and re-checks |
| `src/EventSubscriber/IdempotencySubscriber.php` | Allowlist adds `/api/club/initialize` (conclude-season was added here and then removed again in round two) |
| `src/Dto/OwnerAvatarRequest.php`, `OwnerAvatarController.php` | `dob` accepts an ISO datetime; `""` clears instead of meaning today |
| `migrations/Version20261024132000.php` | `season_snapshot.response_json` + both unique indexes |
| `migrations/Version20261024140000.php` | `club.starter_pack_json` |

Both migrations applied cleanly against the local production restore (no pre-existing
duplicate `(club, season)` rows in either archive table — but the migration's own docblock
says how to find and resolve them if production differs). 21 new tests; full suite 1,330
green.

Round two adds, on top of the above: `WORLD_PACK_VERSION` 2 → 3
(`WorldInitializationService`), per-response logging and `WorldPackCacheService::isCached()`,
the `season` upper bound, and the two reversals. No new migration. Final suite: 1,333 green.

---

# Round two — replies to the frontend reply (24 Oct 2026)

Answers to `docs/2026-10-24-sentry-sweep-frontend-reply.md`. Four of its points changed
backend code; one of them reversed a decision from the first round, and one turned up an
unrelated bug.

## `/starter` stays permissive — and now says why in the code

Your point lands and settles it: `club.country` is persisted per save and `runStep1` re-reads
it on every retry, so an `enabledCountries` check on `POST /api/initialize/starter` would not
merely stop new clubs in unwarmed countries — **it would brick every existing save in one**,
with no client-side escape, because the init flow cannot proceed without a starter pack. Not
doing it. The note in `04_interfaces/output/routes.md` now records the reasoning rather than
the open question, so the next person reading that permissiveness as an oversight finds out
why it is load-bearing before changing it.

On where JP came from: `enabledCountryOptions` is **derived from `enabledCountries`** in the
same response (`StarterConfigController` resolves each enabled code through the `Country`
enum purely to attach a display label), so the two lists cannot disagree, and neither
contains JP on the production restore. Your hypothesis is therefore the only one left
standing: those devices read a `starter-config` whose list did include JP — the dev API being
the obvious candidate, and one of the three JP events is the dev build. Worth noting the
mechanism this exposes, which is ours: **`enabledCountries` is a single admin-editable row,
and removing a country from it silently strands every save already created in it.** That is
an argument for warming JP rather than for tightening anything.

## `Idempotency-Key` on conclude-season: removed, and your key is now a no-op

This reverses what I told you last round, because of something your reply made clear: **the
outbox drops a 4xx rather than retrying it.**

With that behaviour, allowlisting conclude-season is a liability. Its body-hash check exists
to catch a client reusing one key for two genuinely different operations — but club+season
already determines this operation completely, and the server ignores a duplicate anyway, so
the check can *only* fire as a false positive (the same archive re-serialized slightly
differently). The consequence of that false positive is a 422, which the outbox drops, which
silently loses a season of a player's history. All risk, no benefit.

So `/api/league/conclude-season` is off the allowlist. **You need to change nothing** — a key
sent to a non-allowlisted path is ignored, not rejected, so `"<clubId>:<season>"` can keep
being sent. The club+season guarantee is unaffected and covers strictly more: it also holds
for a request killed mid-flight, which never reached the point of caching a response.

`/api/club/initialize` stays on the list. There the header is the *only* guard — the endpoint
has no domain key to deduplicate on — so the mismatch 422 there is genuinely protective.
Which is the principle: the header covers paths with no domain-level guarantee, and paths
that have one should not take on its failure mode.

## The 422 for a future season is gone — the outbox made it dangerous

Same reasoning, and this one is a behaviour change worth reading carefully.

Last round I added a `422` for "concluding a season the club has not reached", and your reply
names it approvingly as the poison-entry case the outbox should drop. I have **removed it**,
because thinking it through against drop-on-4xx, it is the one guard that can cause the exact
loss this whole change set exists to prevent:

> Device is offline across seasons 2, 3 and 4. The queue flushes; season 2's entry is lost to
> something non-retryable. Season 3 now arrives at a server that still thinks the club is on
> season 2 — `3 > 2`, so `422`, so dropped. Season 4 then arrives at a server on season 2 —
> dropped. **Every subsequent season cascades**, silently, forever.

`currentSeason` now moves to `max(current, season + 1)`. A season behind is archived or
replayed and the club stays put; a season ahead is archived and pulls the count up to it. A
gap means the server never received the seasons in between, which is precisely when keeping
the archive that *did* arrive matters most — and the client is authoritative for gameplay
anyway (the server is not the game engine). So the queue can flush in any order, and a
dropped entry cannot poison the ones behind it.

The one remaining `422` on `season` is a value above 1000, which is junk rather than a gap.
It exists because `SeasonRecord::$season` is a `SMALLINT` and an unbounded value would fail
in the driver as a **500** — which your outbox retries forever, the opposite failure.

Please make sure the outbox does not depend on the dropped-4xx path for ordering. Nothing
about ordering is enforced server-side any more, which should make the outbox simpler rather
than harder.

## Q3 — 6,992: accepted as one call, and I cannot make the payload produce it either

Taking your tracing at face value — one invocation, de-duplicated by id before counting,
strictly per-tier, three separate labelled calls — then the number does not come from any
payload this backend can currently serve. Exhaustively, across all 85 cached world packs:

| | |
|---|---|
| Largest `staff` array in any one club | **17** |
| Largest per-tier staff total | **306** (18 clubs × 17, tiers 1 of DE/EN/IT) |
| Largest per-tier `staff` + `scouts` total | **360** |
| Largest `staff` + `scouts` + `players` for one tier | ~972 |
| Whole eight-tier country, all three types | ~6,052 (DE) |

So 6,992 is **19.4×** the largest per-tier staff payload, and does not match any per-tier
figure at all. It sits closest to a whole-pyramid, all-entity-type total — which your tracing
says it cannot be. I am not assuming it away either; I agree it is unreconciled.

What I have added from this side, since you cannot attach country/tier to that event without
changing its rate-limit key: **`/api/initialize/league/{tier}` now logs every response** —
`initialize.league_tier.served` with `country`, `tier`, `cacheHit`, `durationMs`, `clubs`,
`players`, `staff`, `scouts` and `payloadBytes`, counted per entity array per tier, the way
you count them. The next occurrence is placeable by correlating timestamps, with no client
change and no new Sentry label. If that turns out to be awkward, the alternative is a `meta`
block on the response you could attach directly — say the word and it is additive.

One possibility worth your eye, given the counts above: if `persistWorldLeagueClubs` is ever
reached with an accumulated `staffEntries` rather than one league's, a figure near the
whole-pyramid total is what you would see, and de-duplication by id would not reduce it
because every entity across tiers has a distinct id.

`coachCount` — noted that nothing in the app depends on large NPC coaching staffs. Still not
changing it unilaterally: it is live production game data, admin-owned, and plausibly a
deliberate design choice. It is one edit on the Starter Config screen plus
`app:worldpack:warm <CODE> --force`, whenever you want it.

## A separate bug this turned up: world packs were serving the retired trophy shape

Not from your reply, but found while reading a cached payload for the staff counts above, so
it ships here.

`WorldInitializationService::WORLD_PACK_VERSION` was **not bumped** when the trophy-sprite
work landed (`Version20261018202041`, 2026-10-18) and replaced `trophyImage`/`trophyColour`
with a single `trophy` `TrophyConfig`. `buildLeagueSnapshot()` emits the new shape, but the
cache has no TTL and the version check is the only thing that invalidates it — so **84 of the
85 cached packs still carried `trophyImage` + `trophyColour`**, and would have forever. Every
client initializing a world was being handed the retired asset-slug design.

This is the second time that constant has been missed (version 1's own note records it
happening with all-10s personality matrices), so its docblock now says explicitly that *any*
payload shape change counts, including the league-level block, not just the three snapshot
builders.

Bumped to **3**. Consequences for you: `trophy` replaces `trophyImage`/`trophyColour` in the
league block of `POST /api/initialize/league/{tier}` — contract in `docs/api/trophy-config.md`,
and `null` there means "awards no trophy", never a default cup. If anything still reads the
old keys from that payload, this is the change that stops them arriving. Rebuilt and verified
on one tier.

Operationally: bumping makes every cached row a miss, so the first client request per
country/tier pays the rebuild on its critical path. Measured on the local restore, all eight
tiers of a country take **~8 s** at 8 clubs per tier and **~18 s** at 18, so pre-warming is
cheap — plain `app:worldpack:warm <CODE>`, which rebuilds a stale-versioned row through the
atomic replace path. **Not `--force`**: in all-tiers mode that deletes the country's entries
up front and leaves it with no packs until the run finishes, which is strictly worse here.
Warming is a manual step rather than a deploy one, and the admin dashboard's `stalePacks`
count (`payload_version <> WORLD_PACK_VERSION`) is the signal for what is outstanding.
Nothing breaks if it is never run.

## And a second one: spoof entrants could duplicate a club name

Also unrelated to your reply, also found by running the suite rather than reading it —
`CompetitionSpoofEntrantServiceTest` fails roughly one run in ten, and the test was right.

`spoofAllEntrants()` generates its bootstrap club's name through its own
`generateClubName('EN', [])` call and then delegates the rest to
`generateSpoofEntrantsForCompetition()`, which started its own `$usedNames` at **empty** — so
the bootstrap name was never excluded from the names generated after it, and a four-club
bracket could come out with two clubs called the same thing. `generateClubName`'s avoidance
is only ever as good as the list handed to it. The same gap meant a spoof could duplicate a
*real* entrant's club name too.

`$usedNames` is now seeded from the club names already registered in the competition, which
closes both. Verified over ten consecutive runs of that file plus two clean full-suite runs.

(A third, genuinely statistical flake was also fixed: `testGoalkeeperReceivesHeightBias`
compared two 50-sample means whose true difference sits ~2.3 standard errors apart, so it
failed about one run in a hundred. 400 samples a side puts it past 6 sigma, and it now
asserts the magnitude of the bias rather than only its direction.)

## Noted, no action

- **`204` returns `undefined` rather than throwing** — good, that is the property worth
  having. The "no `/api` route answers an empty 2xx" check is now recorded in
  `04_interfaces/output/routes.md` as something to preserve, with the instruction to use
  `204` if a body-less endpoint is ever wanted.
- **`replayed: true` ignored as an extra key** — as intended; it is diagnostic, not a flag
  you need to branch on.
- **B3.3, no re-register path** — then the ghost club is unreachable, and the allowlisting
  stands as insurance only. Agreed it is worth having if that flow changes.
- **The sponsor re-roll** — understood that you compute all of it locally and ignore the
  body. If we ever do persist it you will hear about it, ignored body or not.
- **Refresh-token age in `147350837`** — can't be closed from here either. Agreed on the OTA
  env hypothesis (a dev-channel publish without `EXPO_PUBLIC_API_BASE_URL` pointing a dev
  build at production) as the leading candidate, and agreed there is no design problem to
  fix. Dropping it.
- **`expo-sqlite` 57.0.2 → 57.0.4** — nothing backend-side; noted as the outstanding item.
