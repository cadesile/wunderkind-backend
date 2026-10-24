# Frontend reply to the backend answers — 24 Oct 2026

Replies to the four things
`docs/2026-10-24-sentry-sweep-backend-answers.md` put back to this side, plus what shipped in
the app repo off the back of it. Everything below was read or traced in `wunderkind-app`
rather than assumed; where I could not settle something from the client, it says so.

---

## Shipped in the app repo as a result of your answers

| Your item | What the client now does |
|---|---|
| B2 (idempotent per club+season) | `POST /api/league/conclude-season` is **queued, not fired and forgotten** — new `src/api/concludeSeasonOutbox.ts` |
| B2.2 (`season` in the payload) | **Sent on every call.** `ConcludeSeasonPayload.season` is required on our side |
| B2.4 (`Idempotency-Key` allowlist) | Sent as `"<clubId>:<season>"` — stable across retries of the same archive |
| B1.7 (`/starter` replays) | **No change needed**, confirmed: a `200` flows through the normal path and `"replayed": true` is ignored as an extra key |
| B3.1 (`dob`) | **No change needed**, confirmed — see below. One defensive fix made anyway |
| B3.2 (problem+json) | Already consumed: `apiRequest` reads `detail` first, then `message`, then `error`, and keeps `error` as the machine code. `violations[]` is surfaced to Sentry by `describeApiFailure` |

The outbox persists to AsyncStorage, retries on a short backoff, again on the next launch,
and again on every return to the foreground — which is the moment that actually fixes a
device that was offline at season end. A 4xx other than 401/403/429 is **dropped** rather
than retried: a poison entry at the head would block every later season behind it, and your
new `422` for "season the club has not reached" is exactly that case. 403 holds, like the
week-sync queue, and resumes on sign-in.

One deviation from my own brief, flagged because it contradicts what I asked for: this is
**not** inside `syncQueue`. That class is typed end to end to `SyncRequest` and is more than
half week-sync response reconciliation, with a dedupe key and a retention cap expressed in
weeks; a second item kind would change the shape of an array already persisted on players'
devices. The outbox borrows its shape and leaves it alone.

---

## Q3 — "6,992 looks like a cumulative count on your side"

**It is not cumulative. That figure is one call.**

`captureWarning('db: slow write', { label, ms, rows })` takes `rows` from
`runBulkWrite(label, fn, { rows: meta.rows })`, which is `rows.length` of that single
invocation after de-duplication by id. Nothing aggregates into it — the per-label running
totals live in a separate map that is only emitted in the session summary on background, under
a different event name.

And every call site is strictly per-tier. `persistWorldLeagueClubs` is called once per league,
with that league's own `staffEntries`, from all three callers (`useInitFlow`'s fetch loop, its
resume loop, and `worldStore.initializeWorld`). It then makes three separate
`batchUpsertStaffEntities` calls — `'coach'`, `'scout'`, `'npc_scout'` — so one tier produces
three events under that label, each with its own row count, none of them summed.

So **I cannot reconcile 6,992 with your 306**, and I do not think either of us should assume
it away. What the client contributes to the diagnosis:

- The staff array is passed through 1:1 from your payload. `buildWorldLeagueClubs` maps each
  `club.staff` entry and never synthesises one, with a single exception: if a club has no
  `role: 'manager'`, it substitutes a generated manager for an existing entry — a replacement,
  not an addition.
- De-duplication by `id` happens before the count, so a repeated id would make the number
  smaller, not larger.
- The event carries no country or tier today, which is why neither of us can place it. Worth
  one of us adding; I have not, because the label is also the rate-limit key and splitting it
  per tier would change what gets reported.

Your `coachCount` finding stands on its own merits regardless — 7–13 coaches per club against
a design default of 1 is the only lever here that cuts device-side write volume as well as
bytes, and the client would see the benefit immediately. **That one is your call to make**, as
you say; from this side there is no reason to keep it high, and nothing in the app depends on
NPC clubs having large coaching staffs.

---

## Q4 — "does any `/api/*` endpoint legitimately answer 200 with an empty body?"

Noted, and thank you for checking it exhaustively — that is the answer the change needed. The
client treats an empty 2xx as a retryable transport failure with one exception already coded:
**`204` returns `undefined` rather than throwing**, so if a route ever does become body-less,
make it a 204 and nothing breaks.

---

## Q2 / B1.6 — the 401, and the refresh-token age

I cannot answer the one question that would settle it: **the client has no way to read the age
of the refresh token in `147350837`**, and Sentry does not carry it. If it is worth closing, it
would need a field adding to the auth failure report, which is a client change I have not made.

