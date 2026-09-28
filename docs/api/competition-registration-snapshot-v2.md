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

## `MATCH_NARRATIVE` templates: retiring bundled `narrativeContent.json`

Unrelated to the snapshot fields above (this section covers the client's local match-viewer
commentary, not competition registration), but delivered by the same backend work and
belongs in the same "what should the frontend team change" doc.

`wunderkind-app/src/components/matchux/narrativeContent.json` — the chain-graph of commentary
line templates that `matchTimelineGenerator.ts`/`narrativeEngine.ts` walk to build a match's
live ticker — now has a server-owned, authoritative copy: every line in that file has been
seeded verbatim as a `GameEventTemplate` row under the new `EventCategory::MATCH_NARRATIVE`
(`src/Command/SeedMatchNarrativeTemplatesCommand.php`). The client can fetch this content
instead of bundling its own copy of the JSON, the same way `EventCategory::MATCH` content is
already fetched rather than bundled.

### Fetching it

```
GET /api/events/templates?category=MATCH_NARRATIVE
```

Auth: `ROLE_CLUB` JWT (same as the unfiltered `GET /api/events/templates` the client may
already call for other categories). Response is cacheable — `max-age=3600`, public — same as
today.

```json
{
  "templates": [
    {
      "slug": "GOAL_ATTEMPT_3",
      "category": "MATCH_NARRATIVE",
      "weight": 1,
      "title": "Goal Attempt",
      "bodyTemplate": "{attacker} cuts inside and curls one toward the far post!",
      "impacts": [],
      "firingConditions": null,
      "severity": null,
      "chainedEvents": [
        { "nextEventSlug": "GOAL_ATTEMPT_SCORE", "boostMultiplier": 1.0, "windowWeeks": 0 },
        { "nextEventSlug": "GOAL_ATTEMPT_SAVED", "boostMultiplier": 1.0, "windowWeeks": 0 },
        { "nextEventSlug": "GOAL_ATTEMPT_MISS", "boostMultiplier": 1.0, "windowWeeks": 0 },
        { "nextEventSlug": "GOAL_ATTEMPT_BLOCKED", "boostMultiplier": 1.0, "windowWeeks": 0 }
      ],
      "noInteract": false
    }
  ]
}
```

### Mapping the old shape onto the new one

`narrativeContent.json` was one object per **node type** (`GOAL_ATTEMPT`, `BUILDUP`, ...),
each holding a `templates: string[]` array of candidate lines plus one shared `next_events:
string[]`. The API instead returns one **row per candidate line** — every line that used to
live in a node's `templates` array is now its own template row, slugged
`{NODE_TYPE}_{N}` (1-indexed, e.g. `GOAL_ATTEMPT_1`..`GOAL_ATTEMPT_9`). To rebuild the old
node-keyed shape client-side, group rows by the node-type prefix of their slug
(`/^(.+)_(\d+)$/`, same regex the backend's own generator uses) and collect `bodyTemplate`
back into a `templates` array per group.

### `chainedEvents` replaces `next_events`

`next_events: string[]` becomes `chainedEvents: {nextEventSlug, boostMultiplier, windowWeeks}[]`
— read only the `nextEventSlug` values for this category; the array is otherwise the same
list of branch targets `next_events` held. Two things differ from both `next_events` and from
how `chainedEvents` is used on every other `GameEventTemplate` category:

1. **Every numbered row of a node type carries an identical `chainedEvents` array.** The graph
   edges belong to the node type, not to any one candidate line — `GOAL_ATTEMPT_1` through
   `GOAL_ATTEMPT_9` all list the same four `nextEventSlug` values. This mirrors exactly how
   `narrativeContent.json` had one `next_events` per node object, not per template string.
2. **`nextEventSlug` here names a node type, not a specific row's slug.** On every other
   category, `chainedEvents[].nextEventSlug` is a concrete template's own slug. For
   `MATCH_NARRATIVE` it's the node-type prefix (`"GOAL_ATTEMPT_SCORE"`, not
   `"GOAL_ATTEMPT_SCORE_2"`) — resolve it to one of that node type's numbered rows (a random
   pick, weighted by `weight`, is what the backend's own generator does) at chain-walk time,
   the same way `next_events` was always a node-type reference, never a specific template
   string index.
3. **`boostMultiplier`/`windowWeeks` are inert for this category** — they exist only because
   `chainedEvents` is a shared column shape across all categories; a client walking the
   MATCH_NARRATIVE graph should ignore them and treat every edge as an unweighted possible
   branch, exactly like `next_events` did. (The admin-only `note` field is stripped from the
   API response entirely, as it is for every category.)

A node type with an empty `chainedEvents` array (e.g. `BUILDUP`, `GOAL_ATTEMPT_MISS`) is a
terminal node — same meaning as an empty `next_events: []` today.

### Client-side storage

