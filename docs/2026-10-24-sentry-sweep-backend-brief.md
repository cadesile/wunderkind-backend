# Backend work from the production Sentry sweep — 24 Oct 2026

Nineteen production Sentry events from `com.buildmyclub` v1.2.0 (builds 117/120/121 plus the
OTA publish `01a16849-a67a-7…`, created 2026-10-23 10:23 UTC) were investigated across both
repos. **The frontend half is done** — see "What the client already changed" at the bottom,
because two of those changes alter what the backend will see. This document is the backend
half: four workstreams, ranked, each with the client-side evidence behind it.

Nothing here is a guess about backend code. Several of these routes postdate the context
snapshot the investigation started from, so **grep rather than trusting a documented layout**,
and where this brief asserts something about server behaviour it is quoting a comment in the
client that a backend reviewer should confirm or refute.

Run commands via `lando php bin/console …` / `lando composer …`. Tests: PHPUnit.

---

## B2 — `POST /api/league/conclude-season`: idempotency and late arrival

**Do this one first.** It is the only item that is actively blocking frontend work, and every
occurrence of the bug permanently destroys a season of a real player's history.

### What happens today

The client calls this once, fire-and-forget, and never retries. Sentry `154751485` (Galaxy
Note10+, Android 12, season 1, step `server_sync`) is the failure mode: the POST died as
"Fetch request has been canceled" on a poor connection. The season's archive — the pyramid
snapshot, every tier's stat leaders, the club's full season of fixtures — was simply lost.

The obvious fix on the client is to enqueue the payload in `src/api/syncQueue.ts` so it
retries on the next good connection. **I did not do that, deliberately**, because of this
comment at the call site in `SeasonTransitionService.ts`:

> Guarded so the POST happens at most once per season across retries and crashes — backend
> `concludeSeason` is not idempotent (it persists a `SeasonRecord` and does
> `setCurrentSeason(+1)` every call).

If that is accurate, a client retry produces a duplicate `SeasonRecord` and advances the
server's season counter twice. And a retry cannot be made conditionally safe: a cancelled or
timed-out request **cannot be proven never to have reached the server**, so there is no
transport-level signal the client can key on.

### What's needed

1. **Confirm or refute the non-idempotency claim** in `LeagueService::concludeSeason` /
   `LeagueController::concludeSeason`. It is the hinge for everything else here.
2. **Make a duplicate or late request for the same `club + season` a no-op**, returning the
   same response as the first. "Late" matters as much as "duplicate": once the client queues
   this, a retry can arrive *after the next season has already started*, so keying on
   "current season" is not sufficient — key on the `season` in the payload.
   Specifically, confirm none of these happen twice: a second `SeasonRecord` row, a second
   `SeasonSnapshot`, a re-roll of league sponsors (must be deterministic for a given
   club+season), or a second `setCurrentSeason(+1)`.
