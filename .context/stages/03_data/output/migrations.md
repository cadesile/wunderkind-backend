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

### 2026-11-12 — the economy scaffolding, three migrations, all provably inert

`Version20261112160000` / `...160100` / `...160200`. The whole point of the set
is that **applying it changes nothing**: country factors default to parity, the
wage model to `legacy`, the inflation rate to 0. Turning anything on is a later
operator action. Verified by building a scratch database from the dev schema,
applying all three, diffing the result against a schema built from the mapping
alone (identical), then running every `down()` and confirming nothing of theirs
remained.

Two things in that set are worth copying:

- **A new NOT NULL json column is added WITH a default and then has the default
  DROPPED, in the same migration.** `game_config` is a populated singleton, so
  NOT NULL needs something to put in the existing row — but a json column that
  *keeps* a database-side default is drift the mapping does not declare, and
  `Version20261011134810` exists precisely because six json columns were added
  that way and left that way, after which `migrations:diff` proposed
  re-dropping those defaults on every unrelated change. Populate from the
  default, then drop it.
- **The partial unique index is declared in the ORM mapping as well as the
  migration**, with the predicate spelled exactly as
  `pg_get_expr(indpred, indrelid)` reports it (`(is_economy_baseline = true)`),
  per the rule at the top of `schema.md`.

`...160000` adds `country_profile.is_economy_baseline` + `economy_factor_bp`
and the partial unique index, and flags `EN` in `postUp()` — written as an
unconditional UPDATE, because a database with no `EN` row simply gets no
baseline and the resolver treats "no baseline" as parity for everyone, which is
the same inert state the defaults give.

`...160100` adds the nine `game_config` economy columns.
`...160200` creates `inflation_rate_history`, empty.

`Version20261112180000` (same day, step 4) adds `league.tv_pool_pence`, `league.slots` and
`league.league_position_decrease_percent`. **Its backfill is what makes the deploy provably
inert**: `tv_pool_pence = tv_deal * sizeOf(league)` means dividing the pool back by the same
size reproduces `tv_deal` exactly, by construction — so every league publishes the figure it
published before, whatever the divisor turns out to be, which is what let the size question be
settled on its merits rather than under deploy pressure. Verified on a full clone of the dev
dataset: **0 of 120 leagues** had `pool / slots <> tv_deal`, and no league with a NULL `tv_deal`
got a non-null pool ("no TV money" and "a TV deal of nothing" are different configurations that
`LeagueTierDefaultDiff` compares strictly).

`postUp()` authors `slots` from the **MAXIMUM** NPC count across each country's tiers rather
than the per-tier count: a properly-bootstrapped country's bottom tier is one short because that
slot belongs to the player's club, so the maximum is the playing size and using it makes `slots`
uniform per country (EN/DE/IT 18, six countries 16, five 8, SC 3).

`Version20261112200000` (same day, step 6) adds `league.parachute` and
`game_config.league_parachute`, both nullable and both shipping null — so every league publishes
`parachute: null` and nothing changes (verified on a full data clone: 120 of 120 leagues). Unlike
the json columns in the earlier migrations of this series there is no default to drop, because
nullable columns need nothing for the existing rows.

`Version20261112190000` (same day, step 5) lifts the five staff wage bands out of code into
`game_config`, seeded with StaffGenerationService's literals so `legacy` generation is unchanged.
It settles a disagreement rather than inventing values: `GenerateMarketDataCommand` held a second
set that differed for a coach.

`Version20261112170000` (same day, step 3) adds the four published-only constants —
`matchday_food_uptake_percent`, `npc_solvency_thresholds`, `dof_wage_bill_threshold_pct`,
`sponsor_weekly_pence` — and uses the same add-with-default-then-DROP-DEFAULT pattern for its
two json columns. The mechanic was verified directly rather than assumed: an existing row keeps
the backfilled value after `DROP DEFAULT`, and a subsequent bare INSERT then fails, which is
what proves the mapping owns the default afterwards.

`Version20261112220000` (same day, step 8) adds `game_config.economy_youth_scholarship_cap_bp`
(default 5000). **Its docblock states a rule that no longer holds** — it capped against the
tier's WORST first-team wage — and carries a supersession note saying so. Left unedited
otherwise, because the SQL in it is what ran on the boxes.

`Version20261112230000` (the day after the first nine deployed) adds
`game_config.economy_youth_scholarship_tier1_pence` and `…_floor_pence`, **both default 0 =
off**, and redefines the cap above as a share of the tier's AVERAGE wage. The scholarship becomes
an anchored ladder rather than a flat figure with a ceiling: see `entities.md` for the shape and
`docs/economy-threshold-review.md` §2 for why the first pass was only half a fix. Redefining the
cap's anchor rather than adding a second column is safe **only because the cap is gated on
`economyWageModel = turnover`, which is `legacy` on every deployed box**, so it has never applied
to a published figure. **This one is NOT yet deployed** as of the first nine's rollout.



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

## ⚠️ Never put a bare `?` in `addSql()` — DBAL reads it as a bind placeholder

Cost a failed dev deploy on 2026-11-02. `Version20261102120000` renames a key inside
`audience_group.criteria_payload` and guarded the UPDATE with Postgres's jsonb key-exists
operator:

```sql
AND criteria_payload::jsonb ? 'tutorialCompleted'   -- correct Postgres, breaks via DBAL
```

DBAL rewrote the `?` to a positional parameter and bound nothing, so the server received
`AND criteria_payload::jsonb $1 'tutorialCompleted'` and the deploy died with
`SQLSTATE[42601]: syntax error at or near "$1"`. **Use the function form instead** —
`jsonb_exists(col, key)`, and `jsonb_exists_any` / `jsonb_exists_all` for `?|` / `?&`.

**The process lesson is the sharper one. The SQL had been exercised against a real
Postgres 16 before the commit, on fixtures covering every branch — and that proved nothing,
because it was run in `psql`, not through Doctrine.** `?` is valid in one and a placeholder
in the other, so the only verification that could have caught it is the one that was
skipped: run `doctrine:migrations:migrate` (or `migrations:execute --up/--down`) against a
throwaway database. The host cannot run the suite (vendor needs PHP ≥ 8.5 against 8.4.13,
and `compose.yaml` has no PHP service), which is exactly why this was tempting to skip; a
`php:8.5-cli` container with `pdo_pgsql` and `intl`, pointed at the compose `database`
service, runs both the suite and the console fine.

**What made the failure survivable** was unrelated to the migration: the code it ships with
accepts the old key through `AudienceCriteriaEvaluator::LEGACY_KEYS`, so dev kept working on
un-migrated data between the failed deploy and the fix. A rename migration whose code half
fails closed would have taken the feature down instead.

