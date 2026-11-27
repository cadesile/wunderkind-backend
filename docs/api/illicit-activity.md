# Illicit activity in the sync (PA cheats, 2026-11-27)

Cheats a save has done travel in `POST /api/sync` as `illicitActivity`, a list of records. The first kind is
`fake_sponsor`: a sham sponsorship deal the Personal Assistant pitched and the owner accepted.

**Record only.** The device does every financial calculation. The fake deal's weekly income is already inside the
ordinary `sponsor_payment` ledger line and the club balance the device sends. Nothing here changes a club balance,
a ledger row or an owner balance. Investigations and penalties are a later slice.

## Payload

The app sends the **full list on every sync**, not windowed by week. Leaving the field out means "no change"; `[]`
means none. Money is in **real pence** (not the ledger's ×100 quirk).

```json
"illicitActivity": [
  {
    "id": "0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
    "kind": "fake_sponsor",
    "startWeek": 41,
    "startSeason": 2,
    "suspicion": 64,
    "status": "active",
    "endedWeek": null,
    "details": {
      "name": "Northgate Holdings Ltd",
      "weeklyPayment": 1250000,
      "contractWeeks": 52,
      "endWeek": 93,
      "companySize": "MEDIUM",
      "trigger": "bleed"
    }
  }
]
```

| Field | Rule |
|---|---|
| `id` | The device's id, 1–64 of `[A-Za-z0-9_-]`. Stable for the record's life. |
| `kind` | `fake_sponsor`. Unknown kinds are dropped. |
| `startWeek`, `startSeason` | Positive integers. |
| `suspicion` | Integer 0–100, fixed when the cheat started. No effect yet. |
| `status` | `active` or `ended`. `endedWeek` is a positive integer when ended, null otherwise. |
| `details` (`fake_sponsor`) | `name` 1–80 chars; `weeklyPayment`, `contractWeeks`, `endWeek` positive integers; `companySize` small/medium/large in any case (stored lowercase); `trigger` `bleed` or `table`. Other keys are ignored. |

At most 200 records are read. **A bad record is dropped on its own and logged (`sync.illicit_activity.dropped`); it
never fails the sync.**

## Storage

`club_illicit_activity`, one row per (club, device id), upserted from the newest payload. Rows are **never
deleted**: a record the server has seen but a later list leaves out (a restored save, a rollback) keeps its row and
gets `missingSince`, cleared if it comes back. The raw list is also archived on the `SyncRecord` payload.

Admin: Operate › Accounts › Illicit activity (read only, filter by club, kind, status), and a count on each club's
page.

## Switch

Game Config `paCheatsEnabled` (off by default, served in `GET /api/game-config`) lets the app pitch sham deals and
the hidden `sponsor_pitch` advice topic reach the AI. See `personal-assistant.md`.
