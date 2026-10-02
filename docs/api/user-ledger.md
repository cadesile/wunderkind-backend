# User Ledger / Dividend Draws (client integration)

Now that a single owner avatar persists across every club one account owns (see
`docs/api/owner-avatar.md`), the backend centralizes a user's dividend draws across clubs
instead of leaving each one trapped inside whichever club's own `totalCareerEarnings` it
happened to land on. This is **new, additive** surface — no existing sync field changes
shape, nothing is removed.

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