Note also what a mid-deploy failure costs beyond the migration: `deploy-dev.yml` runs the
seeders *after* `migrations:migrate`, so none of them ran. All are idempotent or
TRUNCATE-and-rebuild, so the previous deploy's data stood — but a deploy that stops here
leaves new code against an old schema, which the workflow's own `concurrency` comment calls
out as the reason `cancel-in-progress` is false.

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
(recorded in `shared/last-sync.md`), driven by the
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
  clubs: their pool entities were deleted on consume, so those squads really are gone. (Starter
  squads stopped being drawn from the pool on 2026-11-17 and are generated instead, so nothing
  is consumed any more — but the column is still the only copy, since a generated player exists
  nowhere else.)
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

- `Version20261028193420` — **additive only: two new tables**,
  `competition_entrant_submission` and `friendly_challenge_submission`, each holding
  `envelope_json` (the client's posted registration body, verbatim) and `submitted_at`,
  FK'd `ON DELETE CASCADE` to `competition_entrant` / `friendly_challenge`. Nothing already
  stored moves or changes, so the split's existing read profiles are untouched and there is
  **no backfill** — rows begin at the next registration, and submissions for entrants that
  registered before this are simply absent (the admin field says so rather than showing
  `{}`). Deliberately **not** added to the completion prune that drops `*_squad`. Paired
  with raising `SnapshotValidator::MAX_SNAPSHOT_BYTES` from 256KB to 4MB, which is what the
  tables are for: the old cap was derived from an estimated 25KB snapshot and 422'd real
  1.4MB registrations. `down()` drops both tables cleanly.


- `Version20261031010000` — **two nullable columns on `leaderboard_entry`
  (`baseline_score`, `cumulative_score`), plus a one-off reset of in-flight period boards.**
  Turns every non-`'all-time'` row into a GROWTH row. `score` has never been an accumulator
  — every writer re-reads a cumulative total and stores it absolutely — so deleting rows at
  a period boundary resets nothing; the existing weekly board is the proof, getting a fresh
  key every ISO week and still showing all-time career goals. Only a baseline resets a
  score, and this is where one starts being kept. Both columns are nullable because
  `'all-time'` rows are standings and are never growth-scored, and because a baseline is
  stamped lazily at a club's first observation inside a period.
  **There is nothing to backfill** — a baseline is a statement about when a period started
  observing a club, and for every period already underway that was never recorded. The
  `UPDATE … SET baseline_score = score, cumulative_score = score, score = 0 WHERE period <>
  'all-time'` instead makes the discontinuity explicit and one-off: every in-flight row is
  declared to start measuring growth at its current cumulative. Without it those rows would
  sit at a stale cumulative with a NULL baseline and `assignRanks()` would rank the whole
  board on it. **Visible effect on deploy: the current ISO week's boards read zero and climb
  from there**, which is the intended semantics arriving a few days early. The statement is a
  constant on the migration class (`RESET_IN_FLIGHT_PERIODS_SQL`) so
  `LeaderboardGrowthResetTest` exercises the shipped SQL rather than a paraphrase.
  `down()` restores the cumulative into `score` before dropping the columns, so a rollback
  leaves period rows holding a standing rather than a zeroed growth.

- `Version20261031020000` — **additive only: four new tables**,
  `leaderboard_award_definition`, `leaderboard_award_placement`,
  `leaderboard_period_archive` and `leaderboard_period_archive_entry`. One migration because
  they are one feature and a half-applied set is useless. Nothing already stored moves or
  changes, and there is **no backfill of any kind**: an archive is a record of a *close*, and
  nothing has ever closed a period, so the first close of each enabled definition writes the
  first row. See `schema.md`'s "Notable constraints" for why
  `uq_leaderboard_period_archive_category_period` can be a plain unique (and why `category`
  and `cadence` are therefore denormalised onto the header), and why
  `uq_lpae_archive_place` is the one partial index here. `down()` drops all four in
  dependency order.

- `Version20261031214540` — **one column, additive**:
  `game_config.youth_scholarship_wage_pence INT NOT NULL DEFAULT 25000`. The flat weekly wage
  (PENCE) for a player SIGNED below the first-team age, so the academy scholarship can be
  repriced from the admin panel instead of an app release.

  **An unmigrated database is safe**, which is why this needs no backfill beyond the column
  default: the app reads `config.youthScholarshipWagePence ?? YOUTH_SCHOLARSHIP_WAGE_PENCE`
  and its own fallback is the same 25000. ⚠️ Note that the guard is `??`, not falsiness — a
  **0** sent from here would NOT fall back and would make scholars free. That is why the
  column is `NOT NULL DEFAULT 25000` rather than nullable, and why the setter floors at 0
  rather than defaulting to it.

  **It IS in `SyncService`'s config payload**, in both branches — and the no-config fallback
  branch sends the real default, **not 0** like the sponsor bands beside it. That difference
  is the whole point: `0` has to stay a legitimate admin choice (a club that pays its
  scholars nothing), so it cannot also mean "unset", and a 0 sent from here would sail past
  the client's `??` rather than falling back.

  **The default has ONE home on this side: `GameConfig::DEFAULT_YOUTH_SCHOLARSHIP_WAGE_PENCE`.**
  The column default (via the `#[ORM\Column]` attribute — a class constant is a valid
  constant expression there, and `doctrine:schema:validate` confirms it maps identically),
  the property initialiser, `SyncService`'s no-config branch and the admin form's POST
  fallback all READ it; `GameConfigYouthScholarshipTest` pins the figure itself exactly once.
  The migration's SQL keeps the literal 25000 on purpose — a migration records the state of
  the schema on the day it ran and must not change meaning when a constant later does.
  The app's `YOUTH_SCHOLARSHIP_WAGE_PENCE` is the remaining copy, and it is the client's
  pre-sync fallback; the app pins it against its own `DEFAULT_GAME_CONFIG` in a test.

## Correctable season archives (2026-11-01)

- `Version20261101120000` — `season_record.overwrite_count` (`INT DEFAULT 0 NOT NULL`) and
  `season_record.overwrite_audit` (`JSON DEFAULT NULL`). Additive, no backfill: `0`/`NULL`
  is truthful on every existing row, since none has been corrected. It is the schema half
  of letting `POST /api/league/conclude-season` **overwrite** a season it has already
  archived instead of silently replaying the first answer — a season filed with wrong
  figures used to be wrong permanently. Only a resubmission whose *result figures* differ
  counts; an identical retry still writes nothing, which is what keeps the client's retry
  queue from inflating the counter. See `entities.md`'s `SeasonRecord` entry and
  `04_interfaces/output/services.md`'s `LeagueService` entry for what a correction
  deliberately does **not** touch.

  `INT` rather than `SMALLINT` on the counter so a client stuck in a resubmission loop
  cannot overflow it into a 500 that the outbox then retries forever.

## A competition entry outlives its club (2026-11-02)

- `Version20261102140000` — `competition_entrant.club_id` becomes **nullable, ON DELETE SET
  NULL**, where it was NOT NULL / CASCADE. No backfill; nothing existing changes value.

  **The bug it fixes was visible in the game.** `competition_fixture`'s `home_entrant_id`,
  `away_entrant_id` and `winner_entrant_id` are already ON DELETE SET NULL, so cascading the
  entrant row away with its club blanked that club out of every bracket it had ever played in
  — a finished cup's opposition reverted to "To be decided", and the scorelines with it, since
  the client only renders a score on a side it can resolve. And it fired on a schedule:
  competitions are filled with spoof clubs by `CompetitionAutoFillService`, and
  `app:clubs:purge-spoof` ran on EVERY deploy in both workflows until 2026-11-02, so each
  deploy emptied out the cups players had just won. **That deploy step has since been removed**
  (both workflows; purging is now an operator action via the admin's "Clear Spoof Clubs"), but
  a club can still be deleted at any time, so this column remains the fix rather than a
  belt-and-braces.

  Keeping the entrant row keeps `clubDisplayJson` with it — the frozen name, badge, kits and
  tier as they were at registration — which is the whole of what a bracket renders. The
  unique index on (`active_competition_id`, `club_id`) is unaffected: Postgres treats NULLs
  as distinct, so any number of orphaned entries may sit in one competition. Everything
  hanging off the entrant (`competition_entrant_squad`, `competition_entrant_submission`,
  `entrant_reward_claim`) still cascades from the entrant, so a deleted club no longer drags
  those with it either.

  `down()` restores CASCADE but deletes null-club rows first — it cannot restore
  non-nullability over rows the original constraint would reject, and deleting them is exactly
  what the old cascade would have done.

  **This does not repair brackets already damaged** — those entrant rows are gone. That repair
  is code, not schema: `CompetitionResponseBuilder::serializeFixtureSide()` falls back to
  `competition_result`'s frozen club copy, which survived because that table cascades from the
  FIXTURE rather than the club. See `04_interfaces/output/services.md`.

- `Version20261102160000` — `live_telemetry_snapshot` gains `promotions` and `relegations`
  (INT NOT NULL DEFAULT 0), the landing page's Table Turnover tile split by direction
  ("▲ 7 ▼ 4" where it read "11"). Same rows, same 7-day window; see
  `03_data/output/entities.md` for why the window did NOT move to 24h with the split.

  `table_turnover` **stays, and `app:telemetry:generate` keeps writing it** — it is published
  as `stats.tableTurnover` on `GET /api/telemetry`, so dropping it would break a field an app
  release already in the wild reads. It equals promotions + relegations.

  `postUp()` backfills `promotions` from `table_turnover` rather than leaving both at 0. The
  table holds one snapshot row and the next cron tick overwrites all three anyway, but a
  landing page rendered in between would otherwise show ▲0 ▼0 beside a live feed. The split
  cannot be recovered from the total, so the whole of it goes to `promotions`: wrong in detail
  for at most one interval, where 0/0 would be wrong AND look broken.

  Written with the Schema API (`addColumn`/`dropColumn`) rather than raw DDL, per this
  codebase's PostgreSQL-only rule.

## 2026-11-03 — location & land-plot economy (six migrations)

| Version | What |
|---|---|
| `Version20261103120000` | Geography: `country_profile` (natural PK `code`), `location`, `location_rival` (+2 raw CHECKs), `land_plot` |
| `Version20261103150000` | AI enrichment history: `location_enrichment_run`, `location_enrichment_attempt` |
| `Version20261111120000` | `country_profile.prestige_suffixes` / `generic_suffixes` / `stadium_formats` (json, nullable) — operator-authored club-naming content, overriding `CountryContentRegistry`'s PHP consts. NULL is the normal state; no data migrated |
| `Version20261112010000` | `country_profile.first_names` / `last_names` (json, nullable) — operator-authored person-name pools, overriding `NamePoolRegistry`'s PHP consts. NULL is the normal state; no data migrated. 39 of 71 countries ship with no pool, and authoring one was previously a deploy |
| `Version20261103180000` | `user_ledger`: nullable `club_id` + SET NULL, `source_kind`/`source_ref` + partial unique |
| `Version20261103200000` | `stadium_estimate` + `stadium_estimate_input` |
| `Version20261103220000` | `plot_hold`, `plot_claim`, `plot_claim_valuation` — three partial uniques |
| `Version20261103230000` | `user_ledger` money columns INTEGER → BIGINT |

Four things worth knowing before touching these:

- **Six new partial unique indexes**, all declared on their entities with the predicate
  spelled exactly as `pg_get_expr(indpred, indrelid)` reports it, and all verified to
  round-trip. `tests/Doctrine/SchemaIsInSyncTest.php` was added for exactly this: it
  asserts `SchemaTool::getUpdateSchemaSql()` is empty, which is the automated form of
  "`migrations:diff` reports clean" and the only reliable guard against predicate churn.
  It excludes the migrations storage table, whose name it reads from the migrations
  configuration rather than hardcoding.
- **`location_rival`'s two CHECK constraints are raw SQL only.** Doctrine cannot map
  CHECKs; that is safe because `migrations:diff` ignores them entirely. Do not "fix"
  their absence from the entity.
- **`Version20261103230000` is a required widening, not hygiene.** Real-world stadium
  prices (~£1,500/seat) exceed the 4-byte integer cap of £21,474,836, and an
  account-level balance accumulates across ten to fifteen clubs by design. Its `down()`
  narrows again and will FAIL rather than truncate if any row is out of range — correct
  behaviour for money.
- **`Version20261103180000`'s `down()` is lossy on purpose**: account-level entries
  (grants, pre-club purchases) have no club and cannot exist in the old NOT NULL shape,
  so they are deleted rather than silently attached to an arbitrary club.

## 2026-11-14 — `club_sale`, one additive table

`Version20261114102300` — the authorisation/receipt row for an admin club buyout. See
`entities.md`'s `ClubSale` entry for the shape and `services.md` for what consumes it.

**Provably inert on apply.** One new table; no column added to, widened or dropped from an
existing one, and nothing backfilled. Nothing reads it until an operator uses the new admin
action, so applying it changes no behaviour anywhere. The `UserLedgerEntryType::CLUB_SALE`
case that goes with it needed **no migration at all** — the column is `varchar(30)` and
`tests/Enum/UserLedgerEntryTypeTest.php` asserts every value still fits it.

Two details that are deliberate and should not be "tidied":

- **`club_id` carries no foreign key.** This row exists precisely to outlive the club it
  describes — `ClubSaleService` deletes that club moments after crediting — so an FK would
  be SET NULL at best and would take the club's identity with it. Same reasoning, and the
  same technique, as `season_ratings_snapshot.club_id`.
- **`uq_club_sale_club_live` is PARTIAL** (`WHERE (status)::text <> 'failed'`), so a FAILED
  attempt does not permanently block the retry that fixes its cause. A plain UNIQUE would
  need a row deleted by hand. Like the six partial uniques from 2026-11-03, the predicate is
  spelled exactly as `pg_get_expr(indpred, indrelid)` reports it, and
  `tests/Doctrine/SchemaIsInSyncTest.php` is what catches it if that drifts.

**Remember `wunderkind_test` is not migration-tracked** (see
`01_overview/output/environment.md`): this table reaches the test database only via
`doctrine:schema:update --env=test`, which was run for it, and `--dump-sql` reported zero
drift afterwards. A suite run before that step fails with `relation "club_sale" does not
exist` on every new test, which looks nothing like the change.

## `Version20261119150000` — resync the `tv_deal` cache `Version20261119120000` left stale

**`tv_deal` is not an independent figure wherever a pool exists: it is a CACHE of
`tvPoolPence / slots`.** `LeagueSizeResolver::syncTvDeal()` is the only writer and it runs on an
**admin save only** (`LeagueAdminController:95`/`:201`), so when `Version20261119120000` corrected
`slots` the figure derived from `slots` was left encoding the old divisor and nothing re-ran the
sync. From the deployed world export of 2026-11-19, EN tier 1: pool `30000000000`, resolved
`tvDeal` `1875000000` (pool/16), **stored `tv_deal` `1666666666` (pool/18)**.

**No client was affected, and that matters for how to read this.** All four publication sites
resolve `derivedTvDealPence() ?? getTvDeal()` (`SyncService:834`, `InitializeController:251`,
`LeagueService:306`, `LeagueImportExportService:66`), so the stale column was invisible
downstream. The one reader that did not resolve was the release gate, `app:economy:wage-table`,
which paired this column with the *corrected* `sizeOf()` — **England's TV income read 12.5% low**.
**The code fix in the same commit is the half that matters**; this migration exists because the
column is a published cache and the next reader should not have to know its history.

⚠️ **ONLY WHERE `tv_pool_pence IS NOT NULL`, AND THAT IS THE WHOLE CARE HERE.** A pool-less league's
`tv_deal` is an AUTHORED absolute an operator typed, not a cache — exactly the case
`derivedTvDealPence()` returns null for. Writing a derived figure over it would invent a pool
nobody authored. `slots > 0` for the mirror reason: `slots = 0` means derive, and dividing by it is
not a figure. PostgreSQL's bigint `/` truncates toward zero like PHP's `intdiv()`, so the stored
figure matches the resolved one to the penny rather than to a rounding mode.

**`WORLD_PACK_VERSION` is deliberately untouched** — not a pack-shape change. Every publication
site was already serving `pool / slots`, so this writes into the column the value clients were
already being given.

**Proved reversible on the clone rather than asserted.** The clone's own rows are already
consistent (`tv_deal = pool / slots` at every EN and SC tier), so the migration is a verified
**no-op** there: the ordered `tv_deal` column's md5 is `baa40bc2e46a16ceeac928053cc52159` before
and after migrating. To exercise `up()`, the clone was temporarily staled to the deployed box's
shape (slots 16/14, `tv_deal = pool/18`) and the round trip measured:
`797da64c59f65551f3cb980ebe76a7f9` -> up -> `a75902d6cde7efde3068ea3b2d8ea6a3` -> down ->
`797da64c59f65551f3cb980ebe76a7f9` -> up -> `a75902d6cde7efde3068ea3b2d8ea6a3`; the clone was then
restored to its own fingerprint. `down()` restores from `league_tv_deal_pre_resync_backup` and
**never recomputes** — recomputing would be the same bug in reverse, overwriting an operator's
later edit with whatever `slots` says at rollback time. The stash column is nullable because
`tv_deal` is, so a league that held NULL goes back to NULL. Pinned by `LeagueTvDealResyncTest`,
which runs the migration's own `RESYNC_SQL` constant and covers the stale row, the **null-pool row
that must not move**, a null cache that must stay null, the `slots = 0` hands-off case,
idempotency, and `intdiv` truncation.

## `Version20261119120000` — correct an authored `slots` that disagrees with the real size

**A BEHAVIOUR CHANGE, deliberately, and the other half of `Version20261119090000`.** That one
authored `slots` only where it was 0 and refused to touch an authored value, correctly, because no
decision existed. The owner made it on **2026-11-19**: **`slots` equals the division's real size**
— 18 was never anybody's decision, it is residue from the spoof purge, which took club rows down
and left `slots` where it was. It does **not** settle 16-vs-20, which is about what to GENERATE.

**Corrects 12 rows on the deployed box** (world partitions exactly 184 + 12 + 4): EN tiers 1–8
from 18 to 16, SC tiers 1–4 from 18 to 14. `SC` tiers 5–8 are `slots = 0` and belong to
`Version20261119090000`; the other 184 already agree. No payout money moves —
`LeaguePayoutResolver` never divides by size — but **check 8's per-club arithmetic**, the
**published `clubCount`** and the app's `tvPoolPence / clubCount` cross-check were all being fed a
false divisor. This recalibrates instruments, not the economy.

### ⚠️ The reserved player slot is DETECTED, not assumed — and an earlier draft got this wrong

`LeagueSizeResolver::sizeOf()` adds 1 at a country's deepest tier for the place the generator
leaves open for the player's club. **That is not universally true.** Measured on the development
clone, 2026-11-19: EN reserves (18 rows at tiers 1–7, 17 at tier 8) but **CA, JP, NG, PL, US and
SC do not** — every tier including the deepest holds the same count. A first draft used
`sizeOf()`'s rule and would have written **9 over a correct 8 in five countries, and 4 over SC's
correct 3**. So the target is `rows + 1` only at the deepest tier *and* only where it holds
exactly one fewer row than the tier above; `rows` otherwise. A single-tier country cannot
demonstrate a reservation and is treated as unreserved.

**Verified a complete no-op on the clone, not asserted:** the `slots` column's md5 fingerprint is
`d56d40bd232d2d31ebe7342f47e76b16` before migrate, after migrate, after rollback and after
re-migrate; `rows_changed` against the stash is **0**; `app:economy:wage-table` is byte-identical;
and SC tier 8 still reads its authored **3**.

⚠️ **AMENDED 2026-11-19 (A7): on the DEPLOYED box this migration was not inert, and the
byte-identical gate above is a clone reading that must not be generalised.** It moved `slots` on 12
rows there and left `league.tv_deal` — a cache of `tvPoolPence / slots` — encoding the old
divisor, because `syncTvDeal()` runs on an admin save only. See `Version20261119150000` above.

`down()` restores every row from `league_slots_pre_correction_backup` (a distinct table name from
`Version20261119090000`'s, so the two roll back independently) and never resets a column to 0 —
that would turn an authored decision into "derive". Pinned by `LeagueSlotsCorrectionTest`, which
runs the migration's own `CORRECT_SQL` constant and covers both the reserved and unreserved
shapes, the `slots = 0` hands-off case, and idempotency.

### A defect this exposes and does NOT fix

`sizeOf()`'s unconditional `+1` is wrong on any box whose deepest tier was not reserved. It is
inert wherever `slots` is authored (the authored value wins), so it bites only `slots = 0`
leagues — on the deployed box that is SC tiers 5–8, which *do* reserve, so nothing is wrong there
today. **Reported, not fixed: changing the resolver is wider than a data correction.**

**Still not fixed as of A7 (2026-11-19), but no longer silent.** `app:economy:wage-table`'s new
check 9 compares the resolved size against the real rows using *this* migration's detection rule,
so a box where the resolver's unconditional `+1` is wrong now prints it as an under-filled tier.
`EconomyAuthoredSizeDriftTest` pins that, which is what keeps the defect visible until somebody
decides to change the resolver.

## `Version20261116180000` + `Version20261116181000` — the league payout ladders (2026-11-16)

**A pair, and the split is the point.** The first adds schema and cannot move a figure; the
second is the re-pricing. Running only the first is a valid, inert state.

### `…180000` — four additive `game_config` columns, inert

- `economy_prize_tier_ratio_ppm` — json, eight `1000000`s (parity)
- `economy_position_pot_tier_ratio_ppm` — json, eight `1000000`s (parity)
- `economy_league_prize_tier1_pence` — bigint, `0` (unauthored)
- `economy_league_position_pot_tier1_pence` — bigint, `0` (unauthored)

Add-with-default then `DROP DEFAULT` on all four, so the mapping owns the default. Inert on two
independent counts: a `0` base propagates to null through `LeaguePayoutResolver` whatever the
ratios say, **and** every league held a non-null column, which the resolver returns verbatim as
an override.

⚠️ **PARTS PER MILLION, not basis points** — the one thing here not copied from
`Version20261115190000`. Tier 8 over tier 1 is 424 ppm = **4.24 bp**, so an integer bp table
would store `4` (a 5.7% error) and one bp step would be 23.6% of the figure. The sponsor ratios
bottom out at 96 bp, where bp is still fine.

### `…181000` — the re-pricing, which DOES move published money

One transaction, four steps, in this order:

1. `CREATE TABLE league_payout_pre_ratio_backup` and stash every league's two figures plus the
   old `league_tier_defaults` JSON and the two bases;
2. author both bases at `10000000000` (£100,000,000 — tier 1 unchanged) and both ratio tables at
   `[1000000, 309000, 91500, 51200, 18200, 6020, 1500, 424]`;
3. clear `prizeMoney`/`leaguePositionPot` **key by key** from each of the eight
   `league_tier_defaults` rows, so trophies, parachutes, TV and sponsor counts survive;
4. `UPDATE league SET prize_money = NULL, league_position_pot = NULL` — unconditional, because
   the stash makes `down()` exact and a `WHERE value = tier default` guard would skip exactly the
   rows whose default had already drifted.

**Why it is safe to clear all of them:** measured on the live export first — all 24 eight-tier
countries held a byte-identical prize/pot ladder and SC held rows 1-4 of the same one, so there
was not a single genuine per-league override anywhere in the world.

**`down()` is exact, not approximate.** A null does not record what it replaced, so the stash
table is the mechanism: `down()` restores leagues and `game_config` from it, resets both ratio
tables to parity and drops the table. Without it a rollback would reset to the shipped constants,
which differ from the authored England ladder at every tier.

⚠️ **`jsonb_exists(col, key)` RATHER THAN THE `?` OPERATOR** in step 3. DBAL parses `?` in
migration SQL as a positional placeholder and dies with `syntax error at or near $1` before
Postgres sees it. Cost one failed run to find.

⚠️ **Needs `WORLD_PACK_VERSION` 6 → 7**, landed with the code in the same release. Both figures
are baked into the cached league block, so without the bump packs keep serving the old ladder
with nothing about the payload looking different — the `tvDeal` incident of 2026-11-13 exactly.

⚠️ **`wunderkind_test` is not migration-tracked.** The four columns were added there by hand
(`ALTER TABLE game_config ADD COLUMN IF NOT EXISTS …`); without them 268 tests error on a missing
column, which looks nothing like the change.

## `Version20261115190000` — sponsor and ticket ratio tables (2026-11-15)

Three additive `game_config` columns, all shipping inert:
`economy_sponsor_tier_ratio_bp` and `economy_ticket_price_tier_ratio_bp` (JSON, eight basis-point
figures, index 0 = tier 1, default eight `10000`s = parity) and
`economy_ticket_price_tier1_pence` (BIGINT, default 0).

**Why, when `Version20261114100000` already added per-tier tables.** That migration added the
EXPLICIT tables — 48 sponsor figures per country, re-derived by hand every time the tier-1 band
moves. These express the same ladder as a tier-1 base plus eight ratios: nine figures, and a
retune of the base carries the ladder with it. The explicit tables are **not** replaced or
dropped; they remain the override (`SponsorBandResolver::bandFor()` reads them first).

**Two ratio tables, not one "tier shape"**, and neither is `economy_tier_factors_bp`: a ticket
and a shirt sponsor do not fall off at the same rate down a pyramid, and the wage factors price
wages. Three tables is three things to author; one shared table is a wage retune silently
re-pricing every sponsor and every ticket in the world.

**Inert by construction.** Parity ratios resolve every sponsor band to the flat column it
resolved to before, byte for byte; a 0 ticket base propagates through `TicketPriceResolver`
whatever the ratios say, so the published ticket price stays 0 ("unauthored"). Verified by
hashing `EconomyConfigPublisher::clientBlock()` before and after on a default-config box — all
five argument combinations byte-identical; `publicBlock()` differs only by the two added ratio
keys, which is the intended addition.

Add-with-default then `DROP DEFAULT` on all three, the pattern this series uses for JSON columns
so the mapping owns the default afterwards. Reversible: three drops.

**Remember `wunderkind_test` is not migration-tracked**: the three columns reached it via a hand
`ALTER` with the same defaults (`--force` refuses NOT NULL columns on existing rows), and
`doctrine:schema:update --dump-sql --env=test` reported zero drift afterwards. The local dev
database carries one pre-existing, unrelated drift statement (`COMMENT ON COLUMN
npc_club.land_plot_id IS ''`) which predates this change.

## `Version20261117120000` — world pack SETS (2026-11-17)

A world pack becomes a **set**: one starter squad plus a payload per league tier, generated
together and claimed by exactly one club, held two-deep per live country. Fixes two things at
once — a country could have warm packs and a pool too thin to draw a starter squad from (a
`412` at the very first step of game init, with no client-side escape), and every player in a
country was served the same byte-identical world.

New `world_pack_set`. `country_world_pack_cache` gains `set_id`; `club` gains
`world_pack_set_id`; `world_pack_generation_run` gains `set_count`;
`world_pack_generation_tier_run` gains `set_id`; `starter_config` gains
`world_pack_set_target` (2) and `world_pack_claimed_retention_days` (7).

The set's squad is **generated**, not drawn from the shared pool, so there is no
`starter_shortfall` column: generation produces the authored count exactly and a column that
can never be non-zero is a trap. See `04_interfaces/output/services.md`'s
`StarterPackService` entry.

Four things worth knowing before touching it:

1. **`set_id` is on the TIER RUN, not the run.** One admin regenerate builds a country's whole
   buffer, so a run covers `set_count` sets and its tier runs carry which set each belongs to.
   That leaves `world_pack_generation_run` the per-country unit it has always been, so
   `uq_world_pack_generation_run_one_active_per_country` is untouched.
2. **The tier run's widened key needs a PARTIAL COMPANION, and that forces its FK to
   CASCADE.** `(run_id, set_id, tier)` does not constrain set-less rows at all, because
   Postgres treats NULLs as DISTINCT — so `uq_..._run_tier_no_set` on
   `(run_id, tier) WHERE set_id IS NULL` restores the one-tier-run-per-(run, tier) guarantee
   that widening the key silently dropped. `WorldPackGenerationRunSchemaTest` caught that.
   **The companion index then rules out `ON DELETE SET NULL` on `set_id`:** one run covers
   several sets, so deleting a country's stock would null several tier runs sharing a
   `(run_id, tier)` and they would all collide — making an ordinary admin "delete this
   country's sets" fail with a unique violation raised from inside the FK. `ON DELETE CASCADE`
   resolves the conflict instead of hiding it; the cost is the per-tier detail of a set an
   operator chose to delete, which no longer describes anything reachable, and the run row
   itself survives. The two choices are load-bearing on each other — changing either one back
   reintroduces the other's failure. Caught by `WorldPackGenerationEndToEndTest`, which does a
   regenerate (and therefore a delete-unclaimed) over a country that already had sets.
3. **`uq_country_tier` was a table CONSTRAINT**, not an index (created as `CONSTRAINT
   uq_country_tier UNIQUE (country, tier)` in `Version20260512000001`), so it needs
   `ALTER TABLE ... DROP CONSTRAINT`; `DROP INDEX` fails on it.
4. **The backfill mints UUIDs in PHP, never `gen_random_uuid()`.** Every id in this schema is
   typed `UuidV7`; `gen_random_uuid()` returns a v4, which Doctrine's `UuidType` hydrates into a
   `UuidV4` that then cannot be assigned to a `UuidV7` property — a TypeError on read, long
   after the migration passed. `Version20261027230000` backfills `competition_entrant_squad.id`
   that way and is an unfixed instance of it.

`postUp()` wraps each country's existing cache rows in one `ready` set so `set_id` can become
NOT NULL without discarding a single warm payload, taking `MIN(payload_version)` (not MAX, so a
mixed-version country still presents as stale and is rebuilt). Those sets carry
`starter_pack_json = NULL` and the claim predicate excludes exactly that, which is what makes
the deploy a provable no-op: nothing is deleted, nothing starts failing, and the live draw keeps
serving until real sets exist.

**`down()` IS NOT LOSSLESS, and cannot be.** `uq_country_tier` means one payload per
(country, tier) and the whole point of the migration is that a country holds several, so the
rollback keeps each country's OLDEST set and deletes the rest (payloads follow via CASCADE).
That is the substance of the rollback, not a side effect — going back to the old schema IS going
back to one world per country. Oldest rather than newest so a country whose only surviving set
is CLAIMED does not have a device's world pulled out from under it.

**`wunderkind_test` needed the hand treatment** (it is not migration-tracked): the new columns
and indexes applied directly, plus four `game_config` economy columns from
`Version20261116181000` that the test database had never received at all — which had been
failing every test that reads `GameConfig` with `column ... does not exist`. Added with
defaults, then the defaults dropped, and the values set to the ENTITY defaults (0 / parity ppm),
not the migration's authored ladder, since authoring them turns the economy on and breaks
`WorldOverviewServiceTest`'s "an unset tier must stay null".

## `Version20261117150000` — staff pay gets a tier dimension (2026-11-17)

Two columns on `game_config`: `economy_staff_tier_scaling_enabled` (bool, **default true**) and
`economy_staff_tier_floor_pence` (int, default 500).

The defect: a tier-8 club paid ~£1,000/week in staff against ~£165/week for its entire squad.
`economy_staff_role_bands_pence` is a single absolute per role, so it can only ever be right at
one tier, while a legacy player's wage scales with an ability that IS per-tier. The same shape
of defect as the youth scholarship, where a flat £250 was 1.97x an average tier-8 wage and
0.19% of an average tier-1 wage.

**It ships ON, deliberately breaking this repo's ship-inert habit.** That habit is for changes
that are untuned; this one is wrong. The switch remains a column so a box can have the old
figures back without a deploy — and it must be reversible, because the figure is baked into
cached world packs.

**Paired with `WORLD_PACK_VERSION` 7 → 8**, in the same commit. `buildStaffSnapshot()` writes
`weeklySalary` into every pack, so repricing staff is a CONTENT change at an unchanged shape —
precisely what that constant exists for. The bump makes every `WorldPackSet` unclaimable at
once; `app:worldpack:maintain` restocks live countries unattended, and until it does new games
generate their world live.

A second, code-only half went with it: staff ABILITY is now drawn from the club's tier band
rather than a single global range, so a tier-8 side can no longer field a world-class manager.

## `Version20261118120000` — the re-derived economy, resolution R2 (2026-11-18)

**Authoring, not schema.** Six figures on `game_config`, in one transaction, in
`Version20261116181000`'s shape: stash everything replaced into `economy_r2_pre_authoring_backup`
(one row, plain columns), write the new values, and have `down()` restore from the stash and drop
the table. Verified as an exact round-trip on a clone: `down()` puts back the turnover row, both
ratio tables, both tier-1 bases and the whole facility column — including REMOVING the six band-3
slug keys `up()` created — and drops the backup table.

What it authors:

1. `economy_baseline_turnover_pence` → `[£331m, £114m, £34.6m, £20.7m, £7.32m, £2.69m, £686k,
   £215k]`. ⚠️ **This is the BASELINE country's table, not England's** — every country is this row
   times its `economy_factor_bp`, all at parity today, so it moves all 24 eight-tier countries at
   once while only EN and SC have been gated.
2. Both ratio tables → `[1000000, 344411, 104532, 62538, 22115, 8127, 2073, 650]` and both tier-1
   bases → `8516630000` (£85,166,300 = 0.2573x the new T1). The ladder `5dc3f51b` authored IS the
   OLD turnover ladder normalised to tier 1, so re-authoring turnover silently invalidates it —
   they move together or not at all. The per-league columns and `league_tier_defaults` are NOT
   re-nulled: `Version20261116181000` already cleared them and NULL = inherit picks the new ladder
   up by itself.
3. `npc_facility_level_ranges` band 3 (tiers 7 AND 8, no country dimension) → four stands at
   `0-1`, nine slugs excluded at `0-0`.

**Nothing is authored for wages, deliberately.** `EconomyScaleResolver::wageScaleMicroPence()`
derives `S_tier` from the turnover row, and the youth scholarship anchor follows the average wage,
so (1) moves both with no second action. Writing either by hand gives the chain two sources of
truth. Staff and scout pay do NOT move — `TierBandScaleResolver` reads the ability bands.

**One `addSql` per key, and `create_missing = true` on every `jsonb_set`.** The facility column is
written one SLUG at a time, never as a whole-row or whole-band overwrite, for the reason
`Version20261116181000` gives about `league_tier_defaults`. The `create_missing` flag is the subtle
half: six of the nine exclusions (`scout_fleet`, `imaging_suite`, `player_wellbeing`,
`nutrition_centre`, `trial_pitch`, `car_park`) had NO band-3 entry, and `buildFacilities()` falls
back to `FACILITY_LEVELS` band 3's `other` range of `0-1` for a slug with no override — so omitting
them would have left all six still built.

**What band 3 held before, which settles an open question.** Overrides WERE authored per slug —
stands `north 1-1, south 1-1, east 1-2, west 1-2` (mean total **5.0**), `scouting_center 1-1`,
`club_shop 1-1`, `training_pitch 1-1`, `physio_clinic 1-1`, `youth_academy 0-1`,
`technical_zone 0-1`, `tactical_room 0-1`, `kinetic_lab 0-1`, the rest `0-0`. The 5.0 matches the
Nov-15 world export's measured 4.89–5.05 against a code default that would give 2.0, so the
discrepancy `candidate-c-rollout.md` §0 flagged was **authored overrides, not the NPC upgrade
drift** `game-mechanics.md` §13.3 describes. The stand half of the change is therefore real, not a
no-op. Afterwards the active templates leave a bottom-division club exactly EIGHT facilities: four
small stands, a training pitch, a physio clinic, a club shop and a tactical room. (`kinetic_lab`,
`zenith_sanctuary` and `neuro_shed` are keys with no active `facility_template` row — inert, and
left exactly as found.)

**Paired with `WORLD_PACK_VERSION` 9 → 10.** Packs bake facilities, wages, `tvDeal` and both payout
figures. ⚠️ The bump is NOT enough on its own here: existing NPC clubs keep the facilities they
were generated with, so the band-3 change reaches nothing until the bottom two tiers are
REGENERATED.

## `Version20261119090000` — author `league.slots` wherever it is still 0

**What it does.** Computes each league's currently derived size in SQL and writes it to
`league.slots` **only where `slots = 0`** (0 means "derive"), stashing every row's prior value in
`league_slots_pre_authoring_backup` so `down()` is exact. Designed to change nothing on the day it
lands: it authors the figure each box was already deriving.

**Why.** `LeagueSizeResolver::sizeOf()` reads 0 as "derive" and returns the live NPC club count
(+1 at the country's bottom tier), so the division size, every club's matchday, the app gate's
`homeGames` and the per-club divisor in `app:economy:wage-table`'s check 8 all followed however
many club rows happened to exist. On 2026-11-18 a spoof-club purge took England from 20 club rows
per tier to 16 and thereby re-priced every division in the world — matchday fell ~20%, six tiers
dropped below the app's coverage floor — with no economy change authored anywhere.

⚠️ **ONLY WHERE `slots = 0`, AND THAT IS NOT A DETAIL.** An already-authored value is somebody's
decision, and overwriting it with a live club count would be the defect applied deliberately. It
is also not hypothetical: the development clone holds all 120 league rows authored, and **SC tier
8 sits at an authored 3 while deriving 4** (3 NPC rows + the player's slot). "Author every league
to its derived size" would therefore have moved a real division.

⚠️ **COMPUTED, NOT HARDCODED**, because the derived size differs per box — the clone has EN at 18
club rows per tier, the deployed box 16. A hardcoded table would author the wrong count on one of
them and silently re-price it.

⚠️ **IT DOES NOT PICK A DIVISION SIZE.** 16-vs-20 is an open owner decision; authoring the current
count is what avoids pre-empting it, and the admin can edit the value afterwards
(`LeagueAdminController::quickEdit()`, blank = 0 = derive).

**Three details copied from the resolver rather than reinvented**, since a divisor that disagrees
with the one it replaces is worse than no change: the NPC join also matches on
`n.country = lg.country`, because `NpcClubRepository::countsByLeagueForCountry()` filters on the
club's own country; the bottom tier is `MAX(tier)` per country, because
`findLowestTierForCountry()` is `ORDER BY tier DESC LIMIT 1` (the deepest EXISTING row, not
`country_profile.league_tier_count`); and the `+1` applies only there. Raw SQL also re-applies the
two bounds `League::setSlots()` would have given it — `LEAST(..., 40)` and skipping `derived = 0`.

**Tested by running the migration's own statement.** `wunderkind_test` is not migration-tracked, so
the migration cannot reach it; `LeagueSlotsAuthoringTest` requires the file by path (migrations are
deliberately not autoloaded) and executes `Version20261119090000::AUTHOR_SQL`, so the test cannot
drift from the migration. **No `WORLD_PACK_VERSION` bump** — no key moves and no resolved size
changes.

## `Version20261119091000` — author `game_config.bankruptcy_deduction_weeks`

**What it does.** Writes the agreed five-row weeks-of-wages deficit ladder — 1–2/2–4/4–8/8–16/16+
weeks at 3/6/10/20/50 points — stashing the column first in
`bankruptcy_weeks_pre_authoring_backup`. `down()` restores a stashed NULL as NULL, with no
`COALESCE`, because NULL is this column's own meaningful state ("the app uses its own ladder").

⚠️ **AUTHORING ONLY — the mechanism already existed.** Column, publication through
`EconomyConfigPublisher::clientBlock()`, the admin JSON textarea, `normaliseDeductionWeeks()` and
`app:economy:wage-table`'s check 5 all shipped 2026-11-13 in `Version20261113120000` with the
column left `null`. The open item was only the figure, specified in
`docs/economy-threshold-review.md` §1. Nothing was designed here.

**Why.** `bankruptcyDeductionTiers`, the pence ladder this supersedes for the turnover model, is
flat absolute pence with no tier dimension: its harshest band starts at £40,000.01 against a
tier-1 weekly wage bill of £3,238,043, so the whole ladder is traversed in 0.01 weeks. The bill is
`turnover × 0.45 ÷ 46`, so it depends on nothing but the turnover row and reads identically on
dev. Weeks of wages cannot decouple at all — a country factor, a tier factor, inflation and a
wage-model flip each move numerator and denominator together, which is why
`npcSolvencyThresholds` already works this way. The rows are contiguous (`weeksMin` equals the
previous `weeksMax`), so they store unaltered and the first-match-vs-last-match question stops
existing.

⚠️ **NOT INERT, AND THAT WAS CHECKED RATHER THAN ASSUMED.** `resolveWeeksLadder` prefers the
published ladder over the app's own constant, so authoring flips every client onto this.
`wunderkind-app`'s `BANKRUPTCY_WEEKS_LADDER` (`src/economy/insolvency.ts`) was read read-only on
2026-11-19 and is **byte-identical** to these rows, so no client behaviour moves. The mechanic is
additionally still gated by `bankruptcyDeductionsEnabled`.

⚠️ **`bankruptcyDeductionTiers` IS DELIBERATELY UNTOUCHED** — same name, type, values, still
published, still the ladder for `legacy`. So the gate's two "rows 4 and 5 unreachable behind row
3's null `deficitMax`" problems and the five per-tier "no range left" problems **survive this
migration by design**: they measure the pence ladder, and amending its published bounds is a value
change nobody has authorised. Do not read their survival as this migration having failed.

## `Version20261119190000` — the catchment fan-base share ladder (2026-11-19)

Adds `starter_config.fan_base_share_percent` (JSON, NOT NULL, PHP-default-backed), seeded with
the authored ladder: `{"1":{"min":60,"max":70}, … ,"8":{"min":15,"max":20}}`. Published as
`fanBaseSharePercent` on `GET /api/starter-config`; admin-editable per tier at
`/admin/starter-config`, clamped 0–100 on save.

Same NOT-NULL-with-a-dropped-DB-default shape as `Version20261102121403` and
`Version20260509133608` — `starter_config` holds exactly one row, so `ADD … JSON NOT NULL` with
no default fails on an existing database, and leaving the default behind is mapping drift the
next `migrations:diff` emits.

🔴 **A NEW COLUMN BESIDE `fan_base_ranges`, NEVER A REINTERPRETATION OF IT, and the reason is
worth keeping.** The two are 1,000× apart in unit: `fan_base_ranges` is an absolute headcount
and the deployed row reads `{"1":{"min":700000,"max":1500000}}` (the ENTITY default is far
smaller — 50,000–150,000 — so read that key from an export, never from the PHP default).
Re-using the old column for a percentage would have published a tier-1 fan base of 700,000% of
the catchment to every save on the next config sync. `fan_base_ranges` is untouched and remains
the app's fallback: `resolveStartingFanCount` (`wunderkind-app/src/economy/fanBase.ts`) takes the
absolute path whenever either this ladder or a club's catchment is missing. Pinned by
`StarterConfigFanBaseShareLadderTest` and `StarterConfigFanBaseShareTest`.

⚠️ **The ladder is deliberately NOT steep, and a steeper one was rejected.** NPC generation
already seats higher tiers in bigger places, so catchment supplies most of the pyramid gradient
and the share's job is the level, not the gradient. 60–70% → 15–20% measures a 68.4:1 England
spread against today's 73.3:1; the 5–10% floor considered first gives 164:1. ⚠️ The app's
`REFERENCE_FAN_BASE_SHARE_PERCENT` constant still holds that rejected 5–10% variant and is what
fills a tier this column omits — so publish all eight rungs.

⚠️ **NO `WORLD_PACK_VERSION` BUMP, AND NONE WAS NEEDED.** The catchment the ladder multiplies is
the pack's `populationSize`, which `WorldPackSnapshotBuilder::buildClubSnapshot()` has carried
since that builder was written (verified against cached payloads at versions 8 and 9). Both
generation paths delegate to that one builder and `InitializeController::tier()` serves the
cached payload whole, so nothing about the pack shape moved here.

⚠️ `wunderkind_test` is not migration-tracked, so this column had to be applied to it by hand
AND its value seeded into the pre-existing row — see `01_overview/output/environment.md`.

## `Version20261120100000` — `scout.ability` (2026-11-20)

Adds `scout.ability` (int, unsigned, NOT NULL) — the scout's single **1–100** figure — and
derives it for **every existing row** from that row's own `experience` + `judgements`. Owner
ruling: *"add 'ability' value, 1-100, have it driven off experience and judgements, to set a
single figure, then we'll base wage on that."* A26 phase 1; the wage half is A24 and is NOT wired
here. Canonical formula: `ScoutAbilityCalculator`.

🔴 **THE BACKFILL IS IN THE MIGRATION, NOT BEHIND IT — a deliberate departure from
`scout.weekly_salary`'s pattern.** That column (2026-11-05) landed `DEFAULT 0` with
`app:backfill-scout-wages` behind it, and its own migration called the default "the only safe
default for a migration but also a lie". For `ability` a zero is worse than a lie: it is **not a
legal value** (the clamp floor is 1) and **A24 will price a wage off this column**, so any window
in which every scout read 0 would price the whole world at the bottom of the band. Every row is
derived in one `UPDATE` inside `up()`, so the column is never in a state anything could misread.
`app:backfill-scout-abilities` still exists — for re-derivation after a formula or config change
(`--force`) and for rows a later raw-SQL import leaves at the floor — but on a database that has
run this migration it is a **no-op, and that is the expected result.**

⚠️ **`up()` RATHER THAN `postUp()`, departing from `api-backwards-compatibility.md`'s checklist
point 5.** Postgres has transactional DDL, so `ADD COLUMN ... DEFAULT 1` → `UPDATE` → `DROP
DEFAULT` commits as one unit and is strictly *more* atomic than a `postUp()` pass, which would
leave a brief window at the floor. `postUp()` is the right shape when the DDL must commit before
the DML can see it; it does not here.

🔴 **THE SQL IS A FROZEN TRANSCRIPTION OF THE FORMULA, NOT A SECOND HOME FOR IT.** A migration is
a historical record and must not be edited once applied, so if the weighting is ever retuned this
statement stays as it is and `--force` re-derives the world. **Do not "fix" this SQL to match a
later formula.**

⚠️ **PHP AND THIS SQL DID DISAGREE, AND THE FIX IS WORTH KNOWING.** `0.7` and `0.3` are not
representable in binary floating point, so PHP's `0.7 * e + 0.3 * m` lands just under on inputs
whose true result is exactly *k*.5, while Postgres `NUMERIC` is exact decimal — **6 of the 5,278
dev scouts rounded differently** (experience 33 against a judgement mean of 38 is exactly 34.5:
SQL gave 35, PHP gave 34). Six rows is small; the defect is the workstream's governing one in
miniature, so it was removed rather than tolerated. `ScoutAbilityCalculator` now multiplies
through by the weights' denominator — `round((7·e·n + 3·Σj) / 10n)` — and divides **once**, which
IEEE correctly-rounds, so a true *k*.5 arrives as exactly *k*.5 and both sides round half away
from zero. Pinned by
`ScoutAbilityCalculatorTest::testRoundsExactHalvesTheSameWayTheMigrationSqlDoes()`;
`app:backfill-scout-abilities --force --dry-run` reporting **0 of 5,278** is the end-to-end check.

The judgement mean is taken over `jsonb_each_text`, i.e. **over the keys the row actually has** —
persisted scouts carry 5, `GenerateMarketDataCommand` writes 7, and a fixed divisor would
under-rate every real scout by ~29%. A row with no numeric judgements falls back to experience
alone, never to a fabricated 0 for that half of the blend.

⚠️ **Paired with `WORLD_PACK_VERSION` 11 → 12** in the same commit, because `buildScoutSnapshot()`
publishes the figure into cached packs. **This does not break v11's "last bump money will ever
cause" rule** — `ability` is census, not money, the same class of content as the player and staff
abilities the pack already carries, and v9 and v10 were both bumps of exactly this kind.

⚠️ `wunderkind_test` is not migration-tracked, so this column had to be applied to it by hand —
added nullable, backfilled with the same arithmetic, then set `NOT NULL`. Without it 20 tests
error on `column "ability" does not exist`. See `01_overview/output/environment.md`.