Your environment-mismatch hypothesis is the strongest one on the evidence, and I can add
something to it. That event is from a **dev build**, and dev builds were arriving tagged
`environment: production` with release `com.buildmyclub@…` — which is now fixed, but it means
**that event may not be what its tags say it is**. More usefully: the API base URL is inlined
into the bundle by Metro at build time, so an OTA takes it from the *update workflow's* env
rather than the installed build's profile. A dev-channel OTA published without
`EXPO_PUBLIC_API_BASE_URL` set would point a dev build at production with dev credentials —
which presents exactly as a 401 whose refresh also fails. I would treat that as the leading
candidate over anything systematic.

Given a 1-hour access TTL and 30-day non-rotating refresh tokens, I agree there is no design
problem here, and I am not planning further client work on it.

---

## B1.2 — Japan, and a warning about tightening `/starter`

The client **never offers JP from anything hardcoded.** Two lists exist and neither contains
it:

- `CLUB_COUNTRY_CODES` (`src/utils/nationality.ts`) is `EN, IT, DE, ES, BR, AR, NL, PT, FR` —
  and it is not what the picker reads anyway.
- The onboarding picker reads `resolveEnabledCountryOptions(starterConfig)`, i.e. **your own
  `enabledCountries` / `enabledCountryOptions`**, falling back to a single `EN` placeholder if
  the config has not been fetched.

So a device asking for `?country=JP` got JP from a `starter-config` response that listed it. If
production's list does not include JP today, either it did at some point, or those devices were
talking to a server whose config did (the dev API is a live candidate — one of the three JP
events is the dev build).

**The important part, before you tighten the endpoint:** the country is not re-chosen per
request. `runStep1` resolves it as `countryOverride ?? pendingCountryRef ?? useClubStore
.getState().club.country`, and `club.country` is **persisted per save**. So a club created while
JP was enabled will keep sending `?country=JP` on every retry, for the life of that save. Adding
an `enabledCountries` check to `/api/initialize/starter` would therefore not just prevent new JP
clubs — **it would permanently brick every existing one**, with no client-side escape, since the
country is baked into the save and the init flow cannot proceed without a starter pack.

If the goal is to stop new clubs in unwarmed countries, the safe place is the config the picker
reads (which already works — JP is not offered), not the endpoint. If JP is genuinely wanted,
warming it is the fix. Either way, the endpoint staying permissive is protecting existing saves.

---

## B3.1 — `dob`: confirmed clean from this side, one hardening change anyway

I traced every path that can produce the `dob` the client sends, and **all of them produce
`YYYY-MM-DD`**:

- The picker builds it from three numeric pickers via `joinDob`, which pads and defaults each
  component, so it cannot emit anything else.
- A prefill from an existing profile comes from your `GET /api/owner-avatar`, which returns
  `YYYY-MM-DD`.

So I cannot reproduce the 422 either, and your widening makes it moot in both directions.

One thing I fixed regardless, because it is the only function in the flow that can turn good
input into an invalid date: `normaliseDob` was
`s.split('-').map(Number)` plus two `padStart`s, which **manufactured** a bad value rather than
rejecting one — `""` became `"0-NaN-NaN"` and an ISO datetime became `"2008-05-NaN"`. It now
parses what is there and returns the input untouched if it is not a date it understands.
Interesting mostly because it is the exact shape that would have produced your 422 from a
client that looked, at the call site, like it was sending a clean date.

Your empty-string finding — `""` reaching `new DateTimeImmutable("")` and setting the owner's
birthday to the day they signed up — is a good catch and not one the client could have seen.

---

## B3.3 — the duplicate club is not reachable from the UI

Worth saving you the worry: **the client's retry after a failed `registerClub` does not
re-register.** The state machine dispatches `createGame` exactly once, on FOUND CLUB confirm;
the retry affordance the player gets afterwards (`onRetryInit`) calls `init.retry()`, which
re-runs world init only. So the ghost-club case you describe cannot be produced by a player
tapping retry, and I have not added an `Idempotency-Key` to `POST /api/club/initialize`.

Your allowlisting it is still worth having as insurance if that flow ever changes, and if we do
add a re-register path this is already solved.

There is a related gap on our side, which is ours and not yours: if `registerClub` fails, the
flow surfaces the error but the retry runs init against a club that was never created. That is
an onboarding-flow fix, logged here only so it is not mistaken for a backend issue.

---

## Open, and not blocking anything

- **`expo-sqlite` 57.0.2 → 57.0.4.** Unrelated to the backend, but it is the largest item still
  outstanding from the sweep: 57.0.4 added the per-statement lock on `reset`/`finalize`/`getAll`/
  `step`, and every native crash frame in those 19 events is one of those calls. Needs a rebuild
  and a device pass, not an OTA.
- **The sponsor re-roll not being persisted** (your B2 side note). Understood and left alone —
  flagging only that the client does not rely on the conclude-season response at all
  (promotion, relegation, fixtures and finances are all computed locally), so from this side
  the behaviour is invisible either way. If you ever do persist it, that is a contract change
  worth telling us about even though we ignore the body.
