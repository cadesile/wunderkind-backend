# Club setup — BUILD, FUND, resume and the quiz

The five endpoints `docs/api/setup-flow.md` listed as "not built". All five are on
`development` as of 2026-11-05.

| Endpoint | Auth | What it does |
|---|---|---|
| `POST /api/club/facilities/{site}` | USER + club | Build out one facility site, charged once |
| `POST /api/club/fund` | USER + club | Stake the club's opening capital — the atomic debit |
| `GET /api/club/setup-state` | USER + club | Resume: what has been charged and built |
| `GET /api/setup-quiz` | USER | The quiz, with this account's progress |
| `POST /api/setup-quiz/{id}/answer` | USER | Answer one question; pays the bonus on the last |

"USER + club" means the same token as everything else — `ROLE_CLUB` is appended to every
account and is not an ownership check (`docs/api/role-club-403.md`). Ownership is a controller
check, and the two `/api/club/` writes **require an explicit club id** via the `X-Club-Id`
header or `clubId` in the body. They never guess: an account can own several clubs, one per save
slot, and a charge landing on the wrong save is real money spent on the wrong game.

---

## The financial boundary, because it decides what these endpoints may do

Three phases, and only the first and last touch the account balance:

1. **SETUP** — purchases are charged to the **account's** `UserLedger` credit. Land, stadium,
   and now facilities. The stake (`POST /api/club/fund`) is the last of those debits.
2. **GAMEPLAY** — isolated. Nothing here is queried or written during play.
3. **DIVIDEND** — the game writes back, crediting the account. That is `dividend_draw`, picked
   out of the sync payload, and it is the only inbound path.

**No endpoint on this page writes `club.balance`.** `SyncService::process()` assigns that column
absolutely from the device on every sync, so a server-side figure there would look right until
the first sync and then vanish. `POST /api/club/fund` therefore **returns** the staked figure
and the client seeds its own local capital with it.

---

## `POST /api/club/facilities/{site}`

### What a site is

One of the four facility categories: **`training`**, **`medical`**, **`scouting`**,
**`stadium`**. They are `FacilityTemplate.category` lowercased, and the generated
`FacilitySpriteMap::SITES` the client draws with carries the same four keys. `GET /api/club/setup-state`
lists whatever this database actually has, so read them from there rather than hardcoding.

### You already have the catalogue

`GET /api/game-config` publishes `facilityTemplates` with `slug`, `category`, `baseCost`,
`maxLevel` and `weeklyUpkeepBase`. There is **no separate facilities read endpoint** — price the
screen from that, and this endpoint re-prices server-side as the authority.

**The cost curve** is `FacilityTemplate.baseCost`'s own published formula: each upgrade costs
`(currentLevel + 1) × baseCost`, so reaching level *L* from nothing costs
`baseCost × L(L+1)/2`. Charging from a part-built site prices only the difference, and
building in two passes costs exactly what building in one does.

### Request

```http
POST /api/club/facilities/training
X-Club-Id: 019a...
Content-Type: application/json

{ "levels": { "training_pitch": 2, "strength_suite": 1 } }
```

`levels` is a map of facility slug → **target** level, and must be non-empty.

- **Omitted slugs are left alone, not zeroed.** A club can already hold facilities before this
  is called (`StarterConfig.defaultFacilities` seeds some at creation), so "absent means 0"
  would make the first real BUILD look like a downgrade of everything it didn't mention.
- **A target below the current level is refused** (`422 invalid_level`). There is no facility
  sell-back, so a lower number can only be a stale screen or a bug, and reporting success while
  charging nothing would leave client and server disagreeing about what the club has.

### Response `200`

```json
{
  "site": "training",
  "charged": true,
  "chargedPence": 400,
  "balancePence": 999600,
  "levels": { "training_pitch": 2, "strength_suite": 1 },
  "facilities": [
    { "slug": "training_pitch", "level": 2, "costPence": 300 },
    { "slug": "strength_suite", "level": 1, "costPence": 100 }
  ]
}
```

`levels` is what the site now **is**, re-read after the write — not an echo of the request. It
differs wherever a target was already met.

### Ledger types are per site

A charge is written as **`facility_training`**, **`facility_medical`**, **`facility_scouting`**
or **`facility_stadium`** — one `UserLedgerEntryType` each, not one shared `facility_build` with
the site buried in `sourceRef`. The app localises a ledger row on its type and ships an i18n key
per site, so a single type left four of those keys dead and every stand, pitch and clinic
labelled with whatever string the server happened to send.

`facility_build` still exists as the **fallback** for a category an admin adds later: a PHP enum
is fixed at compile time, so a fifth site bills correctly under the generic type (with a generic
label) rather than failing at the till.

### Idempotency — `(club, site)`, no header needed

