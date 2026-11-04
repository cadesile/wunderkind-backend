# Land Plots API (client integration)

Branch: `development`. Backend-driven land purchase: a player picks a town, takes a
15-minute hold that freezes the price, then claims the plot and later builds a ground on
it.

Six endpoints, all under `/api/plots`. **All require authentication, none requires a
club.**

---

## 0. Two things to get right before writing any client code

### Money is ACCOUNT credit, not club balance

Land and stadium purchases are charged to the player's **account-level credit ledger**
(`UserLedger`), never to `club.balance`.

This is not a style preference. The device owns `club.balance` — `POST /api/sync` sends it
and the backend accepts it as authoritative — so anything the server deducted there would
be overwritten by the next sync. The ledger is the only durable balance, and it is
**cross-club**: it sums everything the account has ever earned across every club it owns.

That matters for the UI, because of the scale. Stadium prices are **real-world
construction costs** (~£1,500 per seat, so a 90,000-seat ground is ~£135M). Funding a
flagship city is expected to take the earnings of **ten to fifteen clubs** — the gap
between a price and a new club's balance is the long-term progression, not an error state.
Show prices against the ACCOUNT balance, and expect most plots to be unaffordable for a
long time.

Read the balance from `GET /api/account/ledger` (see `user-ledger.md` for the full response
shape — note it returns `type`/`amountPence`, **not** the `category`/`amount` that sync
sends). `402` responses below also carry it.

### Authentication, not club ownership

Every endpoint here is gated on `IS_AUTHENTICATED_FULLY`, **not** `ROLE_CLUB` — a player
can buy land before founding a club, and a claim can exist with no club attached. Do not
gate the UI on having a club.

---

## 1. `GET /api/plots/holds/mine`

The caller's live hold, if they have one. A player may hold **exactly one plot at a
time**.

```
GET /api/plots/holds/mine
Authorization: Bearer <jwt>
```
```json
200 OK
{
  "hold": {
    "holdId": "0193f0a1-...",
    "plotId": "0193f0a2-...",
    "locationId": "0193f0a3-...",
    "town": "Salford",
    "countryCode": "EN",
    "landPence": 500000000,
    "stadiumPence": 1620000000,
    "totalPence": 2120000000,
    "expiresAt": "2026-11-03T14:32:11+00:00",
    "secondsRemaining": 847
  }
}
```

`"hold": null` when there is none. Sent `private, max-age=0` — never cache it, the
countdown is the point.

| Field | Meaning |
|---|---|
| `landPence` | The land price **frozen at the moment of the hold** |
| `stadiumPence` | The stadium build price, likewise frozen |
| `totalPence` | `landPence + stadiumPence` |
| `secondsRemaining` | Counts down to 0. Drive your timer from this, not from `expiresAt` minus the device clock — the device clock may be wrong. |

---

## 2. `POST /api/plots/{plotId}/hold`

Take a 15-minute exclusive option on a plot, freezing both prices.

```
POST /api/plots/0193f0a2-.../hold
```
```json
201 Created
{ "hold": { ...as above... } }
```

**The frozen price is a real guarantee.** If an operator revalues the town's land while
the hold is live, the claim still charges what the hold quoted. The client can show the
quoted figures with confidence for the full 15 minutes.

### Errors

| Status | `error` | Meaning / what to do |
|---|---|---|
| `404` | `plot_not_found` | Unknown plot id. |
| `409` | `plot_held` | Someone else is holding it. **Transient** — holds last 15 minutes, so offer a retry. |
| `409` | `hold_limit` | The caller already holds another plot. Response includes `heldTown`; offer to release it. |
| `409` | `plot_claimed` | Already owned. Response includes `claimedBy`. |
| `422` | `plot_not_sellable` | Permanent-ish: the town is retired, not yet located, not yet valued, or its country is not open to players. `why` explains which. |

Every error body is `{ "error": "<code>", "message": "<human readable>", ...context }`.
The `message` is written to be shown to a player; the `error` code is what you branch on.

---

### A town can be full

`POST /api/plots/{plotId}/hold` returns **`409 town_sold_out`** when the town has no capacity
left, even though the plot itself is unclaimed.

A town holds as many clubs as its size allows — plots scale with city size (`BIG` 4, `MEDIUM` 2,
`SMALL` 1, derived from the enriched population). Established clubs take up to **two** of them
between them however many there are, and players buy what remains:

```
available = plotCount − min(clubsHere, 2) − plotsAlreadyBought
```

The error carries `plots`, `npcOccupied` and `claimed`, so the client can say *"Smallham is
full: 1 plot, 1 taken by established clubs"* rather than just "unavailable".

**Check `claimable` on the location first** (`locations.md`) — it is the identical calculation,
so the list and the refusal cannot disagree. Note the ordering: if the specific plot is already
owned you get `409 plot_claimed` instead, which is the more useful answer.

