# Leaderboard awards — periodic prizes for topping a chart

**Audience:** `wunderkind-app`.
**Status:** backend shipped 2026-10-31.

**Nothing to integrate, and that is the headline.** An operator can now configure a
leaderboard to pay a prize at the end of each period — weekly, monthly or quarterly, to a
configurable number of places. A win reaches the player as an ordinary inbox message with
`requiresAccept: true`, through `GET /api/inbox` and `POST /api/inbox/{id}/accept`.

**There is no new endpoint. Neither inbox endpoint changed in any way.** If your client
already renders an acceptable competition reward, it already renders this one. Everything
below is so you can style it specifically if you want to, and so the push payload is
documented.

---

## How a win happens

A period board is a **growth** board: it ranks what each club gained during the period, not
its all-time total (see `docs/api/leaderboard.md` → "A period board is a GROWTH board"). When
the period ends, the server freezes that board, pays the configured places, and prunes the
live rows.

So the player's experience is: they were on a monthly chart, the month ended, and a prize
arrived in their inbox. Nothing is claimed from the leaderboard screen, and there is no claim
window or expiry.

## The inbox message

```json
{
  "id": "019f3282-491b-75c7-9ea7-c7f84712b2b1",
  "senderType": "competition",
  "senderName": "Golden Boot of the Month",
  "subject": "You placed 1st — Golden Boot, October 2026",
  "body": "München 1. FC finished 1st in Golden Boot of the Month for October 2026 with a score of 25. Accept to claim your prize.",
  "type": "LEADERBOARD_AWARD",
  "requiresAccept": true,
  "relatedEntityType": "LeaderboardPeriodArchiveEntry",
  "relatedEntityId": "019f3284-0a11-7bc2-9f10-55c2e4a1b733",
  "offerData": {
    "type": "LEADERBOARD_AWARD",
    "link": { "kind": "leaderboard", "category": "golden_boot", "period": "2026-M10" },
    "effects": [
      { "type": "ledger_delta", "amountPence": 500000 },
      { "type": "reputation_delta", "amount": 25 }
    ],
    "category": "golden_boot",
    "categoryLabel": "Golden Boot",
    "period": "2026-M10",
    "periodLabel": "October 2026",
    "cadence": "monthly",
    "place": 1,
    "score": 25,
    "archiveEntryId": "019f3284-0a11-7bc2-9f10-55c2e4a1b733"
  }
}
```

### Every field

| Field | Type | Notes |
|---|---|---|
| `senderType` | `"competition"` | **Not a new sender type.** See the note below — this is deliberate, and it is not going to change. |
| `senderName` | string | The operator's own name for the award, e.g. `"Golden Boot of the Month"`. Free text; don't parse it. |
| `type` / `offerData.type` | `"LEADERBOARD_AWARD"` | **The discriminator to key off.** This is what distinguishes a leaderboard award from a competition win. |
| `requiresAccept` | `true` | Derived from `offerData.effects` being non-empty, exactly as for a competition reward. |
| `offerData.effects` | array | The prize. Same `GameEffect` shapes you already handle: `ledger_delta` (`amountPence`), `reputation_delta` (`amount`), `unique_asset_grant` (recorded but not yet delivered). |
| `offerData.link` | object | `{kind: "leaderboard", category, period}` — enough to deep-link to the board that was won. |
| `offerData.category` | string | A leaderboard category slug (`docs/api/leaderboard.md`). |
| `offerData.categoryLabel` | string | A display label for it, so you don't have to map the slug. |
| `offerData.period` | string | The period key — `2026-W44` / `2026-M10` / `2026-Q4`. |
| `offerData.periodLabel` | string | A display label, e.g. `"October 2026"`, `"Week 44, 2026"`, `"Q4 2026"`. |
| `offerData.cadence` | `"weekly"` \| `"monthly"` \| `"quarterly"` | |
| `offerData.place` | int | 1-based. `1` is the winner. |
| `offerData.score` | int | The **growth** value as ranked, not a cumulative total. Units follow the category (see the leaderboard doc's category table — `transfer_record` and friends are pence). |
| `offerData.archiveEntryId` | string (UUID) | The frozen archive row. Also mirrored as `relatedEntityId`, with `relatedEntityType: "LeaderboardPeriodArchiveEntry"`. There is no client endpoint for it — it is a stable id for support and for correlating a push with its message. |

### Why `senderType` is `competition`

It reads like system mail, and it deliberately isn't.

The server's accept path routes only `competition` and `friendly` messages to the code that
applies `offerData.effects`. A `system` message carrying effects would still render
`requiresAccept: true` and then apply nothing — the player taps **Accept** and gets nothing,
with no error anywhere.

A brand-new sender type was also rejected: a value a shipped client has never seen changes how
the message renders, and `competition` already means "you won something, accept to claim".
The semantic distinction lives in `offerData.type`, which is the field clients are documented
to key off anyway.

**So: branch on `offerData.type`, never on `senderType`.**

## Accepting

`POST /api/inbox/{id}/accept`, unchanged. The effects apply to the club exactly as a
competition reward's do, and the response is the same shape.

The server never credits the club directly on its own schedule — it can't, because the next
sync overwrites the balance with the client's own figure. **The money only exists once the
player accepts**, which is what makes the subsequent sync correct.

Two consequences:

- An unaccepted award is not lost and does not expire. It sits in the inbox.
- Until the player accepts, the prize is in no balance anywhere. Don't pre-credit it
  optimistically.

## Push notification

One push per winner, sent after the close has committed.

```json
{
  "title": "You placed 1st — Golden Boot, October 2026",
  "body": "München 1. FC placed 1st on Golden Boot of the Month. Open your inbox to claim the prize.",
  "data": {
    "type": "LEADERBOARD_AWARD",
    "category": "golden_boot",
    "period": "2026-M10",
    "place": "1",
    "archiveEntryId": "019f3284-0a11-7bc2-9f10-55c2e4a1b733"
  }
}
```

`type` is uppercase, matching `ROUND_DRAWN` / `COMPETITION_COMPLETED` / `MATCH_RESULT`. Every
`data` value is a string, as FCM requires — note `place` in particular.

An unrecognised `type` is contractually a no-op that still displays, so **no client release is
needed** for this to be safe. Handling it just lets you deep-link to the inbox instead of the
default destination.

## What this does not do

- **No new endpoint**, and no change to `GET /api/inbox` or `POST /api/inbox/{id}/accept`.
- **No claim window, no expiry, no second claim.** The award is written once; a re-run of the
  server-side close produces no second message.
- **No `UserLedger` entry.** That ledger is a club→owner dividend record; a prize paid into a
  club balance is not an owner-level balance change, so an award never appears there and never
  moves the owner's centralized balance. If you surface that balance, a leaderboard win will
  not change it.
- **No archive endpoint.** Past boards are read through the existing
  `GET /api/leaderboard/{category}?period=<closed key>`, which serves them from the archive —
  see `docs/api/leaderboard.md`.