Fetch once and persist on-device the same way the client already stores the other categories
pulled from this endpoint — this is a swap of the *source* for that stored content (bundled
JSON asset → API response), not a change to the storage pattern itself. Group rows into the
node-keyed shape (per "Mapping the old shape onto the new one" above) once at storage time,
not on every match, so `matchTimelineGenerator.ts`/`narrativeEngine.ts` can keep consuming the
same node-keyed structure they do today. Refresh on the same cadence/trigger already used for
the client's other locally-stored event-template categories (e.g. app launch / cache-expiry),
and fall back to the last-persisted copy when offline — there's no reason this category needs
a live network call per match.

### Display scoping — matchUX only

`MATCH` and `MATCH_NARRATIVE` are the two categories whose content is meaningless outside a
live match view — a highlight/lowlight ticker line and a commentary chain-graph node
respectively, both written assuming a match is currently on screen and using match-only
placeholders (`{attacker}`, `{goalkeeper}`, `{taker}`, etc. — see the bodyTemplate table above)
that nothing outside matchUX knows how to fill. Whatever consumes `GET /api/events/templates`
(unfiltered or filtered) client-side must route rows from these two categories to the matchUX
component only, and explicitly exclude them from the Inbox and from `EventQueueOverlay` — both
of which are generic queued-event surfaces built for the other categories (`player`,
`NPC_INTERACTION`, `player_morale`, etc.) and would render a raw, unfilled placeholder line if
a `MATCH`/`MATCH_NARRATIVE` row reached them. If those two surfaces currently filter by
category allowlist, add both to their exclusion list explicitly rather than relying on them
already being absent from whatever event pool feeds those components today.

### Display-only does not mean impacts-only-in-the-inbox

Being routed exclusively to matchUX (previous section) is about *where the line is shown*,
not about whether its `impacts` fire. When a `MATCH` or `MATCH_NARRATIVE` template is
triggered — i.e. the line is actually picked and displayed, not merely present in the fetched
set — its `impacts` must still be evaluated by the same impact-processing engine that already
applies them for every other category, exactly as `MATCH` already does today (e.g. a
`match_goal_1`-style line nudging the scorer's morale up). Don't special-case these two
categories into "text only, no stat effects" just because they render inside matchUX instead
of the inbox — the two are independent: *where it displays* vs *whether it mutates state*.

Concretely for `MATCH_NARRATIVE`: every row is currently seeded with `impacts: []` (the source
`narrativeContent.json` never carried stat effects, so there was nothing to port), but the
field is real and present on every row precisely so that impacts can be attached later — e.g.
an admin adding `[{"target": "player_1", "field": "morale", "operator": "add", "value": 5}]`
to a `GOAL_ATTEMPT_SCORE` line, where `player_1` resolves to the scorer of the goal that line's
chain is narrating. Use the canonical `stat_changes` shape (see the `impacts` reference table)
— `MATCH`/`MATCH_NARRATIVE` are not in the four categories restricted to the legacy flat-array
reader, so don't assume the flat-array shape here just because existing `MATCH` seed content
happens to use it; treat the two shapes exactly as documented for any other category and let
the shape actually present in the row's `impacts` decide.

Note this only applies to the client's own locally-walked commentary (single-player/local
matches, where the client itself decides which template row fires). A Competition fixture's
server-generated `narrativePayload` is pre-picked commentary text only — the backend has no
club/player state to mutate for a competition entrant snapshot, so it does not evaluate
`impacts` when building that payload, and the client shouldn't expect it to. This section is
about the client's own graph-walk, not about consuming `narrativePayload`.

### What doesn't change

- This is purely a content-source swap for the client's own local/single-player match-viewer
  ticker. It has no bearing on Competition results: those now arrive with a **fully
  pre-generated** `narrativePayload` timeline already walked server-side (see
  `CompetitionResult.toClientSummary()`), so a competition fixture's commentary needs no
  client-side graph-walking at all — this section only matters for whatever local/offline
  matches still generate their own commentary on-device.
- `EventCategory::MATCH` (`match_goal_1` etc.) is a separate, already-integrated category and
  is untouched by this — don't conflate the two when wiring up the fetch.

## Reference

Server-side consumer: `DeterministicEngine::calculateDominance()` /
`DeterministicEngine::distributeCards()` (`src/Service/MatchEngine/DeterministicEngine.php`).
Client-side source of truth for all of the above: `wunderkind-app/src/engine/ResultsEngine.ts`,
`wunderkind-app/src/engine/SelectionService.ts`, `wunderkind-app/src/engine/CohesionEngine.ts`.
Server-side source of truth for the MATCH_NARRATIVE content and graph-walk itself:
`src/Service/MatchEngine/MatchNarrativeGeneratorService.php`,
`src/Command/SeedMatchNarrativeTemplatesCommand.php`.
