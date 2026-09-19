# API Spec — Competition Registration Snapshot: Additive Fields for Match-Engine Parity

`POST /api/competitions/{id}/register` and `POST /api/competitions/{id}/resubmit` accept a
club/players/staff snapshot (`CompetitionRegisterRequest`) that `DeterministicEngine` uses to
simulate every fixture a club plays in that competition. That engine is now a direct
server-side port of the client's own single-player match engine
(`wunderkind-app/src/engine/ResultsEngine.ts`), which reads a handful of inputs the current
snapshot doesn't carry yet. Every one of those inputs is treated as **optional** by
`ResultsEngine.ts` itself — absent means "neutral," never an error — so this is a pure parity
gap, not a new requirement: the client already computes all of these values for its own local
matches, this just asks it to also submit them for a competition fixture.

**This document describes additive, optional fields only.** Nothing here is required for a
valid registration today, and omitting all of it produces exactly today's behavior (the
engine's existing neutral defaults). It only improves realism once the client starts sending
it — same pattern as the "2026 client expansion" fields already accepted by
`SnapshotValidator` (`club.reputation`/`tier`/kit colours, players[] `personality`/`morale`/
etc.) but deliberately left unvalidated: a wrong shape here is silently ignored, not a 422.

## `club` object — new optional keys

| Key | Type | Default when absent | Meaning |
|---|---|---|---|
| `managerMotivation` | number, 0-100 | `60` (neutral) | Feeds the manager-ability boost term in `calculateDominance()` — matches `ResultsEngine.NEUTRAL_MANAGER_MOTIVATION`. |
| `lowUrgency` | boolean | `false` | When `true`, applies a small (`0.95x`) sharpness penalty to the whole side — mirrors a squad playing a dead-rubber fixture without urgency. |
| `cohesion` | number, 0-100 | *(no modifier applied)* | The client already computes this via `computeCohesionFromSquad()` (`CohesionEngine.ts`). Submit the single resulting number, not the underlying relationship graph — the backend has no use for per-pair data. Banded effects (pass-accuracy/error-rate/card-risk) match the client's own `getCohesionMatchModifiers()` thresholds. |

## Per-player object (inside `players[]`) — new optional keys

| Key | Type | Default when absent | Meaning |
|---|---|---|---|
| `suspendedMatches` | integer ≥ 0 | `0` | A player with a value `> 0` is excluded from card generation for that fixture (already suspended, can't pick up a further card) — mirrors the client's own suspension gate. |
| `isActive` | boolean | `true` | `false` excludes the player from card generation the same way a suspension does — e.g. injured/unavailable for this match. |
| `outOfPosition` | boolean | `false` | Whether this XI slot is a natural-position mismatch for the player, per the client's `SelectionService.getOutOfPositionIds()`. Applies a dominance penalty (`0.8x` on that player's contribution) matching the client-side selection screen's own warning. |

## What's unaffected

- `SnapshotValidator` needs **no changes** for any of these — they pass through verbatim and
  default gracefully when absent, the same convention already established for the 2026 field
  expansion (see its class docblock). Don't add `tryFrom()`-style rejection for a malformed
  value here; treat a bad shape as "field absent."
- Registrations that omit all of the above continue to resolve exactly as they do today.
- Goals, assists, cards, and ratings are already computed from fields the snapshot already
  carries (`currentAbility`, `morale`, `condition`, `position`, `personality.*`, `playingStyle`,
  staff `MANAGER` role `ability`) — none of that is new; this doc only covers the remaining gap.

## Reference

Server-side consumer: `DeterministicEngine::calculateDominance()` /
`DeterministicEngine::distributeCards()` (`src/Service/MatchEngine/DeterministicEngine.php`).
Client-side source of truth for all of the above: `wunderkind-app/src/engine/ResultsEngine.ts`,
`wunderkind-app/src/engine/SelectionService.ts`, `wunderkind-app/src/engine/CohesionEngine.ts`.
