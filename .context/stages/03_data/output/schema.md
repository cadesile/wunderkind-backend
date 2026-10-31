# Schema — Tables & Repositories

Full field-level detail lives in `entities.md` — this file indexes tables
by name, notes repository query patterns, and records schema-level
constraints. Don't restate field lists here.

## Table index (by domain, matches `entities.md` grouping)

- **Auth/user:** `user`, `admin`, `guardian`, `email_verification`, `refresh_tokens`, `beta_request`, `deletion_request`, `user_device` (FCM push tokens), `notification_log` (push-pipeline audit trail), `user_ledger` (centralized cross-club dividend-draw audit trail)
- **Core game/club:** `club`, `club_facility`, `facility_template`, `league`, `league_sponsor_income`, `npc_club`, `transfer`, `sync_record`, `season_record`, `season_snapshot`, `season_ratings_snapshot`, `match_result`, `leaderboard_entry`, `leaderboard_award_definition`, `leaderboard_award_placement`, `leaderboard_period_archive`, `leaderboard_period_archive_entry`, `tactical_advantage`
- **Player/squad:** `player` (embeds `PersonalityProfile` — no separate table), `player_archetype`, `player_career_stat`, `player_career_stat_snapshot`, `staff_career_profile` (sync v2 — staff identity/avatar, no stats), `agent`, `scout`, `staff`, `player_siblings` (self-referential join table)
- **Sponsorship/finance:** `sponsor`, `investor`
- **Messaging/admin comms:** `admin_message`, `admin_message_audience_group` (join table), `message_delivery`, `audience_group`, `audience_group_member`, `inbox_message`, `game_event_template`, `social_account_connection`, `social_post_template`
- **Competition:** `competition_template`, `competition_template_reward_template` (join table), `active_competition`, `competition_entrant`, `competition_entrant_squad`, `competition_entrant_submission`, `competition_round`, `competition_fixture`, `competition_result`, `competition_result_audit`, `competition_response_cache`, `reward_template`, `entrant_reward_claim`
- **Friendlies:** `friendly_challenge`, `friendly_challenge_squad`, `friendly_challenge_submission`, `friendly_challenge_audit`
- **Config/singleton:** `game_config`, `pool_config`, `starter_config`
- **Misc/cache/analytics:** `country_world_pack_cache`, `live_telemetry_snapshot`
- **Messenger (infra, not domain):** `messenger_messages` — Symfony Messenger's Doctrine transport table, shared by the `async` and `failed` transports (differentiated by `queue_name`, not by table — see `config/packages/messenger.yaml`); first use of Messenger in this codebase, see `02_architecture/output/structure.md`

## The split rows (`*_squad`, `*_submission`, `*_audit`, `*_response_cache`)

Seven of the tables above exist because a single column cannot express two
different read profiles. ORM 3 removed PARTIAL hydration, so **every mapped
column is decoded on every read of its row** — and the competition and friendly
domains both stored client-submitted squads and admin-only forensics on rows
that public, unauthenticated endpoints read in bulk.

`GET /api/competitions/{id}` decoded up to 64 whole squads to render a bracket
and exhausted the request memory limit; the admin entrant index had already
timed out in production for the same reason; and
`GET /api/friendlies/challenges` decoded two squads per listed challenge.

| Table | Holds | Read by |
|---|---|---|
| `competition_entrant_squad` | XI, staff, engine-only club keys (`cohesion`, `managerMotivation`, `lowUrgency`) | round resolution; one match detail; admin viewer |
| `friendly_challenge_squad` | the same, per side | friendly resolution; one match detail; admin viewer |
| `competition_entrant_submission` | `envelope_json` — the posted body verbatim, `submitted_at` | nothing on a schedule; admin entrant detail, forensics |
| `friendly_challenge_submission` | the same, per side | nothing on a schedule; admin |
| `competition_result_audit` | `event_log_json`, `computation_audit_json` | the admin competition detail page, and nothing else |
| `friendly_challenge_audit` | `event_log_json` | the admin challenge detail page, and nothing else |
| `competition_response_cache` | the prebuilt viewer-independent bracket response | every read of `GET /api/competitions/{id}` |

Two rules keep this from regressing:

