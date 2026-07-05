# Community Stats API

Four read-only, live-computed leaderboard endpoints ranking clubs by activity. No authentication required (public endpoints). No caching — every request is computed fresh against the current database state.

## Common parameters

All four endpoints accept the same two query parameters:

| Param | Type | Default | Notes |
|---|---|---|---|
| `period` | string | `all` | One of `week`, `month`, `season`, `all` |
| `limit` | int | `10` | Capped at `50` regardless of requested value |

### Period semantics

- `week` — last 7 days
- `month` — last 30 days
- `all` — no lower bound, all-time
- `season` — **per-club**, not global. Each club's own most recent `SeasonRecord.createdAt` (i.e. when that club last concluded a season) is used as its individual cutoff. A club that has never concluded a season behaves the same as `all` for that club specifically. There is no single "current season start date" shared across all clubs — clubs conclude seasons independently, whenever their player finishes a season client-side. Don't build UI that assumes `season` maps to a fixed calendar window; it varies per club in the results.

## Common response shape

```json
{
  "period": "week",
  "results": [
    { "clubId": "019f3239-ad18-7ba6-87f7-5fb50e9f8a1f", "clubName": "Example FC", "value": 4, "rank": 1 }
  ]
}
```

- `results` is already sorted descending by `value`; `rank` is 1-indexed and matches array position.
- Clubs with zero matching activity for the period are **omitted entirely** — they never appear with `value: 0`.
- If no club has any matching activity, `results` is an empty array: `{"period": "week", "results": []}`. This is not an error condition — render an empty/zero state, not an error state.

## Errors

- `400 Bad Request` if `period` is not one of `week`/`month`/`season`/`all`:
  ```json
  { "error": "Invalid period. Valid values: week, month, season, all" }
  ```
- `limit` is silently clamped to `[1, 50]` rather than erroring — passing `limit=0`, a negative number, or `limit=999` will not fail, it will just be treated as `1` or `50` respectively.

---

## `GET /api/stats/most-transfers`

Ranks clubs by number of transfers completed, filtered by `Transfer.occurredAt` within the period window.

**Example request**
```
GET /api/stats/most-transfers?period=week&limit=10
```

**Example response (200)**
```json
{
  "period": "week",
  "results": [
    { "clubId": "019f3239-ad18-7ba6-87f7-5fb50e9f8a1f", "clubName": "Example FC", "value": 4, "rank": 1 },
    { "clubId": "019f3239-ad1a-7dea-a050-0676d0d7b9cb", "clubName": "Another FC", "value": 1, "rank": 2 }
  ]
}
```

`value` is a transfer count (integer).

---

## `GET /api/stats/most-development`

Ranks clubs by total player development points gained via transfers (sum of `Transfer.developmentPoints`), filtered by `Transfer.occurredAt` within the period window.

**Example request**
```
GET /api/stats/most-development?period=month&limit=10
```

**Example response (200)**
```json
{
  "period": "month",
  "results": [
    { "clubId": "019f3239-ad18-7ba6-87f7-5fb50e9f8a1f", "clubName": "Example FC", "value": 70, "rank": 1 }
  ]
}
```

`value` is a sum of development points (integer). A club that had transfers but zero net development points would show `value: 0` — but a club with *no* transfers in the period is omitted, not shown as `0`.

---

## `GET /api/stats/most-seasons`

Ranks clubs by number of completed seasons (`SeasonRecord` rows), filtered by `SeasonRecord.createdAt` within the period window.

**Example request**
```
GET /api/stats/most-seasons?period=all&limit=10
```

**Example response (200)**
```json
{
  "period": "all",
  "results": [
    { "clubId": "019f3239-ad1a-7e1a-a050-0676d13f2750", "clubName": "Example FC", "value": 2, "rank": 1 }
  ]
}
```

`value` is a season count (integer). Note: with `period=season`, this endpoint's own filter compares each `SeasonRecord.createdAt` against that same club's most recent `SeasonRecord.createdAt` — in practice this means `period=season` on this specific endpoint will only ever show a club's single most recent season record (see the Period semantics section above).

---

## `GET /api/stats/most-trophies`

Ranks clubs by number of season titles won (`SeasonRecord` rows where `finalPosition = 1`), filtered by `SeasonRecord.createdAt` within the period window.

**Example request**
```
GET /api/stats/most-trophies?period=season&limit=10
```

**Example response (200)**
```json
{
  "period": "season",
  "results": [
    { "clubId": "019f3239-ad18-7ba6-87f7-5fb50e9f8a1f", "clubName": "Example FC", "value": 1, "rank": 1 }
  ]
}
```

`value` is a trophy count (integer). A club with season records but none at `finalPosition = 1` is omitted entirely, not shown with `value: 0`.
