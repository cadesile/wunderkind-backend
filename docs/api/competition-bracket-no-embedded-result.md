# `GET /api/competitions/{id}` no longer embeds per-fixture match detail

**Date:** 2026-10-28
**Status:** backend change shipped; **the app must be updated** (see "What the client must do").

## What changed

Each fixture in `rounds[].fixtures[]` used to carry a `result` object holding the full match
detail: both starting XIs with per-player goals/assists/cards/ratings, the whole commentary
timeline, and a frozen copy of each club's display data.

That `result` key is **gone**. Everything else about a fixture is unchanged:

```
fixtureId, slotIndex, status, homeEntrantId, awayEntrantId, winnerEntrantId,
score { home, away, extraTime, penalties }, matchId, home { … }, away { … }
```

A bracket is still fully renderable from that alone — scorelines, winners, and both clubs'
names, kits and badges — with no additional request.

## Why

Measured with `app:competition:benchmark`:

| | capacity 32 | capacity 64 |
|---|---|---|
| response, before | 397 KB | ~805 KB |
| response, after | **59 KB** | — |

At capacity 32 the embedded `result` blocks were **~74% of the response**: lineups 41.5%,
narrative 32.6%. Every one of those bytes was already served by `GET /api/matches/{id}`, and
the bracket already handed out a `matchId` for every completed fixture — so the endpoint was
shipping up to 63 copies of a payload a client needs one of at a time.

(Worth noting the measurement corrected an assumption: the narrative was *not* the dominant
component. The lineups were.)

## What the client must do

Where it previously read `fixtures[].result`, fetch the match instead:

```
GET /api/matches/{fixtureId}
```

`matchId` on a completed fixture is that id. The match payload is a superset of what `result`
carried, with different key names — it is the first-class match shape documented in
`docs/api/match-detail-club-fields.md`:

| old `result.*` | new `GET /api/matches/{id}` |
|---|---|
| `homeScore` / `awayScore` | `score.home` / `score.away` |
| `homeClub` / `awayClub` | `home` / `away` |
| `homeLineup` / `awayLineup` | `homeLineup` / `awayLineup` (same shape) |
| `narrativePayload` | `narrativePayload` (same shape) |
| `tournament` | `competition` |
| `round` | `competition.roundIndex` / `roundKey` / `roundLabel` |

**Push notifications already carry what you need.** A `MATCH_RESULT` push includes
`fixtureId` and `link: { kind: 'match', id: <fixtureId> }`. Previously the documented
instruction was to re-fetch the bracket and read that fixture's `result`; fetch the match
endpoint with that id instead.

## Server-side notes

- `CompetitionResult::toClientSummary()` is **deleted** — it had no callers once the bracket
  stopped embedding it, and `MatchQueryService::serializeCupMatchDetail()` was already the
  real client shape. Its private helpers (`stripClubId()`, `stripPlayerId()`) went with it.
- The admin competition detail page's "What the device receives" panel now **links**
  `GET /api/matches/{id}` rather than inlining a payload, so the panel cannot claim to show
  something the device does not receive — and the screen does not serialise every fixture in
  a bracket to render it.
- `app:competition:benchmark`'s payload breakdown reports 0 bytes for narrative and lineups.
  That is a regression signal: if either grows, something has re-embedded match detail.