1. **No entity declares an inverse association to its split row.** A `OneToOne`
   inverse side cannot be proxied (the owning FK is on the other table) and is
   therefore *always* fetched eagerly, regardless of `fetch: LAZY` — which would
   silently restore the entire cost. Fetch through the repository.
2. **Fetch in bulk wherever more than one row is on screen.** The repositories
   expose batch methods (`findByEntrants()`, `findByResults()`, `findBothSides()`)
   precisely so a split does not trade a hydration cost for an N+1 of the same
   size. The admin competition detail page renders a whole bracket's results at
   once and uses them.

`*_squad` rows are pruned when a competition completes (no further fixture can
resolve, so they are dead). `*_audit` rows are **not** — an audit exists to
answer "how was this result derived" about a match already played, which is
exactly when someone asks. Ageing them out is a retention policy, undecided.

`*_submission` rows are **not pruned either**, and that is the whole reason they
are a third table rather than a column on `*_squad`, which is pruned and which
round resolution decodes twice per fixture. They hold the registration envelope
exactly as the client posted it — every key, including the ones no backend code
reads yet.

They exist because `clubDisplayBlock()` is a picked ~22-key set, which is what
keeps a bracket render bounded, but which meant everything outside it was
decoded and discarded. A measured 2026 submission is **1,408,914 bytes**, of
which the XI and staff are ~7KB: nearly all of it is `club` history the client
spreads in (`cupTrophies`, `facilityUpgrades`, financials, season tickets,
stadium). Keeping it costs the hot paths nothing.

Three things to know before touching them:

- **Contents are untrusted.** Structural validation is `SnapshotValidator`'s
  pass and nothing more. Never read game truth from here; the normalised
  projections are the display block and the squad row.
- **Never load them in bulk on a page that renders many entrants.** They are
  the largest rows in the domain.
  `CompetitionEntrantSubmissionRepository::findByEntrants()` exists for the
  cases that legitimately need several, before anything needed it.
- **A write goes through `splitSnapshot()`'s `envelope` key**, which both
  domains share, so the competition and friendly sides cannot drift about what
  "the submission" means. `facilities` reaches it only because the DTO declares
  it — `MapRequestPayload` drops undeclared properties.

## Notable constraints (beyond ordinary FKs — see `entities.md` for those)

> **Partial unique indexes are declared in the ORM mapping**, as
> `#[ORM\UniqueConstraint(..., options: ['where' => '<predicate>'])]` on the
> owning entity — DBAL 4 round-trips the `where` option via
> `pg_get_expr(indpred, indrelid)`. This matters in both directions:
> `doctrine:schema:create` builds them (so a fresh `wunderkind_test` is
> correct), and `doctrine:migrations:diff` no longer proposes dropping them as
> unknown objects. Add new ones the same way — not in raw migration SQL alone —
> and copy the predicate exactly as PostgreSQL reports it, or the diff churns.

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
- **`competition_entrant_squad`** — unique on `entrant_id`. Required, not
  decorative: `CompetitionRegistrationService::writeSquad()` upserts with
  `ON CONFLICT (entrant_id)`, which needs a unique constraint to match against.
- **`friendly_challenge_squad`** — unique on (`challenge_id`, `side`), one row
  per participant. Keyed challenger/acceptor rather than home/away, because
  `FriendlyChallenge::$venue` decides which participant is home and so flips
  per challenge.
- **`competition_result_audit`** / **`friendly_challenge_audit`** — unique on
  `result_id` / `challenge_id`.
- **`competition_response_cache`** — unique on `active_competition_id`, one
  prebuilt response document per competition.
- **`user_device`** — unique on `device_token` (not per-user — a token
  identifies an app installation; re-registering it under a different
  user reassigns rather than duplicates).
- **`player`** — `idx_player_youth_draw` on (`date_of_birth`, `potential`,
  `nationality`), serving `PlayerRepository::findYouthStratum()` behind
  `GET /api/players/youth`. Leads on `date_of_birth` because the 12–14 youth
  window is a small slice of a pool spanning every age, so it is by far the
  most selective of the three. Non-unique; declared in the ORM mapping so it
  round-trips through `schema:create`/`migrations:diff`.
