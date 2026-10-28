# Migration History

Entities under `src/Entity/` are the authoritative current schema —
migrations are the change log, not the source of truth (see
`schema.md`/`entities.md`).


## Schema drift: cleared, and how to keep it that way

For a long stretch, `doctrine:migrations:diff` on this project emitted ~90
statements that had nothing to do with the change being made — and, worse,
proposed `DROP`ping four raw-SQL partial unique indexes it could not see. Every
migration had to be hand-trimmed, which is how
`Version20261011114336`/`Version20261011131620` came to carry warnings about it.

That drift is gone. It was three separate things:

- **73 column defaults** present in the database but never declared in the
  mapping (`pool_config`, `game_config`, `excursion`, `starter_config`). Fixed in
  the entities with `options: ['default' => ...]` — no DDL.
- **4 partial unique indexes + 1 plain index + 2 custom index names** that the
  mapping never mentioned. Now declared (see `schema.md`) — no DDL.
- **9 genuine database-side differences** — six json columns carrying a DEFAULT
  the mapping didn't want, two `pool_config.scout_experience_*` defaults that
  actually disagreed with the entity (DB 0/10 vs entity 10/80), and a leftover
  `(DC2Type:uuid)` column comment. Fixed by `Version20261011134810`.

`doctrine:schema:update --dump-sql` now reports "Nothing to update" and
`migrations:diff` reports "No changes detected". **If a diff ever comes back
noisy again, that is a signal, not background noise** — something was added to
the database without being described in the mapping.

## Archive

`migrations/archive/` holds 29 old MySQL-era migrations plus a
`README.md` stating plainly: *"Archived MySQL migrations... Archived
during migration to PostgreSQL (2026-03-26). Do not run these... A
single fresh baseline migration replaces them for Postgres."* Dead
history — not part of the live migration chain.

## Live migration chain: `migrations/` (144 files)

Most recent migrations (chronological):

1. `Version20260913203511`, `Version20260914090400`,
   `Version20260914094959`, `Version20260914153431` — older, not
   inspected in detail this pass.
2. `Version20260915213526` — creates `live_telemetry_snapshot`
   (`id`, `fixtures_simulated`, `capital_deployed_pence`,
   `generated_at`).
3. `Version20260915215939` — adds `recent_events JSON` to
   `live_telemetry_snapshot`.
4. `Version20260915221026` — adds `goals_scored INT` to
   `live_telemetry_snapshot`.
5. `Version20260915230707` — renames `goals_scored` →
   `results_wins`, adds `results_draws`, `results_losses`.
6. `Version20260915233540` — adds `active_clubs`, `weeks_played` to
   `live_telemetry_snapshot`.
6a. `Version20260928190000` — adds `dressing_room_fallout_count`,
    `community_morale_delta`, `dilution_equity_percent`,
    `dilution_club_name`, `dilution_counterparty`, `covenant_active_count`,
    `covenant_seasonal_value_pence` to `live_telemetry_snapshot`, for the
    redesigned categorized "Boardroom Incident & Consequence Feed".