The natural key, on the ledger's own `(sourceKind, sourceRef, type)` guard. No
`Idempotency-Key`: the client would have to persist one across an app kill for it to be worth
anything, and this cannot be got wrong.

| You send | You get |
|---|---|
| The same levels again | `200`, `charged: false`, `chargedPence` = the original charge. **No second debit.** |
| Different levels after a charge | `409 site_already_built`, with `builtLevels` and `chargedPence` so you can show what the player owns |
| Levels already all met, never charged | `200`, `charged: false`, `chargedPence: 0` — nothing to buy, so no £0 purchase is invented |

### Errors

| Status | `error` | When |
|---|---|---|
| `400` | `levels_required` | `levels` missing or empty |
| `402` | `insufficient_credit` | Carries `balancePence` and `requiredPence` |
| `404` | `unknown_site` | Carries `knownSites` |
| `409` | `site_already_built` | Carries `builtLevels`, `chargedPence` |
| `422` | `unknown_facility` | The slug is not an active facility of that site |
| `422` | `invalid_level` | Above `maxLevel`, negative, or below the current level. Carries `maxLevel` |
| `403` / `400` | — | `X-Club-Id` names another account's club / is missing |

---

## `POST /api/club/fund`

The atomic debit. Request:

```http
POST /api/club/fund
X-Club-Id: 019a...

{ "amountPence": 200000 }
```

Any positive amount up to the whole balance — "a partial sum or 100%". Response `200`:

```json
{
  "funded": true,
  "stakedPence": 200000,
  "balancePence": 300000,
  "minimumPence": 0,
  "projection": {
    "weeks": 13,
    "facilityUpkeepPerWeekPence": 1000,
    "facilityUpkeepPence": 13000,
    "wagesIncluded": false
  }
}
```

**`stakedPence` is what you seed local capital with.** It is also what a replay returns, so a
retry after a dropped response still gets the figure.

### The projection is half a projection, and says so

`wagesIncluded` is **always `false`**, and that is structural rather than unfinished:
`POST /api/market/assign` **deletes** players and staff from the pool when they are hired — "no
`club_id`" — so a club's roster exists only on the device. The server knows a club's facilities
and nothing about who it employs.

So the split is: the server returns facility upkeep; **the client sums wages itself** from
`GET /api/market/data` over `clubFundingProjectionWeeks` (published on `/api/starter-config`).
Staff candidates have always carried `weeklySalary`; **scouts now do too**, as of 2026-11-05 —
that was the gap that made a 13-week projection silently model every scout as free.

### No minimum unless an operator sets one

`minimumPence` is `0` by default, meaning **no floor**. `setup-flow.md` had proposed enforcing
one projected from running costs; what shipped computes and returns the projection but refuses
nothing, because refusing a stake the player was told they could make is a worse failure than
letting them underfund a club they can top up from a dividend later. An admin can set
`clubFundingMinimumPence`, and then a smaller stake is `422 below_funding_minimum` with
`minimumPence` in the body.

### Idempotency — `(club)`

| You send | You get |
|---|---|
| The same amount again | `200`, `funded: false`, the original `stakedPence`. **No second debit.** |
| A different amount | `409 already_funded`, carrying `stakedPence` |

### Errors

| Status | `error` | When |
|---|---|---|
| `400` | `amount_required` | `amountPence` missing or not a whole number |
| `400` | `invalid_amount` | Zero or negative |
| `402` | `insufficient_credit` | Carries `balancePence`, `requiredPence` |
| `409` | `already_funded` | Carries `stakedPence` |
| `422` | `below_funding_minimum` | Only reachable when an operator set one |

---

## `GET /api/club/setup-state`

The resume call after a mid-BUILD kill. **Every field is derived from what was actually billed
or built** — ledger rows and facility levels — not from progress flags, so it cannot disagree
with the money.

```json
{
  "clubId": "019a...",
  "balancePence": 999600,
  "grant": { "expectedPence": 550000000, "paidPence": 550000000 },
  "sites": {
    "training": { "charged": true,  "chargedPence": 300, "levels": { "training_pitch": 2 } },
    "medical":  { "charged": false, "chargedPence": 0,   "levels": { "physio_clinic": 0 } }
  },
  "funding": { "funded": true, "stakedPence": 150000, "minimumPence": 0 },
  "quiz": { "total": 3, "answered": 3, "correct": 2, "complete": true,
            "bonusPaid": true, "bonusPence": 366667 },
  "complete": true
}
```