- **`leaderboard_award_definition`** — unique index
  `uq_leaderboard_award_definition_category_cadence` on (`category`, `cadence`).
  **Load-bearing rather than tidy:** it is what makes (`category`, `period_key`) a sound
  non-nullable claim key on `leaderboard_period_archive`, since two definitions on one
  category but different cadences produce different period keys and so cannot collide.
  `LeaderboardAwardDefinitionCrudController` guards it with a flash *before* flushing — a
  `UniqueConstraintViolationException` out of `flush()` closes the EntityManager.
- **`leaderboard_award_placement`** — unique on (`definition_id`, `place`). The FK to the
  definition is `CASCADE` (config, meaningless alone); the FK to `reward_template` is
  `SET NULL` (deleting a template must not silently delete a prize place).
- **`leaderboard_period_archive`** — unique index
  `uq_leaderboard_period_archive_category_period` on (`category`, `period_key`). **This is
  the close job's claim.** `LeaderboardPeriodCloseService::claim()` inserts the header
  `pending` with a raw, un-targeted `ON CONFLICT DO NOTHING` (the
  `RewardApplierService::recordClaim()` idiom — raw specifically because a unique violation
  out of `flush()` closes the shared EntityManager and would strand every remaining
  definition in the same cron tick), and whoever wins the insert owns the close. **Both
  columns are NOT NULL, so this is a PLAIN unique, not a partial one** — which is the entire
  reason `category` and `cadence` are denormalised onto the header instead of keying on the
  nullable `definition_id`. No `where` option, so neither the `pg_get_expr` churn trap above
  nor the `entrant_reward_claim` two-partial-index dance applies here.
  `definition_id` is `ON DELETE SET NULL`, not `CASCADE` — the archive must outlive an admin
  deleting the definition. `claimed_at` is a **lease** (300s, `CLAIM_LEASE_SECONDS`, the same
  constant name and value as `CompetitionRound`), reclaimed by a conditional `UPDATE … WHERE
  status = 'pending' AND claimed_at < :reclaimBefore`. A row stuck `pending` past its lease
  means a close died part-way: its archive is incomplete and its live `leaderboard_entry`
  rows are deliberately **unpruned**.
- **`leaderboard_period_archive_entry`** — unique on (`archive_id`, `club_id`) and on
  (`archive_id`, `rank_position`), plus the **one partial unique in this feature**:
  `uq_lpae_archive_place` on (`archive_id`, `place`) `WHERE (place IS NOT NULL)`, because
  `place` is nullable and PostgreSQL treats NULLs as distinct. Declared on the entity with
  the predicate exactly as `pg_get_expr` reports it, per the note above — verified: a fresh
  `wunderkind_test` builds it via `schema:create` and `migrations:diff` reports no changes.
  `club_id` and `user_id` are denormalised `varchar(36)` with **no FK** at all, so the row
  survives club and account deletion; account erasure nulls `user_id` and keeps the row (see
  `AccountDeletionService::forgetUserInArchives()`). `inbox_message_id` is likewise a plain
  string, because inbox messages cascade-delete with the club and an award must not vanish
  with its delivery receipt.
- **`user_ledger`** — unique index `uq_user_ledger_source_entry` on
  (`source_sync_record_id`, `source_ledger_index`) — the idempotency guard
  for `app:backfill-user-ledger`/`UserLedgerService` (see `entities.md`).
  `source_sync_record_id` is `ON DELETE SET NULL`, not `CASCADE` — a
  rollback purging the source `sync_record` must not delete the audit row.

## `sync_record.payload` field conventions (financial correctness — read before touching any of this data)