7. `Version20260916112510` — large migration creating the entire
   Competition module schema (`active_competition`,
   `competition_entrant`, `competition_fixture`, `competition_result`,
   and related tables/indexes per `entities.md`'s Competition section),
   including the partial unique index enforcing one open
   `active_competition` per template.
8. `Version20260918205514` — adds
   `is_spoof BOOLEAN DEFAULT false NOT NULL` to `club`.
9. `Version20260919163350` — adds `home_club_json`, `away_club_json`,
   `home_lineup_json`, `away_lineup_json` to `competition_result` (full
   stored/served match payload — club kits/badges + starting XI with
   goals/assists/cards/ratings).
10. `Version20260919181112` — adds `losing_team_card_multiplier_max`,
    `losing_team_card_goal_diff_cap` to `game_config` (card-generation
    knobs for the `ResultsEngine.ts`-ported `DeterministicEngine`).
11. `Version20260919191910` — adds `went_to_extra_time`,
    `went_to_penalties`, `penalty_home_score`, `penalty_away_score` to
    `competition_result` — a knockout fixture can never end level;
    these record how a tie was actually settled.
12. `Version20260919211831` — push-notification infrastructure: creates
    `user_device` (FCM registration tokens, unique `device_token`, FK to
    `user` `ON DELETE CASCADE`) and `messenger_messages` (Symfony
    Messenger's Doctrine transport — first use of Messenger in this
    codebase), and adds `send_as_push BOOLEAN`, `push_sent_at TIMESTAMP`
    to `admin_message`.
13. `Version20260920075918` — creates `notification_log` (see
    `entities.md`), the audit trail for the push pipeline. Reuses the
    existing `messenger_messages` table for the new `failed` transport
    (`config/packages/messenger.yaml`) via a distinct `queue_name` rather
    than a separate table — no migration needed for that part.
14. `Version20260920114451` — adds `reminder_sent_at` to
    `competition_round` — claim-lock for the new `ROUND_STARTING_SOON`
    push (`CompetitionRoundReminderService`), same idiom as
    `locked_for_processing_at`'s round-processing claim-lock.
15. `Version20260920133550` — adds `auto_fill_spoof_entrants`
    (default false) and `auto_fill_delay_minutes` (default 20) to
    `competition_template` — dev/testing auto-fill, see `entities.md`
    and `CompetitionAutoFillService`.
16. `Version20260920185614` — adds `trophy_image VARCHAR(20)` and
    `trophy_colour VARCHAR(255)` (both nullable) to `competition_template`,
    mirroring `league`'s trophy columns — see `entities.md`.
17. `Version20260922095155` — decouples the competition
    round lifecycle's draw and resolve phases (previously fused into one
    `CompetitionRoundProcessorService` pass — now `CompetitionDrawService`
    + `CompetitionResultsService`, see `04_interfaces/output/services.md`).
    On `competition_round`: `locked_for_processing_at` is **renamed** (via
    `RENAME COLUMN`, not drop+add) to `draw_locked_at`; a new
    `resolve_locked_at` column is added (the resolve phase's own claim
    column — a round is now claimed twice in its life, once per phase);
    a new `matches_resolve_at` column is added (this round's resolve
    due-time, set the instant it's drawn); a data-only `UPDATE` remaps
    existing `status` values (`pending`/`scheduled`→`draw_pending`,
    `running`→`drawn`, `completed`→`results_published`); a new
    `(status, matches_resolve_at)` index backs the resolve-due query
    alongside the existing `(status, scheduled_at)` draw-due index. On
    `competition_template`: adds `intermission_ratio DOUBLE PRECISION
    NOT NULL DEFAULT 0.3` — see `entities.md`.
18. `Version20260924230000` — adds nullable `secondary_position` to `player`
    (an optional secondary position, same `PlayerPosition` enum as `position`).
19. `Version20260925120000` — adds nullable `identity JSON` to
    `npc_club` (kit + badge config; see `entities.md`'s `NpcClub` entry and
    CLAUDE.md's "Kit & Badge Identity"). The follow-up avatar/kit
    generation demographic-rules pass (age-banded hair weighting, skin-linked
    lip color, rare ginger hair, player-only face/headband defaults, NPC club
    badge-centre exclusion + kit contrast checks) is pure generation-logic —
    no migration of its own.
20. `Version20260927120000` — adds nullable `name VARCHAR(100)`,
    `nationality VARCHAR(60)`, `gender VARCHAR(10)`, `dob DATE`,
    `appearance JSON` to `"user"` (owner identity — see `entities.md`'s
    `User` entry and CLAUDE.md's "Owner Identity"), and drops the now-unused
    `manager_profile` column from both `"user"` and `club`.
21. `Version20260927181328` — drops the dead, never-read
    `npc_squad_config JSON` column from `game_config` (see `entities.md`'s
    `GameConfig` entry — the real, live per-tier squad/staff config lives on
    `StarterConfig` and was untouched by this migration, since it only grew
    new keys inside its existing JSON payload).
22. `Version20260928151627` — World Pack generation tracking schema
    (`WorldPackGenerationRun`/`TierRun`/`ClubRun`) — see
    `02_architecture/output/structure.md` for the Messenger-driven
    orchestration this backs; not detailed here.
23. `Version20260928200000` — adds nullable `home_kit_config`,
    `away_kit_config`, `badge_config JSON` to `club` (the real, player-owned
    club's own kit+badge identity — see `entities.md`'s `Club` entry and
    `docs/api/club-kit-identity.md`).
24. `Version20261002120000` — creates `user_ledger`
    (centralized, cross-club dividend-draw audit trail — see `entities.md`'s
    `UserLedger` entry and CLAUDE.md's "Centralized User Ledger").
25. `Version20261003112841` — sync v2: adds nullable
    `player_career_stat.appearance_config JSON` and creates
    `staff_career_profile` (identity + avatar config per currently-hired
    staff member, no stats columns — see `entities.md`'s entries for both).
26. `Version20261014194623` — index only, no schema change:
    `idx_player_youth_draw` on `player` (`date_of_birth`, `potential`,
    `nationality`) for `GET /api/players/youth`. Before it, `player` carried
    only its primary key and an `agent_id` index, so every youth draw
    sequentially scanned the whole pool. Declared on the entity as
    `#[ORM\Index]`, not just in raw migration SQL, so `schema:create` builds
    it and `migrations:diff` doesn't propose dropping it.
27. `Version20261014223020` — telemetry HUD columns 3 & 4: adds
    `live_telemetry_snapshot.total_goals`/`total_matches`/`table_turnover`,
    drops the six dilution/covenant/fallout columns that fed the replaced stat
    boxes. Dropping is safe here specifically because the table is a single-row
    cache rebuilt in full by `app:telemetry:generate` every 15 minutes — no
    source data lives in it.
28. `Version20261015072452` — adds four youth ability-ceiling
    columns to `pool_config` (under-16 → 10%, under-20 → 20% by default).
    Additive with defaults, so the single existing config row picks them up
    with no data migration; nothing is backfilled, since they shape future
    generation only.
29. `Version20261015093949` — moves the youth-draw
    home-nationality mix from a `YouthPoolService` const onto `pool_config`
    (four per-tier percentage columns, defaults 95/85/70/45 matching the
    constant they replace, so behaviour is unchanged until edited).
30. `Version20261015213517` — adds
    `pool_config.player_first_team_age_min` (default 19), the age floor for
    starter packs and generated NPC squads. Fixes squads of 11-12 year olds:
    one global age range governed both pool contents and squad eligibility,
    and the starter draw had no age condition at all.
31. `Version20261018202041` — replaces the trophy asset
    slug + colour enum with the trophy sprite's config. On both `league` and
    `competition_template`: adds `trophy JSON DEFAULT NULL`, backfills it from
    the old columns, then drops `trophy_image` and `trophy_colour`. The only
    migration so far to **convert** rather than add — worth reading its
    docblock, which records the mapping: `gold`/`silver` carry over and
    `gold_silver` becomes the sprite's `twotone` (so no admin loses a colour
    option), while the 15 slugs spread across the 10 styles in order, there
    being no artwork correspondence between hand-drawn silhouettes and
    procedurally drawn shapes. Only `trophy-1`..`trophy-8` were ever in use
    (one per league tier), so each tier landed on a distinct style. A null or
    unparseable slug stays null — "no design set" meant "no trophy", a state
    the honours board and the tier-defaults table both rely on. `down()`
    reverses it as faithfully as the shapes allow (a style names only the
    first slug that produced it; ribbon colours have no legacy counterpart).
    `game_config.league_tier_defaults` holds the same pair inside its stored
    JSON and is deliberately **not** touched: `GameConfig::normaliseTierRow()`
    folds legacy rows on read and write, which also covers config export files
    taken before the change. See `entities.md`, CLAUDE.md's "Trophy Identity"
    section and `docs/api/trophy-config.md`.
32. `Version20261023133500` — creates `friendly_challenge`
    (Friendlies Challenge Board matches, stakes, snapshots, and results) and
    `idempotency_record` (24-hour `Idempotency-Key` request/response store
    keyed by `(club_id, idempotency_key, endpoint)`).
33. `Version20261024012500` (most recent) — adds `computation_audit_json JSON DEFAULT NULL`
    to `competition_result` to store an engine-agnostic derivation & computation
    audit trail for every tournament match result.

## Takeaway

Active development from mid-September through 2026-09-27 progressed
through: (a) `LiveTelemetrySnapshot` built incrementally field-by-field,
(b) the full Competition subsystem stood up in one large migration, (c)
a `club.is_spoof` flag, (d) the match-result payload enriched with full
club/lineup data and extra-time/penalty-shootout outcomes so a knockout
fixture is never left looking like an unresolved draw, (e) push
notifications (device tokens + a Messenger-backed async send pipeline)
added so the backend can trigger native OS notifications for
competition events (round drawn, new registrant, match result) and
admin broadcasts, later followed by (f) a `NotificationLog` audit trail
+ admin debug tooling once a missing `FIREBASE_SERVICE_ACCOUNT_JSON`
secret caused sends to fail invisibly, (g) three more competition push
types (`COMPETITION_COMPLETED`, `NEW_COMPETITION_OPEN`,
`ROUND_STARTING_SOON`) filling the remaining gaps identified once the
pipeline was actually confirmed working end-to-end on a real device,
(h), on a separate `sprites` branch merged in afterward, two small
additive columns — `player.secondary_position` and `npc_club.identity`
— for the new pixel-art sprite/kit system's admin-editable config, and
(i) consolidating the previously-duplicated, never-serialized "manager
profile" concept (separate ad hoc blobs on both `user` and `club`) into
one structured owner identity on `"user"` alone, and (j) three additive
columns for the landing-page work — `club.reputation_decayed_at` (the
per-club anchor that makes `app:reputation:decay` idempotent and
catch-up-safe, see `docs/reputation-decay.md`) plus
`club_spotlight.trophies`/`established_at` (the featured club's honours
board and founding date). Note `trophies` is added with a `DEFAULT '[]'`
that is then dropped: `club_spotlight` is a singleton that already holds a
row, so a bare `NOT NULL` add would fail. See
`04_interfaces/output/services.md` and `services.md`'s
`PushNotificationService`/`NotificationLoggingSubscriber` entries.
Consistent with
`02_architecture/output/git-activity.md`'s hotspot findings (Competition
feature + admin panel polish).

## Retry-safety migrations (2026-10-24)

Three additive columns and two unique indexes, all from the production Sentry sweep
(`docs/2026-10-24-sentry-sweep-backend-brief.md` and its answers doc), driven by the
client gaining a retry queue — every one exists because a request the client now retries
was not safe to retry:

- `Version20261024012500` — `competition_result.computation_audit_json`, the
  engine-agnostic match derivation audit (admin-only; never in a client payload).
- `Version20261024132000` — `season_snapshot.response_json` plus
  `uq_season_record_club_season` and `uq_season_snapshot_club_season`. Makes
  `POST /api/league/conclude-season` idempotent per club + season. **Will fail if the
  target database already holds duplicate `(club_id, season)` rows** — the migration's
  own docblock carries the query to find them and says which row to keep, and why
  (`hallOfFamePoints` is summed from `season_record`, so duplicates double-count titles).
  Applied clean against the local prod restore; verify on prod before deploying.
- `Version20261024140000` — `club.starter_pack_json`, so an already-initialized club can
  be served its starter pack again instead of a 409. Deliberately left null for existing
  clubs: their pool entities were deleted on consume, so those squads really are gone.
- `Version20261024222441` — `user.debug_logging_enabled`, the per-account override for the
  in-app debug log panel (see `entities.md`'s `User` entry). Defaults false on every
  existing row, so it changes nothing on its own, and the flag is additive to the global
  `GameConfig` switch rather than replacing it. The generated diff also swept in unrelated
  `COMMENT ON COLUMN` churn for `friendly_challenge`/`idempotency_record` (pre-existing DBAL
  comment drift, unrelated to this column) — that was stripped, so expect `migrations:diff`
  to keep proposing it until someone deals with it deliberately.
- `Version20261026005046` — `club_spotlight.facilities`, the four-site sprite config payload
  (training/medical/scouting/stadium) the redesigned landing page draws. Additive, nullable,
  no backfill: existing rows stay null until the next spotlight generation, and null means
  "no payload" rather than "no facilities" — the sprite defaults are demo configs, so the two
  must not be confused. `stadium_config` is left exactly as it was; `landing.js` consumes it.
  Same pre-existing `COMMENT ON COLUMN` churn was stripped from the generated diff as for
  `Version20261024222441`.
- `Version20261027230000` — splits `competition_entrant.snapshot_json` into a bounded
  `club_display_json` plus `competition_entrant_squad` (XI, staff, engine-only club keys),
  and adds `client_snapshot_at` / `squad_player_count`. `facilities` is dropped outright:
  accepted from clients, stored on every entrant, read by nothing. Carries existing rows
  across in SQL, with every `jsonb` extraction `jsonb_typeof`-guarded because the column is
  unvalidated client JSON at the section level and `jsonb_array_length()` raises rather than
  returning NULL on a non-array — one malformed historical row would otherwise abort the
  whole migration. The unique index on `entrant_id` is named explicitly on the entity rather
  than left to `unique: true` on the join column, or `migrations:diff` proposes renaming it
  to Doctrine's generated name forever.
- `Version20261027234500` — adds `competition_response_cache`, the prebuilt
  viewer-independent `GET /api/competitions/{id}` document. No backfill: an absent row is a
  miss and the first reader builds it.
- `Version20261028001500` — splits `friendly_challenge`'s two inline snapshots into
  `challenger_club_display_json` / `acceptor_club_display_json` plus
  `friendly_challenge_squad`. **Deletes data by explicit authorisation**: tournaments and
  friendlies were not live, and clearing them was approved specifically so this migration
  needs no backfill and the new NOT NULL column needs no synthetic default. It truncates
  *instance* data only — `active_competition`, `competition_entrant`,
  `competition_entrant_squad`, `competition_fixture`, `competition_response_cache`,
  `competition_result`, `competition_round`, `entrant_reward_claim`, `friendly_challenge`,
  `friendly_challenge_squad`. The admin-authored catalogue (`competition_template`,
  `reward_template` and their join table) is configuration, not data, and is untouched.
  Tables are named explicitly rather than using `CASCADE`, so a table added later that
  references one of these fails the migration loudly instead of having its rows silently
  cascaded away — and `friendly_challenge_squad` is created *before* the truncate and named
  in it, because it already exists wherever `schema:update` built it from the entity (as on
  the test database), where the other order failed on the very FK the truncate respects.
- `Version20261028010000` — moves the admin-only forensics off rows public endpoints read:
  `competition_result_audit` (`event_log_json`, `computation_audit_json`) and
  `friendly_challenge_audit` (`event_log_json`). Between them those columns had one reader in
  the repository while being decoded on every public bracket read, every
  `GET /api/matches/{id}`, every club-hub query and every COMPLETE challenge the public board
  listed. No backfill — the clearing migration above runs first. These rows are deliberately
  **not** pruned on completion, unlike entrant squads: an audit exists to answer "how was
  this result derived" about a match already played, which is when anyone asks.
- `Version20261028023000` — replaces `competition_response_cache.state_version` (INT) with
  `state_fingerprint` (VARCHAR(64)). The integer was additive and **collided**: the entrant
  count excludes withdrawn rows while the snapshot-version sum includes them, so a withdrawal
  netted -1 and another club's resubmission +1, they cancelled, the cached row still looked
  fresh, and a stale bracket was served behind a 304. It also omitted the competition's
  status, cancellation and completion plus every template field the document embeds. The
  table is a cache, so existing rows are truncated rather than converted — a miss is already
  the correct behaviour, which is why the new column can be NOT NULL with no default.

- `Version20261028141656` — **comments only, no structural change.** Drops the eleven
  leftover `(DC2Type:uuid)`/`(DC2Type:datetime_immutable)` column comments on
  `friendly_challenge` and `idempotency_record`, written by migrations predating the DBAL 4
  upgrade (DBAL 4 no longer annotates these types, so the current mapping emits none).
  Nothing about any column, index, constraint or row changes. It exists because those empty
  strings were the entire reason `doctrine:schema:validate` reported the schema out of sync,
  and a permanently red validate hides real structural drift — which during the tournament
  data-model work was the signal that caught an entity silently losing its
  `use Doctrine\ORM\Mapping as ORM` import, with `php -l` passing and the suite green.
  `down()` restores all eleven exactly as they were.