- `complete` is **`funding.funded`** and nothing else. Builds and the quiz are optional.
- `grant.paidPence` is `null` until the owner avatar is saved, and `expectedPence` is what
  config says this account's grant is. **Do not compute an opening balance** — the grant is a
  range resolved per account, so a figure derived from config is wrong for almost every player
  (`setup-flow.md` #3).
- A site nobody built is present and reports `charged: false`, rather than being absent.
- `Cache-Control: private`, `max-age=0`. Never shared-cacheable.

This is deliberately **not** folded into `GET /api/club/check`, which is the cheap pre-club
routing call every cold boot makes.

---

## The quiz

`GET /api/setup-quiz` → questions plus this account's progress. **No club needed** — the bonus
multiplies the account's grant, so it can be taken before any club exists.

### Two shapes, both served

The response carries the **next unanswered question flattened onto the top level** as
`{id, question, options}`, *and* the full `questions` list. Both are correct and both are
supported:

- A client reading the single top-level question works unchanged. `id` is `null` when there is
  nothing left to answer — a finished quiz, not a stale repeat of the last question.
- `question` is an alias of `prompt`, sent on the top level and on every list item, so either
  key works.

**With exactly one question published the two readings are identical**: the proportional bonus
over a single question (`correct ÷ total` = 1) is the same figure as an all-or-nothing reading
of it. So an operator who publishes one question gets a quiz both implementations score the
same way, which is why this divergence was resolved additively rather than one side moving.

```json
{
  "id": "019a...",
  "question": "…",
  "options": ["…","…","…"],
  "questions": [
    { "id": "019a...", "prompt": "…", "options": ["…","…","…"], "answered": true,
      "chosenIndex": 0, "correct": true },
    { "id": "019b...", "prompt": "…", "options": ["…","…"], "answered": false }
  ],
  "total": 2, "answered": 1, "correct": 1, "complete": false,
  "bonus": { "paid": false, "pence": 500000, "multiplier": 1.0 }
}
```

- **The correct answer is never served.** No `correctIndex`, anywhere, on any response.
- The top-level `id`/`question`/`options` advance as questions are answered, so a resumed quiz
  picks up where it left off.
- `chosenIndex` / `correct` appear only on questions already answered.
- Before completion, `bonus.pence` is what the answers **so far** are worth — never a
  projection of a perfect score, which would show a number the player may not earn.
- **An empty `questions` array is valid**, not an error: no questions published means no quiz,
  so skip the step. `complete` stays `false` — there is nothing to complete.

`POST /api/setup-quiz/{id}/answer` with `{ "answer": 0 }` (an index into `options`):

```json
{ "questionId": "019a...", "correct": true, "answered": 2, "total": 2, "complete": true,
  "bonus": { "paid": true, "pence": 1000000, "multiplier": 1.0 } }
```

### What the bonus is worth

```
bonus = (that account's own starting grant) × quizBonusMultiplier × (correct ÷ total)
```

Proportional, so half marks pay half — no all-or-nothing cliff. At the default multiplier of
`1.0`, a perfect score doubles the grant.

**You cannot compute this figure**, which is the point: the grant is a per-account value
resolved from a range. `quizBonusMultiplier` is published on `/api/starter-config` so the screen
can *say* "double your grant"; the amount always comes from the ledger. `0` switches the bonus
off — the quiz still runs and still records answers.

**Paid on the last answer**, as part of that call — not on a separate finish call, which a
client killed at the wrong moment would never make.

### One attempt per account, ever

The server does not trust the 5-second timer. A question already answered is
`409 already_answered`, enforced by a unique index rather than a check that can be raced. The
bonus is written at most once per account however many times the last answer is submitted.

| Status | `error` | When |
|---|---|---|
| `400` | `answer_required` | `answer` missing or not a whole number |
| `400` | `invalid_answer` | Not one of the options offered. Carries `optionCount` |
| `404` | `question_not_found` | Unknown id |
| `409` | `already_answered` | This account has answered that question |
| `409` | `quiz_unavailable` | The question was withdrawn or is misconfigured |

A question whose `correctIndex` points past its options is **hidden** rather than served as
impossible, so a misconfiguration cannot quietly lower everyone's bonus.

---

## New client-visible error codes

An older build will not recognise these. Flagged rather than assumed to degrade gracefully, the
same way `setup-flow.md` flagged the enrichment codes:

`levels_required`, `amount_required`, `answer_required`, `invalid_amount`, `invalid_answer`,
`invalid_level`, `unknown_site`, `unknown_facility`, `site_already_built`, `already_funded`,
`below_funding_minimum`, `already_answered`, `quiz_unavailable`, `question_not_found`, and
`insufficient_credit` on these two new routes.

## Admin

- **Setup Quiz** — Configuration → Setup Quiz. Questions, options, the correct index,
  ordering and an active flag. Withdraw a bad question by deactivating it: answers reference it,
  and bonuses already paid were scored against it.
- **Quiz Bonus Multiplier**, **Minimum Club Stake**, **Funding Projection Weeks** — Starter
  Config.
- **Scout weekly wage range** — Staff Pool Config. Changing it does not reprice existing
  scouts; `app:backfill-scout-wages --force` does.
