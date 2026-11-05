# Setup flow — backend answers

A reply to the prototype team's "Setup flow and authentication" note (v3 prototype,
2026-11-04). Structure follows that note so the two read side by side.

**Read this with `.context/shared/api-backwards-compatibility.md`.** The backend ships ahead of
the app, so everything below that is "exists" is already deployed to dev and must keep working.

---

## The one correction that changes the whole auth table

**`ROLE_CLUB` is not an ownership check, and it is not a level above USER.**

`User::getRoles()` appends `ROLE_CLUB` unconditionally to every account
(`src/Entity/User.php:164`). It is granted at registration, by the act of being a `User` row —
not by owning a club, and nothing can withdraw it. Its own docblock explains why: the column
twice held something else (`[]` before 2026-03, a since-deleted `ROLE_ACADEMY` until 2026-04),
and either value made every `roles: ROLE_CLUB` route answer `403` to an account that had just
logged in successfully — indistinguishable client-side from a refused account. See
`docs/api/role-club-403.md`.

So the note's three levels collapse to two at the firewall:

| Note's level | What the firewall actually enforces |
|---|---|
| PUBLIC | `PUBLIC_ACCESS` — genuinely open. |
| OPTIONAL | Does not exist anywhere. Every endpoint is one or the other. |
| USER | `IS_AUTHENTICATED_FULLY`. |
| CLUB | **Also just `IS_AUTHENTICATED_FULLY` in practice**, because `ROLE_CLUB` is universal. |

**Ownership is enforced in the controllers, not by the role.** A "CLUB" endpoint resolves the
caller's active club from the token and answers `404`/`403` when there isn't one or it isn't
theirs. That is a real check — it is just not a role check, so you cannot read the auth level off
`security.yaml` and conclude anything about ownership.

Practical consequence for the client: **treat USER and CLUB as the same auth level.** Send the
same token. The difference is only which calls will succeed, and that depends on state, not on
the token.

---

## Answers to the eight open questions

### 1. Token after `club/initialize` — no refresh needed

The token you already hold works for every call after FOUND CLUB, including the world init.
`ROLE_CLUB` was in it from the first login (see above), so `club/initialize` grants nothing and
has nothing to hand back. **Do not add a refresh between FOUND CLUB and the CLUB calls** — the
current straight-through sequence is correct.

The thing that can actually fail there is not the token but the club lookup: an endpoint that
resolves "the caller's active club" needs the club to be committed first. `club/initialize`
commits before responding, so the sequence is safe as written.

### 2. Gate enrichment-triggering reads — done, and done differently

**Shipped on `development`, 2026-11-04.** Your proposal was right about the risk and we went
further on the mechanism:

- **`GET /api/locations/{id}` no longer triggers anything.** It is read-only and free. Opening a
  town does not spend money.
- **`POST /api/locations/{id}/enrich`** is the only trigger. Requires a signed-in user, no club.
  Returns `202 {enrichmentStatus, queued, pollUrl, message}`; `200` if already enriched;
  `409 enrichment_needs_admin` for `needs_review`/`failed`; `404` for an unknown id.
- **The whole locations surface is now authenticated** — list, facets and detail. Per your
  follow-up: the catalogue is unreleased curated content and shouldn't be public either.

A GET that spends money was the defect; moving the spend to an explicit POST fixes it more
completely than gating the GET would have, because it also gives you a way to say "price this
one" without navigating, and makes prefetch and double-tap harmless.

**And there is now a per-account quota**, which answers the second half of your question.

- `GET /api/account/enrichment-quota` → `{used, max, remaining, unlimited, exhausted}`. USER.
  **Gate the "get a valuation" button on `exhausted`.**
- The POST returns `429 enrichment_quota_exhausted` when the allowance is gone, and carries the
  same `quota` block on every `2xx`, so you never need a second call to know what is left.
