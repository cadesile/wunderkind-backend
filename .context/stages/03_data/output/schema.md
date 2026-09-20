# Schema — Tables & Repositories

Full field-level detail lives in `entities.md` — this file indexes tables
by name, notes repository query patterns, and records schema-level
constraints. Don't restate field lists here.

## Table index (by domain, matches `entities.md` grouping)

- **Auth/user:** `user`, `admin`, `guardian`, `email_verification`, `refresh_tokens`, `beta_request`, `deletion_request`, `user_device` (FCM push tokens), `notification_log` (push-pipeline audit trail)
- **Core game/club:** `club`, `club_facility`, `facility_template`, `league`, `league_sponsor_income`, `npc_club`, `transfer`, `sync_record`, `season_record`, `season_snapshot`, `season_ratings_snapshot`, `match_result`, `leaderboard_entry`, `tactical_advantage`
- **Player/squad:** `player` (embeds `PersonalityProfile` — no separate table), `player_archetype`, `player_career_stat`, `player_career_stat_snapshot`, `agent`, `scout`, `staff`, `player_siblings` (self-referential join table)
- **Sponsorship/finance:** `sponsor`, `investor`
- **Messaging/admin comms:** `admin_message`, `admin_message_audience_group` (join table), `message_delivery`, `audience_group`, `audience_group_member`, `inbox_message`, `game_event_template`, `social_account_connection`, `social_post_template`
- **Competition:** `competition_template`, `competition_template_reward_template` (join table), `active_competition`, `competition_entrant`, `competition_round`, `competition_fixture`, `competition_result`, `reward_template`, `entrant_reward_claim`
- **Config/singleton:** `game_config`, `pool_config`, `starter_config`
- **Misc/cache/analytics:** `country_world_pack_cache`, `live_telemetry_snapshot`
- **Messenger (infra, not domain):** `messenger_messages` — Symfony Messenger's Doctrine transport table, shared by the `async` and `failed` transports (differentiated by `queue_name`, not by table — see `config/packages/messenger.yaml`); first use of Messenger in this codebase, see `02_architecture/output/structure.md`

## Notable constraints (beyond ordinary FKs — see `entities.md` for those)

- **`active_competition`** — partial unique index
  `uq_active_competition_one_open_per_template` (`WHERE status =
  'registering'`), added in `Version20260916112510` — enforces at most
  one open competition per template at the database level, not just in
  application code.
- **`competition_entrant`** — unique index on
  (`active_competition_id`, `club_id`) — one entry per club per
  competition.
- **`competition_result`** — `OneToOne` + unique on `fixture_id` — one
  result per fixture.
- **`user_device`** — unique on `device_token` (not per-user — a token
  identifies an app installation; re-registering it under a different
  user reassigns rather than duplicates).

## Repository query patterns worth knowing

- **Recruitment-pool repositories** (`AgentRepository`, `ScoutRepository`,
  `StaffRepository`, `SponsorRepository`, `InvestorRepository`,
  `PlayerRepository`) share a consistent shape: `findInPool(...)`,
  `countInPool()`, `getPoolBreakdown()`, mirroring `PoolConfig` tuning
  values. `PlayerRepository` additionally has
  `findForWorldInit*` variants (by ability range/position/nationality
  with `excludeIds`) for world/NPC population generation, plus
  `findForScoutSearch(...)`, `getAdminSummary()`, `deleteByIds()`.
- **Stats/leaderboard repositories** (`MatchResultRepository`,
  `TransferRepository`, `SeasonRecordRepository`,
  `PlayerCareerStat(Snapshot)Repository`, `LeaderboardEntryRepository`)
  share a period-aggregation pattern parameterized by
  `StatsPeriod`/`PeriodResolver` (e.g. `getMostWinsByClub`,
  `getBiggestSpendersByClub`, `getMostTrophiesByClub`,
  `topGoalScorerInWindowByClub`). `LeaderboardEntryRepository` also has
  `findOrCreate`, `findWithRankForClub` (computes rank),
  `findTopByPeriod`.
- **Singleton-row repositories** (`GameConfigRepository`,
  `PoolConfigRepository`, `StarterConfigRepository`,
  `LiveTelemetrySnapshotRepository`) each expose a
  `getConfig()`/`getSnapshot()` fetch-or-create method, consistent with
  these being single-row config/telemetry tables.
- **`NpcClubRepository`** — `findForeignClubs()`,
  `getCountsByCountryAndTier()`, `getAllGroupedByLeague()`,
  `findDistinctRegions()`, `clubNameExists()` — supports procedural world
  generation and league population.
- **`CountryWorldPackCacheRepository`** — `findForCountryAndTier`,
  `findCachedTiers(..., payloadVersion)`, `deleteByCountry`,
  `findAllSummaries` — cache invalidation/versioning.
- **`Competition\ActiveCompetitionRepository`** — `findOpenForTemplate`
  (enforces the one-open-per-template invariant at the app layer too).
- **`Competition\CompetitionRoundRepository`** — `findDueRounds
  (\DateTimeImmutable $now)`, `hasUpcomingRound` — drives round-
  processing scheduling (see `04_interfaces/output/services.md`'s
  `CompetitionRoundProcessorService`).
- **`SyncRecordRepository`** — `deleteByClubFromWeek` (rollback
  support), `countRollbacksByClub`, `findValidPayloadsSince`.
- **`DeletionRequestRepository`** — `countRecentFailuresByIp`
  (rate-limiting), `deleteOlderThan` (retention cleanup) — GDPR-style
  deletion-request throttling/cleanup.
- **`UserDeviceRepository`** — `findByDeviceToken`, `findByUserIds`
  (resolves devices fresh at push-send time, not pre-resolved at
  dispatch), `findDistinctUsersWithDevice` (candidate pool for eager
  admin-broadcast push resolution — deliberately an `IN`-subquery on
  ids rather than `SELECT DISTINCT` on hydrated `User` rows, since
  PostgreSQL has no equality operator for `User`'s `json` columns and a
  naive `DISTINCT u` errors).
