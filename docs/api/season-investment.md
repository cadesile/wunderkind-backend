# Owner season investment — `GET`/`POST /api/club/season-investment`

Added 2026-11-12. **Ships switched off**: the base ceiling is 0, so every `POST` is refused with
`422 above_season_ceiling` until an operator authors a figure on World → Economy.

The owner's account balance is separate from the club's. Once a season, the owner may gift money
from it into one of their clubs, up to a ceiling that scales with the club's country and tier.

## ⚠️ The server debits; the DEVICE credits

`creditClubPence` in the response is an **instruction, not a report**.

The debit from `UserLedger` is real and durable. The credit to `club.balance` is applied by the
device. The server cannot do it: `SyncService::process()` assigns `club.balance` absolutely from
the device on every sync, so a server-side credit would look right until the next sync and then
vanish — with no server-side authority left to repair it from. Same reason land and stadium
purchases are charged to account credit and never to `club.balance`.

A client that ignores `creditClubPence` loses the money the player just spent.

## Which season it is — and why you cannot say

**The season is not a request parameter and cannot be one.**

It comes from the count of this club's `SeasonRecord` rows, plus one. Those are written only by
`POST /api/league/conclude-season`, one per `(club, season)` behind a unique constraint, so the
count advances by at most one per actually-concluded season.

`club.currentSeason` is deliberately **not** used, because it is device-mirrored — the sync
payload overwrites it. A client that could name its own season would both raise its own ceiling
(the ceiling grows with the inflation index) and unlock windows it never played. A device
claiming season 50 writes one `SeasonRecord` and moves the count from *n* to *n+1*.

So: conclude a season, get a new window. No rollover, and skipping is allowed — each season's
ceiling stands alone.

## The ceiling

```
ceilingPence = basePence × countryFactor × tierFactor × inflationIndex(season)
```

**This is the only place the backend applies inflation itself.** Everything else it publishes is
base-year and the app compounds the index — but a ceiling has to be checked against the season
being claimed for, or a save twenty seasons in would be held to year-one money.

The response carries `ceilingPence`, `remainingPence`, `countryFactorBp` and `tierFactorBp` so
the figure can be *explained* rather than just enforced. The app is never told a country factor
in any other context, so a refusal without the resolved ceiling would be a dead end — the player
could not be shown what they *can* give.

A club with **no league** has no tier to scale by and gets a ceiling of 0.

## `GET` — what the owner may invest

Read-only. Returns the same shape the `POST` does, so one code path renders the screen either
way. `creditClubPence` is `0` — nothing happened.

```json
{
  "invested": false,
  "season": 3,
  "investedPence": 0,
  "ceilingPence": 10000000,
  "remainingPence": 10000000,
  "balancePence": 184000000,
  "countryFactorBp": 10000,
  "tierFactorBp": 10000,
  "tier": 4,
  "creditClubPence": 0
}
```

## `POST` — invest

```json
{ "amountPence": 2500000 }
```

Optionally `clubId`; otherwise the `X-Club-Id` header decides which save, through `ClubResolver`
— naming someone else's club is a `403`, not a write onto the wrong save.

**Idempotent on `(club, season)`.** The same amount twice returns `200` with `invested: false`,
the original figure, and no second debit. A *different* amount is `409` — a season is invested in
once, and the second request is a new gift wearing the first one's key. Idempotency is the
ledger's `(sourceKind, sourceRef, type)` unique index with `sourceRef = "{clubId}:{season}"`, the
same natural key `POST /api/club/fund` uses rather than an `Idempotency-Key` header.

**The replay check runs before the ceiling check**, deliberately: an operator lowering the
ceiling must not turn a client's harmless retry into a permanent failure for a debit that already
happened.

## Error codes

| Status | `error` | When |
|---|---|---|
| `400` | `amount_required` | `amountPence` missing, or not a whole number of pence |
| `400` | `invalid_amount` | zero or negative |
| `402` | `insufficient_credit` | the account balance does not cover it — carries `balancePence` and `requiredPence` |
| **`409`** | **`already_invested_this_season`** | a *different* amount for a season already invested in — carries `investedPence` and `season` |
| **`422`** | **`above_season_ceiling`** | over the resolved ceiling — carries `amountPence`, `ceilingPence`, `season` |

**The two bold codes are new and client-visible.** An older build will not recognise either;
flagged here rather than assumed to degrade gracefully, per
`.context/shared/api-backwards-compatibility.md`. Both are **unreachable while the base ceiling
is 0**, which is the shipped state — the same way `429 enrichment_quota_exhausted` shipped behind
a cap of 0 = unlimited.

`402` agrees with `POST /api/club/fund` and `PlotClaimController`: one meaning for one code
across every way money leaves an account.

## The ledger row

`UserLedgerEntryType::SEASON_INVESTMENT` (`season_investment`), label "Season investment",
**stored negative** — `isDebit()` is true and `UserLedgerService::append()` refuses a positive
amount. It is **not** club earnings, so it never reaches the dividend aggregates or the public
Rich List; both filter on type precisely so a negative purchase row cannot corrupt a leaderboard.

It appears in `GET /api/account/ledger` like any other entry.

## Turning it on

World → Economy, "Owner season investment". Set the base ceiling in pounds; `0` or blank switches
it back off. The resolved per-tier ceilings are visible in the same screen's resolver preview via
the country and tier factors.