`sync_record.payload` archives the raw client `SyncRequest` verbatim — the
loose array fields (`ledger`, `promises`, `transfers`, `excursions`,
`relationships`, `fixtures`) carry no server-side validation or sub-shape
typing (see `SyncRequest.php`'s docblocks). Most monetary fields in this
payload are genuine pence (`£ = value / 100`) — **`ledger[].amount` is the
one exception, and it is a real, confirmed bug, not a false lead:**

- **`ledger[].amount` is 100x true pence**, not real pence. Real pence =
  `amount / 100`; real pounds = `amount / 10_000`.
- Confirmed two ways: (1) cross-referencing `excursions[].costPence` (an
  exact, independently-typed field) against its matching `fan_initiative`
  ledger line for the same booking — the ledger amount is always exactly
  100x `costPence`; (2) comparing a real sync payload's raw `ledger[].amount`
  values against the same club's own in-game live ledger screen — every
  category checked (matchday income, sponsor income, upkeep, wages, investor
  equity, transfer fee) showed the identical 100x ratio.
- This originates client-side (`wunderkind-app`'s on-device game engine
  writes the inflated value before syncing) — out of this repo's scope to
  fix at the source (backend-only scope is a standing project convention).
  The backend's job is to **correctly interpret** the value it receives, not
  trust it as labeled.
- **Every consumer of `ledger[].amount` must divide by 100 before treating
  it as pence.** Two canonical, tested implementations exist — reuse one,
  don't add a third ad hoc conversion:
  - PHP: `LiveTelemetryService::ledgerAmountToPence()` (private static
    helper — used by `aggregate()`'s `capitalDeployedPence` and
    `buildLedgerEvents()`'s `amountPence`/`amountExact`).
  - Twig: the `ledgerCurrency()` macro in
    `templates/admin/_macros.html.twig` (used by
    `templates/admin/club_profile.html.twig`'s Ledger table). Never pass a
    raw `ledger[].amount` straight to the plain `currency()` macro — that
    macro assumes real pence.
- Every *other* monetary payload field is genuine, uncorrected pence and
  needs no adjustment: `earningsDelta`, `balance`, `totalCareerEarnings`,
  `signings[].fee`, `transfers[].grossFee/agentCommission/netProceeds`,
  `promises[].offer.amountPence`, `excursions[].costPence`. That's also why
  `buildDilutionEvents()`/`buildCovenantEvents()` in `LiveTelemetryService`
  deliberately source their exact figures from `promises[].offer.amountPence`
  instead of `ledger[].amount` — see that class's docblock.
- If you add a new consumer of `ledger[].amount` (a new admin view, report,
  or telemetry aggregation), apply the same /100 correction — or, better,
  route through one of the two canonical implementations above.
- **`dividend_draw` is the one `ledger[].category` that is REAL pence, not
  100x-inflated.** Detected by `UserLedgerService::recordDividendDraws()`
  (called from `SyncService::process()`) to centralize a user's cross-club
  earnings onto `UserLedger` — see `entities.md`. It's a brand-new category
  with no pre-existing client code to inherit the device-side bug from, so
  it was deliberately specified as correctly-scaled from day one (confirmed
  explicitly with the human, not inferred) — same reasoning as
  `promises[].offer.amountPence`. **Do not** route it through
  `LiveTelemetryService::ledgerAmountToPence()`; see
  `docs/api/user-ledger.md` for the frontend-facing contract.

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
- **`Competition\CompetitionRoundRepository`** — `findDueForDraw`/
  `findDueForResults` (\DateTimeImmutable $now)`, `findByCompetitionAndIndex`,
  `hasUpcomingRound` — drives the two decoupled round-processing cron
  passes (see `04_interfaces/output/services.md`'s `CompetitionDrawService`
  / `CompetitionResultsService`).
- **`SyncRecordRepository`** — `deleteByClubFromWeek` (rollback
  support), `countRollbacksByClub`, `findValidPayloadsSince`,
  `findTopAttendanceSince`. The first of those **does not return `payload`
  whole**: it takes a list of top-level keys and narrows each payload to them in
  SQL (`jsonb_object_agg` over `jsonb_each`, guarded by `jsonb_typeof` because
  `SyncRecord::$payload` defaults to `[]`, which stores as a JSON *array* and
  makes `jsonb_each` raise). Its one caller holds the whole 24h window in memory
  at once and a decoded payload costs ~4.8x its stored JSON, so `relationships`
  — the largest key, ~60% of payload bytes, up to 162 kB a row, read by nothing
  in telemetry — must never be fetched. The key list lives on
  `LiveTelemetryService::PAYLOAD_KEYS`; see
  `04_interfaces/output/services.md`'s `LiveTelemetryService` entry.
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
