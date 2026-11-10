# `GET /api/competitions/champions`: `history[]` is the list of past winners

**Date:** 2026-11-10
**Status:** shipped. Additive — nothing was removed or renamed.

## What changed

The response gained two keys:

```
champions, history, nextCursor, serverTime
```

`history[]` is **one row per completed competition instance**, newest first. `champions[]` is
unchanged: **one row per active template**, carrying that cup's current holder.

Rows of the two arrays share one shape, so a client can render either with the same row
component:

```json
{
  "templateId": "01a1a081-5d6d-7242-9fcf-fc179eb35be4",
  "templateName": "Sprint Series",
  "templateIsActive": true,
  "entrantCapacity": 4,
  "trophy": { "style": "ball", "finish": "silver", "ribbons": false, "…": "…" },
  "instanceId": "01a1c125-2dea-7b66-a5d7-0d14859806eb",
  "completedAt": "2026-11-10T06:28:01+00:00",
  "champion": { "entrantId": "…", "clubId": "…", "clubName": "Prestwich Alexandra", "…": "…" },
  "runnerUp": { "…": "…" }
}
```

`templateIsActive` is the one key that is new on **both** arrays — it was added so the shared
row shape could stay shared (see "retired templates" below).

## Why

`champions[]` was being read as the historical-winners list, and it cannot be one. Its length
is the number of active templates, not the number of cups played, because it is built by
looping the active templates and asking each for its single latest completed instance. In
production that meant one active template, five finals settled, five different winning clubs —
and an app that showed exactly **one** winner, permanently, with no parameter able to widen it.

The landing page never had the problem because it asks the question the other way round: most
recent finals, whatever template they belong to (`CupResultsBoardService`). `history[]` is that
question, answered on the API.

Widening `champions[]` instead would have repeated `templateId` down a list existing clients
key on, and silently changed what the key means for every client already shipped. Hence a new
key.

## Pagination

| Param | Default | Notes |
|---|---|---|
| `limit` | `20` | Clamped to **50**. Applies to `history[]` only. |
| `cursor` | `0` | Raw offset, as a string — same idiom as `GET /api/competitions`. |

`nextCursor` is the next offset as a string, or `null` on the last page. The clamp is enforced
in `HallOfFameService`, not the controller, because this endpoint is `PUBLIC_ACCESS`.

`champions[]` is **not** paginated and ignores both params — it is bounded by the number of
active templates.

## Things a client must handle

- **`champion` and `runnerUp` can be `null`** on a row of *either* array: a genuinely
  completed instance whose final never recorded a winner. In `history[]` those rows are kept
  rather than filtered, because dropping them after `LIMIT` would return short pages while the
  cursor still advanced by the full page — a hole the client could not detect. In `champions[]`
  the row is kept because the instance genuinely is that template's latest completed one;
  there is simply no club to name. This is unchanged behaviour on that key, not new. A client
  wanting winners only should skip null-champion rows.
- **`completedAt` is never `null` in `history[]`**, and never null on a `champions[]` row
  either. An instance marked `COMPLETED` with no `completedAt` is excluded from both — it is
  one admin form save away (`status` and `completedAt` are independent fields on the CRUD
  page) and Postgres orders `completed_at DESC` **NULLS FIRST**, so such a row would otherwise
  head page 1 forever and date itself today.
- **Retired templates keep their champions.** `history[]` deliberately ignores
  `template.isActive`, so a cup that has been retired still shows every final it ever had,
  with `templateIsActive: false`. `champions[]` drops it — there is no *current* holder of a
  cup nobody can enter. Note this differs from `GET /api/competitions`, which hard-filters
  `t.isActive = true` and so hides a retired competition's history along with its live
  instances.
- **Ordering is `completedAt DESC`, then `createdAt DESC`, then `id ASC`.** The id tiebreak
  matters under offset pagination: two cups provisioned and completed in the same tick share
  both timestamps, and an unstable sort would not merely reorder them — it would repeat one row
  across a page boundary and drop the other.

## `GET /api/club/hub`

The hub payload gained `history` with the same row shape, fixed at **5** rows and with **no**
`nextCursor` — it is one aggregate response, and a client wanting to page the history calls
this endpoint for it. Its `champions` key is unchanged in meaning and now comes from the same
service, so the two payloads cannot drift.

## Server-side note for whoever changes this document next

Both keys are owned by `App\Service\Competition\HallOfFameService` and both end in its private
`row()`. Add a field there, not in a controller — the two controllers each had their own copy
of this loop before, and the copies had already diverged on how they read the trophy.

`championPair()` on `CompetitionResponseBuilder` is shared with the prebuilt
`GET /api/competitions/{id}` document, which is cached per competition and ETag-validated on
`computeStateFingerprint()`. A COMPLETED competition's state never changes again, so anything
that alters `champion`/`runnerUp` **output** there must bump `DOCUMENT_SCHEMA_VERSION` in the
same commit, or stored documents keep serving the old shape forever. Adding a key to the Hall
of Fame row does not touch that document and needs no bump.
