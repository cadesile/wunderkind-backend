# Live Telemetry API (client integration)

Backend-driven global activity aggregate: the 24h macro counters and the merged
"boardroom incident" feed that the landing page (<https://www.buildmyclub.co.uk/>)
already renders, exposed as JSON so the React Native app can show the same thing.

## Why this exists

The data has existed server-side for a while — `LiveTelemetrySnapshot`,
`LiveTelemetryService`, an `app:telemetry:generate` command and a 15-minute cron — but
**there was no endpoint of any kind.** `LandingController` passed the snapshot straight
into Twig and the numbers were baked into the HTML; the page makes no `fetch`/XHR call
and references no `/api/` path at all. So the app could not read any of it. This
endpoint is the only JSON access to that aggregate.

## `GET /api/telemetry`

Public, no auth required. Reads the cached singleton snapshot and **never aggregates per
request** — the aggregation walks every recent `SyncRecord` payload and is far too heavy
for a request path.

```
GET /api/telemetry
```
```json
200 OK
{
  "generatedAt": "2026-10-21T22:15:00+00:00",
  "windows": { "statsHours": 24, "feedDays": 7 },
  "stats": {
    "fixturesSimulated": 42,
    "capitalDeployedPence": 24630000,
    "results": { "wins": 7, "draws": 3, "losses": 2 },
    "activeClubs": 11,
    "weeksPlayed": 48,
    "weeksPlayedApproximate": true,
    "totalGoals": 31,
    "totalMatches": 12,
    "tableTurnover": 6,
    "tableTurnoverWindowDays": 7,
    "communityMoraleDelta": null
  },
  "feed": [
    {
      "category": "PYRAMID",
      "categoryLabel": "[PYRAMID // MOVEMENT]",
      "text": "Dulwich Hamlet promoted to Tier 6",
      "detail": "Finished 1st on 92 points.",
      "amountExact": "£246,300",
      "club": "Dulwich Hamlet",
      "clubId": "club-abc-123",
      "clubBadge": { "kit": "stripes", "primary": "#ff0000" },
      "meta": { "tierFrom": 7, "tierTo": 6 },
      "timestampIso": "2026-10-21T19:15:00+00:00"
    }
  ]
}
```

### Polling and caching

The snapshot is regenerated **every 15 minutes**. Polling faster than that re-reads an
identical row, so the response carries `Last-Modified` and `ETag` (both derived from
`generatedAt`) and `Cache-Control: public, max-age=450`. Send `If-None-Match` and you
get a **304** with no body. Fetch on screen focus, cache the last good payload locally,
and render stale-while-revalidate — don't set up a short interval timer.

### `stats` — caveats that matter

| Field | Note |
|---|---|
| `capitalDeployedPence` | Real pence, already corrected for the sync ledger's 100x-inflated `amount` convention at generation time. **Do not** scale it again. Emitted as a JSON number (the column is a `bigint`, which would otherwise hydrate as a quoted string). |
| `weeksPlayed` | **An approximation**: sync count x 4, not a true weeks-elapsed count. `weeksPlayedApproximate: true` is sent in-band so this can't be mistaken for exact. Label it loosely ("~48 weeks") or don't show it. |
| `tableTurnover` | Counted over the **7-day** window, not the 24h one the rest of the block uses — a promotion/relegation only lands when a season concludes, which is far rarer than a sync, so a 24h count would read 0 almost permanently. `tableTurnoverWindowDays` states this. Don't put it under a "24H" heading without a qualifier. |
| `communityMoraleDelta` | **Nullable, and null is not 0.** `null` = not enough paired morale readings to compute a delta; `0` = computed, no net change. |
| `results` | Knowingly double-counts: it is tallied from each payload's `form[]`, which carries no fixture identity to deduplicate on. `totalGoals`/`totalMatches` *are* deduplicated (by `fixtureId`). |
| All counters | Windowed by sync **arrival** (server clock), not when matches were *played* — ~3.5% of fixtures were played more than 24h before the sync reporting them. Deliberate: arrival time cannot be gamed, device clocks can. |

**A zeroed response is valid, not an error.** Every counter 0 and `feed: []` is the real
steady state on a quiet day, and also what a brand-new environment returns before the
cron first runs. Render an empty state; don't treat it as a failure.

### `feed` — up to 20 items, newest first

Every line is backed by real data. The feed was once padded with six static
sacking/boardroom-clash/contract-dispute/youth-intake lines that had no backing data;
those were **removed by product decision**, and "real events only" is now a binding
constraint on every category. Nothing here is placeholder content.

`category` is the machine value — **switch on this**, and keep a default branch, because
a new category can ship server-side without an app release. Current values:

`PYRAMID`, `OUTLAY`, `ATTENDANCE`, `DRESSING_ROOM`, `DILUTION`, `COVENANT`, `TALISMAN`,
`COMMUNITY`, `TOP_TRANSFER`, `TOP_SCORER`

| Field | Note |
|---|---|
| `categoryLabel` | Terminal-styled chrome for the web HUD, e.g. `[PYRAMID // MOVEMENT]`. Render your own label from `category` instead of showing these brackets. |
| `text` / `detail` | **Client-generated narrative strings** that may name real clubs and real players. Already public on the landing page, but showing them in-app is a product call. `detail` falls back to `text` when there is no longer form. |
| `amountExact` | A **pre-formatted GBP display string** (`"£246,300"`), with the `£` and grouping hardcoded server-side — it cannot be localised or re-formatted, and the raw pence is **not** on the event. `null` where no figure could be established (the generator shows nothing rather than guessing). See "Known gap" below. |
| `club` | Display name only. **Not an identifier** — club names are not unique (production carries six "London FC"). |
| `clubId` | The real `Club` row, when there is one. **Key on this, never `club`.** `null` for items whose club is a name only — a transfer's destination may be an NPC side with no `Club` row. |
| `clubBadge` | The club's kit/badge config, the same `KitConfig` shape the app's existing `KitSprite` renders. Nullable. |
| `meta` | Free-form per-category extras; shape varies by `category`. Treat as opaque unless you've checked the generator for that category. |
| `timestampIso` | The event instant. **Compute relative time from this**, client-side. |

### Three keys are deliberately not emitted

The stored `recent_events` JSON contains them; the endpoint strips them, and a test
asserts they never reach a client:

- **`at`** — a real `DateTimeImmutable` persisted into the JSON column, which serialises
  to `{"date":…,"timezone_type":3,"timezone":…}`, a PHP artifact with no business
  meaning. `timestampIso` is the same instant, portable.
- **`time`** — a relative string ("3 hours ago") rendered at *generation* time, so it is
  already up to 15 minutes stale when served, and would freeze permanently in a client
  cache.
- **`dossier`** — the per-club match/player bundle the web boardroom overlay opens.
  Excluded by product decision: it is the bulk of an event's bytes (up to 5 fixtures plus
  a player likeness, per event, across 20 events) and the app has no screen for it. If a
  screen ever needs it, add it behind an explicit `?include=dossier` rather than widening
  the default payload.

## Known gap

`amountExact` is a formatted string, so an app cannot localise currency or re-format the
figure. Fixing it properly means having `LiveTelemetryService::event()` also store the
raw pence on each feed item and emitting it as `amountPence` — a generator change plus a
snapshot rebuild, deliberately **not** done here to keep this change to exposing existing
data. Raise it if the app needs non-GBP or compact formatting.
