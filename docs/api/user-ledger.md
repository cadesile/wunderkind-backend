# User Ledger / Dividend Draws (client integration)

Now that a single owner avatar persists across every club one account owns (see
`docs/api/owner-avatar.md`), the backend centralizes a user's dividend draws across clubs
instead of leaving each one trapped inside whichever club's own `totalCareerEarnings` it
happened to land on. This is **new, additive** surface — no existing sync field changes
shape, nothing is removed.

## What reads the ledger

The ledger is now the source of truth for owner earnings. Three surfaces read it:

| Surface | Reads | Notes |
|---|---|---|
| `GET /api/leaderboard/career_earnings` | `UserLedgerRepository::sumDividendsByClub()` | Total drawn per club. Now an **aggregate** category, so a draw reaches the board on the next `app:leaderboards:generate` run (every 5 min), not instantly at sync time. |
| Landing page "Rich List" | `UserLedgerRepository::findTopOwnersByBalance()` | Each owner's latest `balanceAfterPence`, i.e. exactly what `getCurrentBalance()` returns. Owners with no entries are absent, not listed at zero. |
| Admin club profile / user edit | `getTotalDividendsByClub()` | Headline figure; the legacy accumulator is shown alongside for comparison. |

### `Club.totalCareerEarnings` is deprecated

That column is a client-supplied running total of *every* income a club has ever had —
a much looser thing than money the owner actually drew out. Nothing server-side ranks or
reports on it any more.

It is **still written on every sync and still emitted** by `GET /api/club/status` and the
sync response, because the shipped client reads it. Removing the field is a separate,
later step that needs a coordinated wunderkind-app release. Don't add new readers.

One stream deliberately stays outside the ledger: the server-side manager dividend
`EconomicService::processFinancialYearEnd()` computes at each financial year end still
goes only to `totalCareerEarnings` and writes no `UserLedger` row (explicit product
decision). The ledger therefore reflects client-reported `dividend_draw` entries and
nothing else.

### Expect it to be sparse at first

`dividend_draw` is a new category, so there is very little history: at the time of the
switch a representative production copy held **one** ledger entry across 162 owners. Both
the Rich List and the `career_earnings` board are correspondingly near-empty and fill in
as clients report draws. Pre-existing `career_earnings` rows carrying old accumulator
figures are explicitly zeroed by `LeaderboardCalculationService` rather than left
standing, so they cannot outrank real draws.

## Reporting a draw — via the existing sync ledger

There is no new endpoint for this. A dividend draw is reported exactly like any other
financial event: as an entry in the `ledger[]` array you already send on
`POST /api/sync`.

```json
POST /api/sync
{
  "weekNumber": 12,
  "clientTimestamp": "2026-10-02T14:00:00Z",
  "ledger": [
    { "category": "dividend_draw", "amount": 25000, "description": "Season dividend" }
  ]
}
```

| Key | Type | Notes |
|---|---|---|
| `category` | `string` | Must be exactly `"dividend_draw"` (case-sensitive) for this to be picked up. |
| `amount` | `int` | **Real pence — see the units note below.** `25000` = £250.00. |
| `description` | `string` | Optional free text, shown verbatim on the admin audit trail. |

The draw is attributed to whichever club the sync payload itself targets (the same
`clubId` field / `X-Club-Id` resolution every other sync-time field already uses) — send it
in the same sync call as the club whose accumulated earnings the draw is coming from.

## ⚠️ Units: `amount` is REAL pence here, unlike every other `ledger[].category`

This is the one thing to get right. Every *other* `ledger[].category` your existing
on-device ledger-writing code emits (`upkeep`, `wages`, `matchday_income`,
`sponsor_payment`, `investor_income`, `fan_initiative`, …) is sent at **100x true pence** —
a known, longstanding bug in that shared writer, confirmed and documented backend-side
(`sync_record.payload` field conventions). `dividend_draw` is a brand-new category with no
existing client code behind it, so it was deliberately specified as **correctly-scaled real
pence from day one** rather than inheriting that bug — same convention `promises[].offer.amountPence`
already uses. **Do not route this value through whatever helper your existing ledger-writer
uses to produce the other categories' inflated amounts** — compute it directly as real pence
(e.g. `Math.round(poundsDrawn * 100)`), the same way you'd compute any other genuinely-scaled
pence field in the payload.

If this ships inflated by 100x, every dividend recorded against the user's balance will be
wrong by two orders of magnitude — there is no server-side correction applied to this
category, by design, so get a real payload checked against the admin ledger (see below)
before relying on it.

## What happens server-side

- Every sync is scanned for `dividend_draw` entries; each one becomes a `UserLedger` row
  recording the amount, which club it came from, the in-game date (`clientTimestamp`) and
  real-world date the server received it, and a snapshot of the user's overall
  cross-club balance immediately before and after that entry.
- `amount: 0` (or the key omitted) is treated as a no-op — nothing is recorded.
- Re-sending the exact same sync payload (a network retry) does not double-count a draw
  that sync already reported, so retries are safe.
- Multiple `dividend_draw` entries in one sync are each recorded in array order, chaining
  the running balance across them.

## Reading the balance back — not yet available to the client

**There is currently no `GET` endpoint exposing a user's centralized balance or draw
history to the app.** The data exists (visible today only in the admin panel, at
`/admin/user/{id}/edit`), but nothing under `/api/` surfaces it yet. If the app needs to
display "Owner Balance: £X" or a draw history screen, a new read endpoint needs to be
designed and built — flag this back if/when that's needed; it isn't blocked on anything
above, just not built yet.

## Error responses

None specific to this feature. `ledger[]` entries are free-form and unvalidated server-side
(same trust model as every other category) — an unrecognized `category`, or one that fails
to parse as expected, is silently ignored rather than rejecting the sync.