- The ceiling is admin-set on `/admin?routeName=admin_starter_config` ("Land Valuations Per
  Account"), and also published on `/api/starter-config` as `maxEnrichmentRequestsPerUser`. **`0`
  means unlimited**, and that is what the migration sets on the existing row — the endpoint is
  already deployed and working, so a migration must not start refusing requests as a side effect.
- **Only billed calls are charged.** `202 queued: false` (somebody else is already valuing that
  town), a double tap, a retry after a timeout, and opening an already-priced town are all free.
  An exhausted account can still browse everything and open every priced town; only a *new*
  valuation is refused.
- It is a **lifetime total, not a rolling window.** Your note suggested "N per account per hour";
  what is built is "N per account, ever". A window needs a table of timestamps rather than a
  counter — say so if you want that instead and it is a separate, larger change.

**Throughput ceiling, which matters for the UI:** the worker is a per-minute cron with
`--limit=10`, so the system drains at ~10 towns/minute globally. One town on an explicit tap is
fine; a screen that fans out is not. Polling guidance: 3s for 30s, back off to 10s, give up at
~3 min. Full contract in `docs/api/locations.md`.

**One thing the note doesn't mention and should:** enrichment is what gives a town its **plots**.
A bare-name town has zero plots until valued, so before enrichment it reports
`claimableReason: "awaiting_enrichment"` — which is *not* `town_sold_out` and must not be
rendered as a dead end. These two used to be the same value, which sent players away from the
majority of the catalogue.

### 3. `starting_grant` — already built, on the first `owner-avatar`

**Exists.** Written as a side effect of `POST /api/owner-avatar`
(`src/Service/StartingGrantService.php`). No `POST /api/account/setup` needed.

- Hung off the avatar, not registration (which would grant to accounts that never play) and not
  club creation (which would grant per club).
- **Idempotent on the user id, forever.** `UserLedgerService::append()` dedupes on
  `(sourceKind, sourceRef, type)` = `('starting_grant', userId, STARTING_GRANT)`, so the
  hundredth avatar save writes nothing. Not a "first call" heuristic — safe for a
  partial-update endpoint the client hits repeatedly.
- **Never throws into the caller.** A grant that can't be written is logged and retried on the
  next save, because the player must not be stuck on the one screen they cannot skip.

**But there is no single `startingGrantPence`, and this breaks an assumption in the note.** The
amount is a configured **range**, resolved deterministically per user id
(`StarterConfig::resolveUserStartingBalancePence()`). `/api/starter-config` already publishes
`userStartingBalanceMinPence` and `userStartingBalanceMaxPence`.

So: **the client must not compute an expected opening balance.** Read the real figure from
`GET /api/account/ledger` after CREATE ACCOUNT. Showing a number derived from config will be
wrong for almost every account. (A range both ends of which are `0` is a legitimate "grant off"
setting, and then no entry is written at all — handle a zero balance.)

### 4. Should `quiz_bonus` double the grant? — recommend a server-side multiplier

**Not built.** Neither `GET /api/setup-quiz` nor the answer endpoint exists.

On the question itself: "double the grant" only works if the server does the arithmetic, because
of #3 — the client cannot know the grant. So the recommendation is a configured
`quizBonusMultiplier` applied server-side to the account's own resolved grant, written as a
separate `quiz_bonus` entry. The client shows whatever the ledger says.

A fixed amount would be simpler but flattens the range: a lucky account's bonus would be a
smaller proportion of its grant than an unlucky one's, which is backwards from the intent.

**The server will not trust the 5s timer**, as the note anticipates. What it will enforce is
**one attempt per account, ever** — same ledger-idempotency mechanism as the grant.

### 5. `Idempotency-Key` header, or once-per-club-per-site? — once-per-club-per-site

Use the natural key. Every money-moving write in this system is already idempotent on something
the request itself carries — `sourceRef` on the ledger row — and `(club, site)` is exactly that
for facilities, `(club)` for funding. An `Idempotency-Key` header would add a second,
client-generated dedupe path over the top of a server-side one that cannot be got wrong, and the
client would have to persist the key across an app kill for it to be worth anything.

What that buys you, concretely: retry the same `levels` → `200`, no second charge. Different
`levels` after a charge → `409 site_already_built`. That is the behaviour the note proposes and
it needs no header.

### 6. Resume after a mid-BUILD kill — yes, and it should be its own endpoint

Server state is the right source, and `GET /api/club/check` is the wrong place for it. It
currently returns `{exists, clubId}` and is the pre-club routing call — it is deliberately
cheap and deliberately answers one question. Loading it with setup progress would make the
hub's first call do work for a screen the player may never reach.

Recommend **`GET /api/club/setup-state`** returning which sites are charged and whether the club
is funded, derived from the ledger rather than from flag columns, so it cannot drift from what
was actually billed. **Proposed, not built.**

---

## Corrections to the note's tables

| Note says | Actually |
|---|---|
| `GET /api/locations?country=XX` — PUBLIC | **USER.** Changed 2026-11-04. |
| `GET /api/locations/facets` — PUBLIC | **USER.** Same change. |
| `GET /api/locations/{id}` — "opening an unpriced town queues one billed AI valuation" | **USER, and it queues nothing.** Use `POST /{id}/enrich`. |
| "Signed out, it computes affordability against 0" | No longer reachable — the endpoint needs a token, so `balancePence` is always real. |
| `startingGrantPence` as a proposed scalar on starter-config | It is a **range**, already published as `userStartingBalanceMinPence`/`MaxPence`. Read the actual grant from the ledger. |
| `GET /api/locations/{id}` polled "every 1–2 s" | Too fast, and now capped anyway. 3s for 30s, then 10s, give up at ~3 min. The worker drains ~10 towns/minute system-wide. |
| `starting_grant` — "proposed" | **Exists**, on the first `owner-avatar`. |
| "We need a way to tell 'no owner yet' from 'owner but no club'" | **`GET /api/owner-avatar` already answers it** — `name === null` means no owner yet. No new endpoint needed, though a combined `GET /api/account` would save a round trip. |
| `GET /api/market/data` — "needs weekly wage on every candidate" | Staff candidates **already carry `weeklySalary`**. Scouts, agents, investors and sponsors do not — see the gap below. |
| CLUB as an auth level above USER | Same firewall level. Ownership is a controller check. |

`GET /api/countries`, `/api/starter-config` and `/api/telemetry` **stay PUBLIC** and were not
part of the locations change. They are consumed by the released landing page, so moving them
would be a real break rather than a theoretical one, and none of them is a doorway to spending.

### A gap in 5.6/5.7 worth knowing before you build the funding floor

The funding step projects running costs from candidate wages. `Staff` has `weeklySalary` and it
is exposed. **`Scout` has no wage column at all** — not unexposed, absent from the entity. `Agent`
carries `commissionRate`, `Investor` an `expectedReturnPercentage`, `Sponsor` only a size band.

So a 13-week projection built from `market/data` today would silently omit scout cost and model
agents as free. That needs deciding before `POST /api/club/fund` has a floor to enforce: either
scouts get a wage (schema change, and a backfill for every generated scout) or the projection
treats them as a flat per-head cost from config.

---

## Status of everything the note proposes

| Proposed | Status |
|---|---|
| `POST /api/locations/{id}/enrich` + read-only GET | **Built**, on `development`. |
| `awaiting_enrichment` vs `town_sold_out` split | **Built.** |
| Locations behind auth | **Built.** |
| `GET /api/setup-quiz`, `POST /api/setup-quiz/{id}/answer` | Not built. Needs the #4 decision. |
| `POST /api/club/facilities/{site}` | Not built. Contract in the note is agreed; see the wage gap. |
| `POST /api/club/fund` | Not built. Floor needs the wage gap resolved. |
| `GET /api/club/name-available` | Not built. Cheap and uncontroversial. |
| `GET /api/club/setup-state` | Not built. Recommended over loading `club/check`. |
| Per-account enrichment quota + `GET /api/account/enrichment-quota` | **Built.** Lifetime cap, admin-configurable, 0 = unlimited. |
| `GET /api/account` (owner + club in one call) | Not built. `GET /api/owner-avatar` + `GET /api/club/check` covers it today. |
| New `starter-config` fields | Not built. Several are game-balance figures that live elsewhere today; needs a pass per field. |

**New error codes are a client-visible change.** `409 enrichment_needs_admin`,
`429 enrichment_quota_exhausted`, `409 town_sold_out` and `awaiting_enrichment` are all new. An older build will not recognise
them. Flagged here rather than assumed to degrade gracefully.