3. **Measure the request's duration** — fixtures, sponsor rolls, snapshot writes — against the
   client's timeout, which is now **30s** for this endpoint specifically (it was the global
   10s). The payload is by far the largest the app sends: the whole pyramid snapshot plus
   `seasonLeaders` (top-10 scorers/assisters/rated per tier, across the whole world) plus
   `seasonFixtures` (a full season for the user's club).
4. Tell the frontend when it is idempotent. The client change to enqueue it is small and is
   waiting on exactly that.

Relevant: `LeagueController::concludeSeason`, `LeagueService::concludeSeason`,
`ConcludeSeasonRequest`, `SeasonRecord`, `SeasonSnapshot`.

---

## B3 — `POST /api/owner-avatar` returns HTML for a 422

### What happens today

Sentry `155644075` (OPPO CPH2753, embedded build 121), new-game onboarding. The last
breadcrumb before the failure is:

```
POST /api/owner-avatar → 422 (non-JSON)
```

The body was Symfony's "An Error Occurred: Unprocessable Content" HTML page. So the client
received a 422 with **no machine-readable violations at all** — it could not tell the player
which field was wrong, and the Sentry event could not tell us either.

Two further facts from the client side worth having:

- The event was **mislabelled** as "club registration failed after FOUND CLUB". That was a
  frontend bug (one `catch` covering two sequential calls) and is fixed — but it means any
  earlier occurrences of this 422 in Sentry are filed under the wrong title. The avatar POST
  runs *before* `registerClub`, so in that event **the club was never registered**.
- The payload the client builds is `{ name, dob, nationality, avatar }` —
  `toOwnerAvatarPatch()` in `src/components/new-game-setup/applySetup.ts`. Note `gender` is
  **deliberately absent**: the flow no longer asks for it, and the endpoint is documented
  (`docs/api/owner-avatar.md`) as a true partial update keyed on key *presence*. If a
  constraint has since started requiring it, that is the likely 422.

### What's needed

1. **Reproduce the 422** with the payload shape above. Check null/missing fields, length and
   enum constraints, and whether the DTO changed between client build 121 and what is
   deployed. `nationality` is sent as a demonym (via `codeToNationality`), not an ISO code.
2. **Make every `/api/*` error return JSON regardless of `Accept`** — `application/problem+json`
   or `{violations: [...]}`. This is the general fix and matters well beyond this endpoint;
   an HTML error page from an API route is unusable by any client. Log the violations
   server-side too.
3. **Confirm a retry of club registration after FOUND CLUB is idempotent** and does not fail
   on a name that is now taken by the half-completed first attempt (`ClubInitializationService`).
   The club name is permanent in the client's draft by that point, so a player who hits this
   has no way to proceed if the retry 409s on their own name.

---

## B1 — `/api/initialize/*` robustness

Four events touch this. Three are `country=JP` and one `country=DE`, which may or may not be
meaningful — worth checking whether those countries have complete name-generation data and a
cold `CountryWorldPackCache`.

| Sentry | Device | What happened |
|---|---|---|
| `155675759` | realme 5i, Android 10 | Native SIGSEGV during `init_step=Assembling your squad…`, last request `POST /api/initialize/starter?country=JP`. Boot watchdog fired at 60s. |
| `147350837` | dev build | `ApiError: Session expired` on `POST /api/initialize/starter?country=JP` — a 401 whose token refresh then also failed. |
| `155519935` | — | `ApiError: Network error` (`UnknownHostException`) on `POST /api/initialize/starter?country=DE`, device genuinely offline. Client retried twice and gave up. Expected behaviour; listed for completeness. |
| `153689847` | Galaxy F22, Android 12, 6GB | `db: slow write` — `staff.batchUpsertEntities` wrote **6,992 rows in 3,623ms**, plus 3,837ms of queue wait. |
| `155784582` | Galaxy A12, Android 11, 2.8GB | `POST /api/initialize/league/{tier}` returned an **empty body** when the app was backgrounded mid-request, at "Building Division 2…". |

### What's needed

1. **Why does one tier carry ~6,992 staff rows?** This is the concrete number from
   `153689847`: a single `/league/{tier}` response, persisted by the client as 6,992 staff
   entities. It looks like NPC staff for every club in the tier. If that is intended, say so
   and the client will keep chunking it; if it is accidental duplication, it is the single
   largest init cost on the wire and on the device.
2. **Measure cold-cache cost** of `/starter` and `/league/{tier}` for a country with an empty
   `CountryWorldPackCache` (JP is the likely cold case given it appears three times). Check JP
   has complete name-generation data and config.
3. **Is `WorldPackCacheService::getOrBuild` idempotent and race-safe** for the same
   country+tier under retried or concurrent requests? This now matters more than it did:
   the client treats an empty/truncated 2xx body as a retryable network failure (see below),
   so `/league/{tier}` will be retried in cases where it previously was not. No double
   generation, no race on insert.
4. **Is `app:worldpack:warm` run in production, and for which countries?** If not, recommend
   a warm-on-deploy or a per-enabled-country job.
5. **Check response sizes and whether compression / smaller chunks help.** Related to (1).
6. **The access-token question, which only you can answer:** what is the JWT TTL, and what is
   the refresh-token behaviour? Worst-case init on a weak device runs for **minutes** (the
   watchdog's second deadline is 60s and `155675759` blew through it). If the TTL is shorter
   than a slow init, `147350837`'s 401 is a design problem, not a flake. The client already
   attempts a refresh once on any 401 and retries the request; in that event the **refresh
   itself** failed, so the question is whether the refresh token was already consumed,
   rotated, or expired. A 401 on `/starter` must also be JSON with status 401, and the
   firewall rules for `/api/initialize/*` should match the other authenticated routes.
7. **Confirm retry after a partially completed init is safe** — `Club::starterInitializedAt`,
   `worldInitializedAt`.

Relevant: `InitializeController` (`/api/initialize/starter`, `/leagues`, `/league/{tier}`),
`StarterPackService`, `WorldPackCacheService::getOrBuild`,
`WorldInitializationService::buildTierPack`, `CountryWorldPackCache`, `app:worldpack:warm`.

---

## B4 — contract hygiene

Keep `docs/frontend-integration.md` current and backwards compatible, additive changes only.
Two client changes below touch the contract. (The original brief also named
`docs/api/sync-v2.md`; there is no such file in this repo — the sync contract lives in
`docs/frontend-integration.md`.)

---

## What the client already changed (merged into `development`, not yet on `master`)

Two of these alter what the backend will observe, so they are listed first.

**1. An empty or truncated 2xx is now a retryable transport failure.** `apiRequest` used to
finish with `await response.json()`. A backgrounded request produced
`JSON Parse error: Unexpected end of input` (`155784582`), which the init flow's retry policy
did not recognise — it only retries `ApiError(0, …, 'network')`. The body is now read as text
first; an empty body on a 2xx (except `204`) and an unparseable one both become that
retryable network error. **Consequence for the backend:** any endpoint that can legitimately
return a 200 with an empty body will now be retried by the client. If such an endpoint exists,
it should return `204` instead. Init endpoints in particular will see more retries than before
— hence B1 item 3.

**2. `POST /api/league/conclude-season` now has a 30s client timeout**, up from the global 10s
(`CONCLUDE_SEASON_TIMEOUT_MS`, `src/api/endpoints/season.ts`). Still one attempt, still no
retry, pending B2.

Non-contractual, but useful context for anyone reading the Sentry stream:

- **A non-JSON error body is now captured on the client's `ApiError`** (first 500 chars, plus
  length, under `{ nonJson: true, bodyLength, bodyPreview }`) and attached to Sentry events via
  a shared `describeApiFailure()` helper. So the next occurrence of B3 will arrive with the
  HTML page attached — but the right fix is still to send JSON.
- **Sentry `environment` and `release` are now derived at runtime** from
  `Application.applicationId` + `Updates.channel`. Dev-channel builds were previously arriving
  tagged `environment: production` with release `com.buildmyclub@…`, so **historical Sentry
  data for this app mixes dev and production events under the production release.** Worth
  knowing before drawing conclusions from event volume on anything older than this change.
- Four separate client-side defects were masking dead-SQLite-handle errors and are fixed;
  none of them involve the backend. Written up in the app repo at
  `.context/stages/07_synthesis/output/architecture-notes.md`, "The dead SQLite handle has
  THREE spellings".
- The Tournament Hub Dashboard crash (`GET /api/club/hub` omitting the `inbox` list) was fixed
  client-side in `39386ab6` by coercing every absent list to `[]` at the API boundary. It is
  on `dev`/`development` but **not on `master`**. A separate, smaller question for the backend
  stands: `GET /api/club/hub`'s DTOs declare those seven lists as required and the server does
  not always send them. A missing list and an empty one are now indistinguishable to the
  client, so if a sparse Dashboard is ever reported, that is where to look.

## Open questions back to the frontend

1. Is `concludeSeason` idempotent per club+season today? (B2 — blocks the client retry queue.)
2. What is the JWT access-token TTL, and does a refresh during a long init consume a token the
   next refresh needs? (B1.6 — decides whether `147350837` is a flake or a design problem.)
3. Is ~6,992 staff rows per tier the intended volume? (B1.1.)
4. Does any `/api/*` endpoint legitimately answer 200 with an empty body? (Contract change 1.)