---

## 3. `DELETE /api/plots/holds/{holdId}`

Give up a hold early, freeing the plot for others immediately.

```json
200 OK
{ "released": true }
```

`"released": false` means there was nothing live to release — already expired, already
used, or already cancelled. **This is not an error**: pressing cancel twice is ordinary,
and the end state is what the player wanted either way.

`403 hold_not_yours` if the hold belongs to another account.

**You never need to release an expired hold.** Expiry is handled server-side; an expired
hold blocks nobody and is invisible to every read.

---

## 4. `POST /api/plots/{plotId}/claim`

Buy the land. Requires a live hold, named explicitly in the body.

```
POST /api/plots/0193f0a2-.../claim
Content-Type: application/json

{ "holdId": "0193f0a1-..." }
```
```json
201 Created
{
  "claim": {
    "claimId": "0193f0b1-...",
    "plotId": "0193f0a2-...",
    "locationId": "0193f0a3-...",
    "town": "Salford",
    "region": "Greater Manchester",
    "countryCode": "EN",
    "claimedBy": null,
    "clubId": null,
    "claimedAt": "2026-11-03T14:20:03+00:00",
    "landPencePaid": 500000000,
    "stadiumPencePaid": 0,
    "totalPaidPence": 500000000,
    "hasStadium": false
  }
}
```

The hold id is required rather than inferred from the account, so a stale client cannot
spend a hold the player has since taken on a different plot.

`landPencePaid` is **what was actually charged** and is never recomputed — land is
revalued over time, and a later sell-back needs both the original figure and the current
one.

### Errors

| Status | `error` | Meaning |
|---|---|---|
| `400` | `hold_required` | No `holdId` in the body. |
| `402` | `insufficient_credit` | Not enough **account** credit. Carries `balancePence` and `requiredPence` — render the shortfall. |
| `403` | `hold_not_yours` | The hold belongs to another account. |
| `409` | `hold_consumed` | Already used. A double-tapped buy lands here; **nothing was charged twice.** |
| `409` | `plot_claimed` | Lost the race to another buyer. **Nothing was charged** — the ledger write happens only after the claim succeeds. |
| `409` | `hold_plot_mismatch` | The hold is for a different plot than the URL names. |
| `410` | `hold_expired` | Take a new hold to see the current price. |

---

## 5. `POST /api/plots/claims/{claimId}/stadium`

Build the ground on a plot already owned. Charged at the price the **claim** was made
against, so a player is not penalised for a model change since.

```json
200 OK
{ "claim": { ...with "hasStadium": true and "stadiumPencePaid" set... } }
```

Calling it on a claim that already has a stadium is a **no-op that returns `200`** — it
does not charge twice.

| Status | `error` |
|---|---|
| `404` | `claim_not_found` |
| `403` | `claim_not_yours` |
| `409` | `claim_released` — the plot is no longer owned |
| `402` | `insufficient_credit` (with `balancePence` / `requiredPence`) |

---

## 6. `GET /api/plots/claims/mine`

Every plot the account owns.

```json
200 OK
{ "claims": [ { ...claim object as above... } ] }
```

Sent `private, max-age=0`.

`claimedBy` is the club name **frozen at the moment the club was attached**, so it keeps
rendering even after that club has been deleted. `clubId` is `null` for a plot bought
before founding a club, or whose club has since gone.

---

## 7. Founding a club on a plot you own

Owning land and having a club are separate things, in that order. `POST
/api/club/initialize` takes an **optional** `locationId` to tie them together:

```json
POST /api/club/initialize
{ "clubName": "Salford FC", "locationId": "0193..." }
```

- **Supplied** — the caller must already hold an **active claim with no club on it** at that
  location. The club is created, `home_location_id` is set, and the claim is pointed back at
  the club in the same transaction. The `201` response echoes `homeLocationId`.
- **Omitted** — exactly the previous behaviour. A club with no ground is still a club, and
  `homeLocationId` comes back `null`.

This endpoint **does not buy land**. It only attaches a club to land already bought, which is
what keeps every money path in the claim endpoints.

The new club's `balance` is still the standard starter balance from `GET /api/starter-config`.
**The land is not deducted from it** — land came out of account credit at claim time, and
`club.balance` is device-authoritative anyway (see §0).

The location also settles the club's `country`, so there is no need to send both. An explicit
`country` is respected and not overridden.

### Errors

| Status | `error` | Meaning |
|---|---|---|
| `403` | `location_not_owned` | You hold no active claim there. Deliberately does **not** say whether anyone else does. |
| `409` | `location_club_exists` | Your claim there already has a club. Carries `clubName`. |
| `422` | `club_name_taken` | That name is already used **at that location** (or by an NPC club in the country). The existing error code — nothing new to handle. |

