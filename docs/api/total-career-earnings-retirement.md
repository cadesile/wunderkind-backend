# Retiring `totalCareerEarnings`

Owner decision, 2026-11-25: keep the field wherever removing it would break an installed
app build, and retire it on a plan instead of in one step.

## Where it stands

`Club::$totalCareerEarnings` is a client-supplied accumulator of the club's lifetime income.
Nothing on the server reads it any more. The last reader, the server-side financial
year-end investor payout and dividend, was removed in the same change as this document.
The `career_earnings` leaderboard and the spotlight read `UserLedger` dividend draws instead.

It is still:

- accepted on `POST /api/sync` (`SyncRequest::$totalCareerEarnings`, defaults to 0) and
  written to the column on every sync;
- emitted by `GET /api/club/status`, `GET /api/club/all` and the sync response's `club` block.

## Why it can't simply be deleted

Shipped app builds read it from those three responses. They merge it into local state with
`Math.max(local, server)`, so a **missing** field risks `NaN` in the local value, but a
**zero** is harmless. There is no minimum-app-version gate on the API today, so the server
can't tell an old build from a new one.

## Plan

1. **Now (this change).** The server stops computing anything from the field. Responses
   are unchanged.
2. **App release N (the owner/game database split).** The app stops reading the field and
   stops sending it. The server then stores 0 for clubs on that build, which no reader cares
   about. Old builds keep working: they still receive the field, and `max(local, 0)` keeps
   their local value.
3. **Gate.** Add an app-version signal (for example an `X-App-Version` header recorded on
   `SyncRecord`), or use store-console adoption figures, so it is visible when release N
   or later is effectively the only build in use.
4. **Removal, once the gate shows it is safe.** Drop the field from the three responses and
   from `SyncRequest`, remove the entity property, and add a Doctrine migration that drops
   `club.total_career_earnings`, with a `down()` that re-adds it as `BIGINT UNSIGNED DEFAULT 0`.
   Update `docs/frontend-integration.md`, `docs/api/club-list.md` and the
   `ClubControllerAllClubsTest` assertion in the same change.

Until step 4, do not add new readers.
