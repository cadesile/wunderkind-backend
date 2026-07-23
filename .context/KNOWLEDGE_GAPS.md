# Knowledge Gaps

## Routes and API spec extraction unverified
**Question:** `04_interfaces/routes.md` and `controllers.md` were built via `static-regex-fallback` (per the provenance table, live `debug:router`/`route:list` was not run) and `api-spec.md` is marked `unavailable` — do the documented routes/controllers accurately reflect what's actually registered, including any attribute-based or dynamically-loaded routes the regex scan could miss?
**Why it matters:** Any consumer relying on this context for endpoint behavior (auth, request/response shape) is working from an unverified static guess rather than the actual routing table.

## `TacticalAdvantage.multiplier` business rule
**Question:** What determines the `multiplier` value for a given `style`/`opponentStyle` pairing (e.g. `POSSESSION` vs `DIRECT` defaults to `1.0`), and is there a canonical matrix of all style combinations that should exist?
**Why it matters:** `TacticalAdvantage` has no hand-written notes and the entity only shows constructor defaults — an engineer changing match-simulation logic can't tell if `1.0` is a "no advantage" placeholder or a real balancing value without external confirmation.

## `LeagueSponsor.rolledValue` semantics
**Question:** What does "rolled" mean for `rolledValue` (bigint, default 0) — is it re-randomized periodically, accumulated, or set once per league/sponsor pairing — and what process writes to it?
**Why it matters:** No purpose note exists for `LeagueSponsor`; without knowing the update trigger, a bug fix or migration touching sponsor revenue could silently break whatever "rolling" logic currently governs it.

## `social_account_connection.access_token` / `refresh_token` storage
**Question:** Are `access_token` and `refresh_token` (both `TEXT NOT NULL`/`TEXT DEFAULT NULL` in `Version20260705141806`) encrypted at rest, or stored as plaintext OAuth tokens?
**Why it matters:** If unencrypted, this is a security-sensitive gap — plaintext long-lived OAuth tokens for connected social platforms in the DB is a real exposure risk that isn't visible from the schema alone.

## `game_config.npc_squad_config` JSON shape
**Question:** `Version20260707203954` adds `npc_squad_config JSON` then immediately sets it `NOT NULL` with no default shown — what schema/shape does this JSON blob follow, and what was the backfill value for existing rows when the column went from nullable to required?
**Why it matters:** A required JSON column with an unclear shape and an in-migration nullability flip is a common source of runtime `null`/malformed-JSON errors if the app-layer contract isn't documented.

## `Club.managerProfile` vs `Club.managerTemperament/Discipline/Ambition`
**Question:** `Club` has both a `?array $managerProfile` JSON blob and discrete `managerTemperament`/`managerDiscipline`/`managerAmbition` int fields — is `managerProfile` a legacy/duplicate representation of the same traits, or does it hold different data?
**Why it matters:** Two competing representations of "manager" state on the same entity risks code reading/writing the stale one; the field note only documents the clamped int setters, not `managerProfile`'s role.

## `season_ticket_holder_percent` default rationale
**Question:** `Version20260719000000` adds `game_config.season_ticket_holder_percent SMALLINT DEFAULT 60 NOT NULL` — is 60% a tuned game-balance constant, and does changing it require corresponding changes to revenue/attendance calculations elsewhere?
**Why it matters:** It's a very recent (2026-07-19) migration with no accompanying entity note; anyone adjusting game economy balance needs to know whether this value is safe to tweak in isolation.