Club names are unique **per town**, not per country: two "United FC"s are fine in two
different towns and refused in the same one. Comparison ignores case, accents and repeated
spaces, matching `src/utils/clubName.ts`. Clubs with no location never collide with each other.

---

## 8. Selling a plot back, and the 30-day dormancy reclaim

### `GET /api/plots/claims/{claimId}/sell-back-quote`

What selling would pay, without selling. Same calculation the sale itself uses, so the figure
shown and the figure paid cannot disagree. Writes nothing; `private, max-age=0`.

```json
{
  "claim": { "...": "..." },
  "quote": {
    "payoutPence": 457000,
    "land":    { "component": 450000, "currentLandPence": 500000, "landPencePaid": 480000,
                 "basis": "current_valuation", "returnRate": 0.9 },
    "stadium": { "component": 0, "stadiumPencePaid": 0, "returnRate": 0.5 },
    "club":    { "component": 7000, "clubId": "0193...", "clubName": "Salford FC" }
  }
}
```

### `POST /api/plots/claims/{claimId}/sell-back`

```
payoutPence = round(currentLandPence x 0.9)      # current value, NOT what was paid
            + round(stadiumPencePaid x 0.5)
            + the club's value                   # when a club stands on the plot
```

Four things worth knowing:

- **Land is paid at its CURRENT value, not what was paid.** Land is revalued over time, so a
  plot can gain or lose. The missing 10% is a transaction fee, so churning plots has a cost.
  Show the quote, not an assumption. If a location has no current valuation (un-enriched, or a
  valuation withdrawn), the payout falls back to what was paid — `quote.land.basis` says which
  rule applied.
- **It liquidates the club.** The club loses its ground, so the payout includes the club's
  value — its last-synced balance plus its sunk facility investment. Paying only for the land
  would confiscate everything built on the site. The club row itself is kept; only its home
  location is cleared. (Its **squad is not** in the valuation: there is no market-value field on
  a player, and the one that looks like it is a wage.)
- **The club's value is as of its last sync**, because `club.balance` is device-authoritative.
  Worth saying out loud in the confirm screen.
- **The credit is account-level** — a `sell_back_credit` entry with `club: null`, visible at
  `GET /api/account/ledger`. The response carries the new `balancePence`.

Errors: `404 claim_not_found`, `403 claim_not_yours`, `409 claim_released` (someone or something
already released it — a dormancy reclaim, say).

### The 30-day dormancy reclaim

A plot whose club has not synced for **30 days** is released automatically and **the owner is
credited exactly what a voluntary sell-back would have paid.** They lose the plot, not the money,
so coming back after a break is recoverable.

That equality is deliberate. If a reclaim paid *more* than selling, going quiet would be the
optimal play — the opposite of what the rule is for.

Two warnings arrive in the club inbox first, at **23 days** (`7 days left`) and **29 days**
(`1 day left`), each with `offerData.kind = "dormancy_warning"` carrying `stage`, `claimId` and
`daysRemaining`. **A plot with no delivered one-day warning is never reclaimed** — if the warning
pass is behind, the reclaim stalls rather than taking plots silently.

A single sync resets the clock. The warning schedule re-arms per period of inactivity, so a
player who returns and later lapses again is warned again rather than silently skipped.

**Claims with no club are never warned or reclaimed**, because the clock is the club's sync
time. Land bought and never built on is held indefinitely.

---

## 9. Recommended client flow

```
1. Player opens a town                -> GET /api/locations/{id}   (see locations.md)
2. Player taps HOLD PLOT              -> POST /api/plots/{plotId}/hold
                                         -> start a timer from secondsRemaining
3. Player reviews the frozen prices   (no further calls; the figures cannot move)
4. Player confirms                    -> POST /api/plots/{plotId}/claim  { holdId }
5. Player founds the club             -> POST /api/club/initialize { clubName, locationId }
6. Later, player builds a ground      -> POST /api/plots/claims/{id}/stadium
7. Player wants out                   -> GET  /api/plots/claims/{id}/sell-back-quote
                                         -> POST /api/plots/claims/{id}/sell-back
```

Handle `409 plot_held` at step 2 by offering a retry, and `402` at steps 4 and 5 by
showing the shortfall against the account balance. Both are expected outcomes in normal
play rather than exceptional ones — the second especially, because prices are real-world
and the whole point is that a flagship city takes many clubs to afford.

---

## 10. Not covered by this document

- `GET /api/locations`, `/facets` and `/{id}` — the town list, filters and detail, including
  rivals, affordability and `isClaimed`. See `locations.md`.
- The account credit ledger and `GET /api/account/ledger` — see `user-ledger.md`.
- `starting_grant` and `quiz_bonus`. The ledger entry types exist; nothing writes them yet, and
  there is no endpoint or defined grant amount. Prices stay real-world in the meantime.
