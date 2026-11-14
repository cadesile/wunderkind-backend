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

## Reading the balance back — `GET /api/account/ledger`

> This section previously said no read endpoint existed. It does now. The land step needs a
> balance to show against a plot price, so this shipped alongside `GET /api/locations`.

`GET /api/account/ledger` — requires a fully authenticated user (**not** `ROLE_CLUB`: credit
exists before a club does). Never cached: `Cache-Control: private, max-age=0`.

### Query parameters

| Param | Default | Notes |
|---|---|---|
| `limit` | `50` | Clamped to 1–200. |
| `cursor` | — | Opaque keyset cursor from a previous response's `nextCursor`. A malformed one is a `400 invalid_cursor`, never a silent restart from the top. |

### Response

```json
{
  "balancePence": 4850000,
  "entries": [
    {
      "id": "0193...",
      "type": "land_purchase",
      "label": "Land purchase",
      "amountPence": -150000,
      "balanceAfterPence": 4850000,
      "club": null,
      "description": null,
      "occurredAt": "2026-11-04T10:22:31+00:00",
      "recordedAt": "2026-11-04T10:22:31+00:00"
    }
  ],
  "nextCursor": "cHJpY2V8..."
}
```

### The field names differ from the inbound sync contract, deliberately

This is the one thing to get right when integrating. **Writing** a ledger entry (via `POST
/api/sync`, above) uses `category` and `amount`. **Reading** one back uses `type` and
`amountPence`. They are not interchangeable:

| Concept | Inbound (`POST /api/sync`) | Outbound (`GET /api/account/ledger`) |
|---|---|---|
| Kind of entry | `category` | `type` |
| Money | `amount` | `amountPence` |
| Free text | `description` | `description` |

Three reasons the read shape is spelled this way, so nobody "harmonises" the two later:

1. `UserLedger`'s own properties and the admin grid's labels already use these names.
2. `...Pence` is the convention for every endpoint built in this era (`/api/plots/*`,
   `/api/telemetry`, `/api/excursions`).
3. A bare `amount` key already means a row **count** elsewhere in this API
   (`GET /api/players`), so reusing it for money would be actively misleading.

### Other things worth knowing

- **`amountPence` is signed.** Purchases are negative. `balanceAfterPence =
  balanceBeforePence + amountPence` is a constructor invariant, so the running total is
  reconstructible from history alone.
- **`club` may be `null`**, and routinely is. A starting grant, a quiz bonus, or a plot bought
  before founding a club are facts about the ACCOUNT. `club` is also null once the club it
  referred to has been deleted — which is why `plot_claim` freezes `club_name_at_claim`
  rather than reading an owner's name back through here.
- **No unit rescaling happens here.** The 100x inbound scaling noted above applies to what the
  client *sends*; this endpoint reports the stored figure, which is what `balancePence` is
  actually computed from.
- **Ordering is by `recordedAt` then `id`**, which is the same ordering `getCurrentBalance()`
  uses — so `balancePence` always equals `entries[0].balanceAfterPence`. `recordedAt` is the
  historical server timestamp even for backfilled rows, so this is a true chronology.
- **Pagination is keyset, not offset.** A ledger grows at the head, so an offset window shifts
  under a client that is paging through it.

## Error responses

None specific to this feature. `ledger[]` entries are free-form and unvalidated server-side
(same trust model as every other category) — an unrecognized `category`, or one that fails
to parse as expected, is silently ignored rather than rejecting the sync.

---

## Addendum — new entry types (last updated 2026-11-05)

`type` is no longer always `dividend_draw`. Thirteen more exist, written by the backend rather
than read out of a sync payload:

| `type` | Sign | Written when |
|---|---|---|
| `starting_grant` | positive | A one-off credit granted to a new account |
| `quiz_bonus` | positive | Credit earned from the onboarding quiz |
| `land_purchase` | **negative** | Buying a land plot (see `land-plots.md`) |
| `stadium_purchase` | **negative** | Building a ground on a claimed plot |
| `sell_back_credit` | positive | Proceeds of selling a plot back |
| `facility_training` | **negative** | Building out the training site at setup (see `club-setup.md`) |
| `facility_medical` | **negative** | Building out the medical site at setup |
| `facility_scouting` | **negative** | Building out the scouting site at setup |
| `facility_stadium` | **negative** | Building out the stadium site at setup |
| `facility_build` | **negative** | A facility site with no type of its own — only reachable if an admin adds a new category |
| `club_funding` | **negative** | The opening capital staked into a new club at setup |
| `club_sale` | positive | An operator bought one of the account's clubs out (see below) |
| `custom_income` | **either** | An operator credited or deducted the account by hand |
| `reversal` | either | A support reversal of an earlier entry |

**One type is localised per site, deliberately.** The four `facility_*` values exist as separate
types rather than one value carrying the site, because the client localises a row on its `type`
and ships a copy key per site.

**`club_sale` is paid for a club that no longer exists.** An operator can buy a club out of an
account: the credit lands here and the club and all of its history are then permanently deleted.
Three things a client must handle, and only this type combines them:

- **`club` is always `null`** on these rows, deliberately — the credit is an account-level fact
  that outlives the club it paid for, so there is nothing to attribute it to.
- **The club is gone by the time the row is readable.** Any local cache keyed on that club id
  should be dropped; its squad, season records, awards and tournament entries no longer exist
  server-side. A sync for that club will not find it.
- **`description` is free text an operator typed**, worded for the player, and its `label` is
  `"Club sale"`. If no note was written the server falls back to `"Club sale — <club name>"`,
  which is the only place that club's name still appears.

It is **not** counted as club earnings, so it does not appear on the career-earnings
leaderboard. A mistaken buyout is corrected with a `reversal` row, never by editing this one.

**`custom_income` is the one an operator authors.** Two things follow from that and from nothing
else in this table: its sign varies **per row**, so it cannot be assumed either way; and its
`description` is free text a human typed, rather than a fixed server string. Its `label` is
`"Adjustment"`.

Three consequences for a client:

- **`amountPence` is signed, and purchases are negative.** Do not take the absolute value
  when rendering a running balance.
- **`club` may be `null`.** Grants, bonuses and a plot bought before founding a club are
  facts about the ACCOUNT, not about any club. It is also nulled when a club is deleted —
  the entry survives, because the balance is a chain and removing rows would roll it back.
- **The balance can be large.** Stadium prices are real-world (~£135M for a flagship
  ground) and this balance deliberately accumulates across every club the account owns,
  so size your formatting accordingly. The underlying columns are 64-bit.

A reversal never edits the entry it corrects: it is a new, opposing row. Both remain
visible in history.
