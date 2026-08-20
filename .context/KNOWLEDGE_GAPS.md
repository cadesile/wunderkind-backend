# Knowledge Gaps

## Migration rollback footprint
**Question:** Multiple recent migrations (`Version20260626000001`, `Version20260704000001`, `Version20260716000000`, `Version20260719000000`) show a column being both added and dropped within the same migration file — is this an artifact of the extraction/regex scan, or do these migrations genuinely add-then-drop the same column in production?
**Why it matters:** If real, `User.lastLoginAt`, `Club.tutorialCompletedAt`, `starter_config.world_pack_players_per_agent`, and `game_config.season_ticket_holder_percent` would not persist as expected, silently breaking any code (e.g. `Club` entity's `tutorialCompletedAt` field, `03_data/entities.md`) that relies on those columns existing.

## schema.md extraction reliability
**Question:** `03_data/schema.md` is flagged `ai-call-failed (existing content retained)` in the provenance table — how stale is the retained content, and which tables/columns might be missing or outdated relative to the actual current schema?
**Why it matters:** Any decisions about `GameConfig`, `LeagueSponsor`, `TacticalAdvantage`, or `User` fields based on this doc risk being wrong since it wasn't freshly regenerated.

## api-spec.md unavailable
**Question:** `04_interfaces/api-spec.md` extraction is marked `unavailable` — is there no OpenAPI/API-spec source in the repo, or did the extraction step fail to locate one?
**Why it matters:** Without this, there's no verified contract for API consumers (e.g. frontend or `/api/beta-request/verify` per `entities.md`'s `BetaRequest`), and routes.md/controllers.md content can't be cross-checked against a formal spec.

## state.md has no source material
**Question:** `03_data/state.md` is `ai-no-relevant-files-found` — is game/session state tracked entirely through entities like `GameConfig`, `Club`, `Agent` (with the new `appearance` JSON column), or is there a missing state-machine/workflow layer not captured by this generator?
**Why it matters:** Entities like `Club` carry state-like fields (`tutorialCompletedAt`, `worldInitializedAt`, `starterInitializedAt`, `lastSyncedAt`) suggesting an implicit state machine that isn't documented anywhere.

## Social media integration intent
**Question:** `Version20260705141806` and `Version20260705202249` add `social_account_connection` and `social_post_template` tables (with OAuth-style `access_token`/`refresh_token` storage), and `Version20260705211313` adds `facebook_page_url`/`x_profile_url` to `game_config` — what's the business purpose (automated social posting per club? per league?) and is `access_token`/`refresh_token` encrypted at rest?
**Why it matters:** Storing raw OAuth tokens in a `TEXT` column (`social_account_connection.access_token`) is a security-sensitive design choice that isn't explained or verified anywhere in the extracted content.

## `npc_squad_config` JSON semantics
**Question:** `Version20260707203954` adds `game_config.npc_squad_config` as JSON `NOT NULL` with no default shown — what shape/schema does this JSON take, and what migration path populated existing rows before the `NOT NULL` constraint was enforced?
**Why it matters:** Since `GameConfig` is described as a singleton row (`entities.md`), a `NOT NULL` JSON column added after the row already exists implies a backfill step that isn't documented, risking a broken deploy if reproduced elsewhere.

## `hallOfFamePoints` / `reputation` invariants enforcement point
**Question:** `entities.md`'s field note states `hallOfFamePoints` is `max(current, incoming)` and `reputation` floors at 0 — are these invariants enforced only in application-layer setters, or also at the database level (check constraints)?
**Why it matters:** `Club.hallOfFamePoints`/`reputation` are plain `int` columns in `schema.md` with no DB constraints shown, so any code path that sets these fields directly (bypassing the described setters) could violate the invariant undetected.

## EasyAdmin JSON field workaround scope
**Question:** The `Agent` entity's field note describes a workaround for EasyAdmin auto-configuring `json` columns as `CollectionType` — does this same issue apply to the newly added `appearance` JSON columns on `Player` and `Scout` (`Version20260713194158`), or were those admin forms built differently?
**Why it matters:** If `Player`/`Scout` admin CRUD forms weren't given the same `configureOptions()` tolerance and form-theme treatment as `Agent`, editing those entities in EasyAdmin could throw the same `"options ... do not exist"` error.
