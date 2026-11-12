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
