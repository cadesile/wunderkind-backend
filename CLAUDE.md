# CLAUDE.md


<!-- context-generator: start -->
## Project Context (ICM)

`.context/` is the source of truth for this codebase's structure, stack,
data model, and interfaces — read `.context/CONTEXT.md` first; it routes
you to the stage relevant to your task.

**Before exploring this codebase for ANY task** — including spawning a
sub-agent, grepping, or reading manifests/config/source files directly —
check `.context/CONTEXT.md` and the relevant stage's `output/` for
the answer first. This applies even when the task isn't phrased as an
architecture question (e.g. "check dependencies for security updates"
still needs facts a stage may already document). Only explore raw source
for what's missing or possibly stale.

Read and follow `.agents/skills/icm-codebase-context/SKILL.md` for how to use and maintain `.context/`.

**If this is the first time you (any agent) are reading this file in a
session:** tell the human `.context/` and this skill are available,
before doing anything else. If `.context/stages/*/output/` is empty
or missing, explicitly ask whether to run stage `01_overview` now, in this
session, to set it up (see the skill's CONTEXT.md) — don't just silently
skip it and don't silently run it unasked either.

You (the human) can also say "warm", "warm the context", or
`/icm-context warm` at any time to trigger this explicitly, without
waiting to be asked — see `SKILL.md`'s Triggers table.

**Finishing any task:** if your change affects a stage's documented content
(schema/migration → `03_data`, new routes/services → `04_interfaces`,
new module/directory → `02_architecture`, etc.), update that stage's
`output/` as part of finishing the task — not as a separate step.

This file is a disposable, regenerable pointer (see .gitignore) — `.context/`
is the actual source of truth. If you ever find yourself in this repo
without a file like this one, but `.context/` exists, treat it as
authoritative anyway and recreate this pointer for whichever agent you are.
<!-- context-generator: end -->

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Dev Environment

All PHP commands must run inside the Lando container:

**PHP runs on the 8.5 branch, but local and deployed are NOT on the same patch.**
Deployed dev/prod pin `php:8.5.12-fpm-alpine` exactly (`Dockerfile`); local Lando runs
PHP 8.5.7, because Lando can only boot `devwithlando/php` images and that family is
rebuilt a few times a year. So local dev lags upstream security patches by design —
read `.lando.yml`'s own comments before trying to "fix" the mismatch, and treat the
local environment as unpatched. A PHP security release means bumping the `Dockerfile`
now and the Lando image whenever Lando ships a newer build.

```bash
lando start                          # spin up PHP 8.5 + PostgreSQL 16
lando php bin/console <command>      # Symfony console
lando composer <command>             # Composer
lando psql                           # PostgreSQL shell (db: wunderkind, user/pass: wunderkind)
lando logs -s appserver              # tail app logs
```

**Database**: PostgreSQL 16. Connection string:
`postgresql://wunderkind:wunderkind@postgres:5432/wunderkind?serverVersion=16&charset=utf8`

**Developing against real data volume**: `bash scripts/pull-prod-db.sh` pulls a fresh
`pg_dump` from the production Hetzner box over SSH and restores it into the local Lando
database, replacing whatever's there. **Raw, not scrubbed** — includes real user PII
(emails, owner identity, GDPR `DeletionRequest` rows) by deliberate choice; see the
script's own header banner before reusing it beyond solo local dev. Requires SSH access to
prod and Lando running.

## Common Commands

```bash
# Tests
lando php vendor/bin/phpunit --no-coverage                              # full suite
lando php vendor/bin/phpunit tests/Service/SyncServiceTest.php --no-coverage  # single file
lando php vendor/bin/phpunit --filter testMethodName --no-coverage      # single test

# Cache
lando php bin/console cache:clear

# Database — fresh setup on a new clone
lando php bin/console doctrine:database:drop --force
lando php bin/console doctrine:database:create
lando php bin/console doctrine:schema:create
lando php bin/console doctrine:migrations:sync-metadata-storage
lando php bin/console doctrine:migrations:version --add --all --no-interaction

# Generate JWT keys (once, or after key rotation)
lando php bin/console lexik:jwt:generate-keypair --no-pass --overwrite

# After adding/changing entities, generate a new migration
lando php bin/console doctrine:migrations:diff

# Seed data (run in this order on a fresh DB) — mirrors the deploy workflow's seeder
# sequence (.github/workflows/deploy-dev.yml) except for app:seed-excursions, which is
# fresh-DB-only: it upserts by slug from a hardcoded array and so overwrote prices
# edited through the admin on every deploy, hence its removal from both workflows —
# the excursion catalogue is admin-owned, don't re-add it there. Missing any of these
# behaves like the real feature is broken (e.g. skipping app:seed-match-narrative makes
# every Competition result's narrativePayload come back empty, not an obvious
# "seed this" error)
lando php bin/console app:seed-game-events        # narrative event templates
lando php bin/console app:seed-match-narrative    # MATCH_NARRATIVE chain-graph content (Competition result narrativePayload)
lando php bin/console app:seed-player-events      # player event templates
lando php bin/console app:seed:morale-events      # morale event templates
lando php bin/console app:seed-archetypes         # 20 curated archetypes (10 positive, 10 negative)
lando php bin/console app:seed-social-post-templates  # social media post templates
lando php bin/console app:seed-excursions         # team-bonding excursion catalogue
lando php bin/console app:generate-market-data    # agents, scouts, investors, sponsors
lando php bin/console app:market:generate         # generate market pool entities
lando php bin/console app:pool:warm               # pre-warm the market entity pool
lando php bin/console app:worldpack:warm          # warm country/nationality data cache

# Starter pack supply
lando php bin/console app:starter-pack:check EN   # can a new EN club be given a starter pack right now?
lando php bin/console app:starter-pool:audit --dry-run   # sweep every enabled country; show what would be queued
lando php bin/console app:starter-pool:audit             # ...and queue it onto the `pool` transport
lando php bin/console messenger:consume pool -vv         # drain it locally (no crontab under Lando)

# Periodic leaderboard awards (no crontab under Lando — the cron runs this every 10 min at :04)
lando php bin/console app:leaderboards:close-periods --force --dry-run -v   # what would close
lando php bin/console app:leaderboards:close-periods --force -v             # close the CURRENT open period
lando php bin/console app:leaderboards:close-periods --at=<ISO8601>         # pin "now"

# Admin
lando php bin/console app:admin:create            # create a backend admin user

# Debug
lando php bin/console debug:router
lando php bin/console debug:firewall
```

## Testing

- Unit tests (`PHPUnit\Framework\TestCase`) run in-memory and need no DB.
- **Functional / `WebTestCase` / `KernelTestCase` tests use a SEPARATE database, `wunderkind_test`** (env `test`, which skips `.env.local` — so it does **not** share the dev DB). This DB is created via `doctrine:schema:create`, not migrations, so its migration metadata starts empty and it **drifts** whenever a migration adds a column that `schema:create` didn't originally build. Symptom: `column "x" does not exist` errors in functional tests only. Reconcile it:
  ```bash
  # See the drift, then apply missing columns to the test DB:
  lando php bin/console doctrine:schema:update --dump-sql --env=test
  # After the schema matches entities, mark all migrations applied so metadata is truthful:
  lando php bin/console doctrine:migrations:sync-metadata-storage --env=test
  lando php bin/console doctrine:migrations:version --add --all --no-interaction --env=test
  # Verify:
  lando php bin/console doctrine:migrations:up-to-date --env=test
  lando psql -d wunderkind_test -c "<sql>"   # inspect the test DB directly
  ```
  **Partial unique indexes are now mapped — this used to be a trap and no longer is.**
  Postgres partial indexes (`CREATE UNIQUE INDEX ... WHERE (...)`:
  `uq_active_competition_one_open_per_template`,
  `uq_entrant_reward_claim_with_template`/`_without_template`,
  `uq_world_pack_generation_run_one_active_per_country`) are declared on their
  entities as `#[ORM\UniqueConstraint(..., options: ['where' => '...'])]`, which
  DBAL 4 round-trips. `schema:create` therefore builds them, so a fresh
  `wunderkind_test` gets them automatically and `migrations:diff` no longer
  proposes dropping them. **If you add another partial index, declare it that way
  rather than only in raw migration SQL** — the predicate string must match what
  `pg_get_expr(indpred, indrelid)` reports, or the diff will churn. The old
  symptom, for reference: code doing `INSERT ... ON CONFLICT DO NOTHING` against
  an absent index (`CompetitionProvisionInstancesCommand`, `RewardApplierService`)
  silently inserted duplicates, or raised `PDOException: ... no unique or
  exclusion constraint matching the ON CONFLICT specification`.

  **This still doesn't catch everything:**
  - **Seed commands aren't run against `wunderkind_test` by anything.** If a
    functional test's behavior depends on seeded rows (e.g.
    `MatchNarrativeGeneratorService` reading `MATCH_NARRATIVE`-category
    `GameEventTemplate` rows seeded by `app:seed-match-narrative`), a fresh
    `wunderkind_test` has none — symptom: an assertion like "must carry a
    non-empty narrative timeline" failing with an empty array, not an obvious
    missing-data error. Fix: run the relevant seed command with `--env=test`.
- **API functional-test login is single-use.** `$client->loginUser($user, 'api')` authenticates
  **exactly one request** against the stateless JWT firewall — the next request on the same
  client returns `401 JWT Token not found`, and calling `loginUser()` again does not recover it.
  The test env has no JWT keys (`JWT_SECRET_KEY` is empty in `.env`, and `.env.local` is skipped
  under `APP_ENV=test`), so minting a real bearer token is not an option either. To exercise more
  than one authenticated call, reboot per request: `self::ensureKernelShutdown()`,
  `static::createClient()`, re-fetch the `User` from the fresh EntityManager, then `loginUser()`.
  See `AdminMessageControllerTest::authenticatedRequest()`. This is why
  `InboxControllerTest` only ever asserts 401.
- **Never name a test helper `runCommand()`.** Symfony 8.1's `KernelTestCase` pulls in
  `ConsoleCommandAssertionsTrait`, which declares `public static function runCommand()`. A
  non-static method of that name in a subclass is a hard PHP fatal at class-load time
  (`Cannot make static method ... non static`) raised during PHPUnit's suite-*building*, so it
  takes down the **entire run** with exit 255 and zero tests executed — not just that file.
  `CompetitionProvisionInstancesCommandTest` uses `runProvisionCommand()` for this reason.
  Either use the inherited static helper, or pick a distinct name.
- **Don't assert EasyAdmin's `ea-clickable-row` class in a functional test.** It is no longer
  server-rendered: from EasyAdmin 5.x (confirmed 5.7.0) the row carries
  `data-default-action-url` + `role="link"` + `tabindex="0"`, and the bundle's own JS adds the
  class at runtime via `querySelectorAll('[data-default-action-url]')`. DomCrawler executes no
  JS, so that assertion can only ever fail — and its failure is **not** evidence row-click
  regressed. Assert `role`/`data-default-action-url` and then follow the URL, as
  `NotificationLogCrudControllerTest` does.
- **Admin functional-test login** — the `admin` firewall uses a Doctrine `EntityUserProvider` that re-fetches the user by email on every request, so an in-memory-only `Admin` is silently treated as unauthenticated. Persist a real `Admin` first, then `$client->loginUser($admin, 'admin')` (see `tests/Controller/Admin/SocialAuthControllerTest.php`).

## Git Workflow

`development` is the working branch for most tasks — commit directly there
(it's a direct child of `master`). Never commit directly to `master` or
`dev`; those are only ever reached by merging `development` in.

```bash
git checkout development
git pull origin development
# ...work, commit directly on development...
git push origin development
```

**Three branches: `development` → `dev` and `development` → `master`, propagated by merge,
never cherry-pick.** When a task is complete: merge `development` → `dev` and push (external
dev/staging testing), then merge `development` → `master` and push (production release) — two
**separate** merges, in that order. `dev` can sit ahead of and diverge from `development`/
`master` for a long time, so those merges aren't always clean. Full policy (propagation rules,
verify-before-push, conflict handling): `.context/stages/01_overview/output/deployment.md`.

## Deployment

Full runbook: `docs/deploy/hetzner.md`. Context digest (topology, secrets, gotchas,
per-branch deploy table, **and the git branching/merge policy**):
`.context/stages/01_overview/output/deployment.md`.

## Architecture

### The Hybrid Model
The server is **not** the game engine. Gameplay (Weekly Tick, training, aging, personality) runs entirely on-device. The API handles:
1. **Club sync** — receives aggregate deltas, updates `Club` totals
2. **Anti-cheat** — rejects `weekNumber` rollbacks; every sync recorded in `SyncRecord` even if invalid
3. **Leaderboards** — upserts `LeaderboardEntry` rows for `all-time` and current ISO week
4. **World data** — serves league structures, NPC clubs, and market entities to clients

### Pool Lifecycle (Player + Staff)
The backend is a **data pool**. `Player` and `Staff` entities have **no club FK** — they exist in the DB as available pool data only. When a Player or Staff is consumed (starter pack or market assign), it is **immediately deleted** from the DB and a full snapshot is returned to the frontend, which stores it locally. The backend never tracks which players/staff a club owns.

- `Player`/`Staff` in the DB = available pool. No `club_id`, no `assigned_at`.
- Snapshots are built via `WorldInitializationService::buildPlayerSnapshot()` / `buildStaffSnapshot()` **before** the entity is removed.
- `Sponsor` and `Investor` are **not** pool entities — they retain a `club_id` FK (financial contracts).
- `Scout` has no club FK and is not deleted on assign.

### Competition & Friendly Snapshots (split by read profile)

Client-submitted squads and admin-only forensics live in **separate rows** from the
display data public endpoints read. Full detail:
`.context/stages/03_data/output/schema.md`'s "The split rows".

| Row | Holds | Who may read it |
|---|---|---|
| `competition_entrant.clubDisplayJson` | bounded club display block | every bracket render |
| `competition_entrant_squad` | XI, staff, engine-only club keys | resolve, one match detail, admin |
| `friendly_challenge.{challenger,acceptor}ClubDisplayJson` | same, per side | the public challenge board |
| `friendly_challenge_squad` | XI, staff, engine keys, keyed by `FriendlySide` | resolve, one match detail, admin |
| `competition_entrant_submission` / `friendly_challenge_submission` | the posted envelope **verbatim** | nothing on a schedule — admin, forensics, future features |
| `competition_result_audit` / `friendly_challenge_audit` | event log, derivation audit | admin detail pages only |

This exists because ORM 3 removed `PARTIAL`, so **every mapped column is decoded on
every read of its row**. One `GET /api/competitions/{id}` used to decode up to 64 whole
squads and exhaust the request memory limit; the admin entrant index had already 504'd in
production for it; the public friendly board decoded two squads per listed challenge.

**Two rules, both easy to break by accident:**
- **Never add an inverse `OneToOne` from the parent to a split row.** A `OneToOne` inverse
  side cannot be proxied (the owning FK is on the other table), so Doctrine fetches it
  eagerly *regardless of* `fetch: LAZY` — silently restoring the whole cost. Go through the
  repository.
- **Batch-fetch wherever more than one row is on screen.** `findByEntrants()`,
  `findByResults()`, `findBothSides()` exist so a split doesn't trade a hydration cost for
  an N+1 of the same size. The admin bracket page renders every result at once.

`*_squad` rows are pruned when a competition completes (no fixture can resolve, so they're
dead) — **after** the flush that commits `COMPLETED`, since that prune is raw SQL and
committing it before a failed flush would leave a live bracket unresolvable. `*_audit` rows
are deliberately **not** pruned: an audit answers "how was this result derived" about a
match already played, which is exactly when someone asks.

**The bracket does not embed per-fixture match detail.** `rounds[].fixtures[]` carries
`score`, `matchId`, `home`, `away` and the winner — no `result` block. It used to carry both
lineups, the commentary timeline and frozen club copies per fixture, which
`app:competition:benchmark` measured at ~74% of a 397KB capacity-32 response (lineups 41.5%,
narrative 32.6%); removing it took that response to 59KB. `GET /api/matches/{id}` already
served exactly the same thing, and `matchId` is the handle. `CompetitionResult::toClientSummary()`
is gone with it — `MatchQueryService::serializeCupMatchDetail()` was always the real client
shape. Client contract: `docs/api/competition-bracket-no-embedded-result.md`. **Do not
re-embed match detail here**; the benchmark's payload breakdown reports 0 bytes for narrative
and lineups specifically so a regression shows up.

**Registration reserves a slot, then queues the payload.** `POST /register` does only the
contended part under `SELECT … FOR UPDATE` on `active_competition` — status check, capacity
count, entry fee, `INSERT competition_entrant` — then commits and dispatches
`StoreEntrantSubmissionMessage` onto the **`registration`** transport. The ~1.4MB squad and
submission write happens in the handler, in its own transaction, contending with nothing
(every statement is keyed to one `entrant_id`). That took the locked section from ~50ms to
~1ms, which is what multiplies registrations-per-second on one competition.

- **`entrant.squadState` (`EntrantSquadState`) is a separate axis from `status`**, not a new
  case on it. `status` is serialized straight to a client already live in production, so a
  `pending` case there would be a contract break. A reserved entrant genuinely *is*
  REGISTERED — it holds a slot and has paid.
- **Clients see it as `submissionStatus` / `viewer.snapshot.status`, with `stored` mapped to
  `confirmed`** by `EntrantSquadState::clientValue()` — the one boundary that owns the
  mapping, so renaming a case cannot silently change a published contract. Additive; client
  contract: `docs/api/competition-registration-status.md`. **It is exposed only on the
  register/resubmit response and in `viewer`, never in `serializeEntrantSummary()`** — and
  that restriction is load-bearing, not a product call. `viewer` is composed per request,
  while entrant summaries are served from `competition_response_cache`, whose fingerprint
  does not include `squad_state`; putting it there would serve a value that goes stale
  seconds after every registration. Adding it to a cached path means adding it to the
  fingerprint first.
- **The HTTP response is unchanged.** It reads only `clubDisplayJson`, which lands at
  reservation, and `seed` is always 0 at registration (`setSeed()` is called once, by
  `CompetitionLockService`, when the competition fills). So the synchronous 201 still carries
  everything it always did — no polling, no client change.
- **A competition does not LOCK while any entrant is unstored**, even at full capacity —
  `lockIfComplete()`, called when a submission stores, not from `register()` (where the new
  entrant is always PENDING, so a capacity check there could never see a complete field).
  This is a correctness requirement, not tidiness: locking sets the competition SCHEDULED and
  entrants ACTIVE, while `withdraw()` requires REGISTERING + REGISTERED — so locking over an
  unstored entrant wedges the competition permanently, with the draw deferring forever and the
  affected club unable to withdraw to release its slot. Deferring the lock means a FAILED entry
  always leaves its competition withdrawable, so the stuck state stops existing rather than
  being documented. Visible effect: a full competition can sit un-started for a moment.
- **`resubmit()` writes inline and sets `squadState = STORED`.** It is rare and deliberate, not
  a burst, so it does not queue — and it is how a FAILED entrant recovers. Omitting the state
  flip would write good rows and leave the entrant FAILED, blocking its competition over a flag
  describing data that is no longer missing.
- **`CompetitionDrawService` refuses to draw a competition with any non-`stored` entrant**,
  checked in `drawRoundIfClaimable()` **before** `claimDraw()`. After would park a 300s lease
  on a round that was never drawn, turning seconds of queue latency into a five-minute stall.
  A squad row is the engine's only input: drawing against a PENDING entrant would not error,
  it would resolve a real fixture against an empty XI. `FAILED` is deliberately not drawn
  around either — it needs an operator, and `app:competition:health` reports both.
- **`StoreEntrantSubmissionMessage` is the one message carrying a payload, not an id.** Every
  other one re-reads its subject from a table; here the envelope is precisely what has not
  been stored yet, so parking it first to keep the message small would write the same 1.4MB
  twice. Consequence: `messenger_messages` rows are large, and this transport is drained by an
  **always-on supervisord worker**, not a once-a-minute cron — a competition can fill and
  become drawable seconds after its last registration.
- **`registration` is its own transport, never shared with `competition`.** Round draws are
  latency-sensitive and must not queue behind a registration burst — and the dependency runs
  both ways (a draw waits on pending submissions), so sharing could deadlock a round behind
  work it is itself blocking.
- **In `test`, `registration` is `sync://`, not `in-memory://`** — alone among the transports.
  The others are fire-and-forget side effects a test asserts were dispatched; this one is the
  second half of registration, so in-memory would leave every functional test asserting
  against an entrant with no squad. The queueing itself is covered by `RegistrationQueueingTest`
  with a spy bus.
- **Registration sets `SET LOCAL lock_timeout`** (`LOCK_TIMEOUT_SECONDS`, 5s) and the
  controller turns SQLSTATE `55P03` into a **503 + `Retry-After`**, not a 500. With a small
  FPM pool, requests parked indefinitely on one row lock is the whole API refusing to serve,
  not a slow registration. `SET LOCAL` so it reverts on commit and cannot leak into the cron
  jobs and workers that legitimately run long statements.

**Capacity, FPM and the box.** The server is **2 vCPU / 3.8GB**, shared by `wunderkind-prod`,
`wunderkind-dev`, `postgres-prod`, `postgres-dev` and `caddy`. The image default was
`pm.max_children = 5` — five concurrent PHP requests for the *entire* API, not five
registrations — so a burst was an outage for every other endpoint. `docker/www.conf` sets
`pm = static`, `pm.max_children = 12` (~6x cores; PHP here is CPU-bound, so more runnable
workers buy context switches, not throughput) and nginx gets `client_body_buffer_size 2m` so a
1.4MB body stops spilling to a temp file on every registration. **Raise `max_children` when
the box grows cores, not when a queue looks slow** — and re-measure, since a 1.4MB
registration is only ~22ms of JSON work and ~20MB peak.

**Round processing is queued, not inline.** `competition-draw-rounds` and
`competition-resolve-rounds` only *dispatch* now — one `DrawCompetitionRoundMessage` /
`ResolveCompetitionRoundMessage` per due round onto the **`competition`** transport, drained
every minute by `docker/competition-consume.sh`. They used to draw and resolve every due
round inline, and a resolve runs the match engine plus narrative generation for up to 32
fixtures, so several tournaments hitting a round boundary in the same minute was unbounded
work in one 256M process.

**The claim is taken by the handler, not the dispatcher**, and that is load-bearing: claiming
at dispatch would start the lease ticking while the message waited in the queue, and a lost
message would leave a round claimed by nobody. It also means dispatch needs no deduplication
— a duplicate delivery just fails its claim and returns. Unlike `app:starter-pool:audit`,
dispatch is therefore **not** gated on an undrained queue; gating would stall other
competitions for no gain. `drawDueRounds()`/`publishDueResults()` remain as synchronous
drivers over the same per-round methods the handlers call, for tests and as a fallback.

**The pipeline reports on itself — `app:competition:health`.** Read-only DBAL
(`CompetitionHealthService`): `competition` queue depth and consumer lag, round claims held vs
past the lease, rounds due and unclaimed past a 180s grace, response-cache coverage. Runs every
5 minutes (`docker/competition-health.sh`), `--strict` exits 1 on any warning, `--json` for
scraping. It exists because every bound below came from `app:competition:benchmark` on one box
and nothing in production said whether those settings still held. Thresholds are first guesses
— the cron logs and pages nobody on purpose. Cache **builds** are logged
(`competition.cache.build`, with `elapsedMs` and `bytes`) and hits are not: hit rate is a ratio
over requests, so derive it from builds against access-log counts rather than logging the
hottest read path in the domain.

**Round claims are leases, and a status transition must commit with its own work.**
`claimDraw()`/`claimResults()` set `drawLockedAt`/`resolveLockedAt`, reclaimable after
`CompetitionRound::CLAIM_LEASE_SECONDS` (300s) and cleared on success. That window is set from
`app:competition:benchmark`, not guessed — the slowest measured full round is ~8.4s at
capacity 64, so it keeps ~36x headroom; re-measure if the match engine gets slower. They used to be
one-way flags, so any exception mid-round stranded it forever. Worse, `claimDraw()` also
committed `status = DRAWN` by raw SQL **before** `drawRound()` built any fixture — a failed
flush then left a round DRAWN with no fixtures and `matchesResolveAt` NULL, invisible to
`findDueForDraw()` *and* `findDueForResults()`. **Never commit a round's status ahead of the
work it describes**; set it on the entity so it lands in the same flush.

Other things worth knowing:
- **`clubDisplayJson` is a picked key set, not the normaliser's output.**
  `normalizeClubData()` ends in `array_merge($clubData, ...)` and forwards every
  unrecognised client key verbatim; `clubDisplayBlock()` picks known keys, which is what
  keeps the stored block bounded. It carries both `id` and `clubId` (same value) because
  `SnapshotValidator` checks `club.id` specifically. Re-pointing a snapshot at another club
  must move **both** via `repointClubData()` — identity resolves as `clubId ?? id ??
  fallback`, so setting one leaves the stale other winning.
- **`SnapshotValidator` caps a submitted snapshot at 4MB** — and that number came from
  measuring a real client payload, which is the only way it should ever be set. It was
  originally 256KB, justified as "~10x a real one" against an *estimated* 25KB snapshot. A
  real 2026 submission is **1,408,914 bytes**, so the cap 422'd ordinary registrations on a
  live tournament until 2026-10-28. (The staff ≤ 20 cap added in the same commit was reverted
  a day later for the identical failure mode — twice now, an invented bound has rejected real
  traffic. Measure, don't estimate.) The ceiling above it is nginx's `client_max_body_size
  5m`; the ~1MB gap is deliberate, so an oversized body is a legible 422 and not a bare 413.
  Almost all of that 1.4MB is `club` history the client spreads in — `cupTrophies`,
  `facilityUpgrades`, financials, season tickets — while the XI and staff together are ~7KB.
- **Everything submitted is now stored, verbatim, on a row nothing hot reads.** `club` is
  still picked down to `clubDisplayBlock()` for the bracket and the engine keys still go to
  the squad row — those projections are unchanged and must stay bounded. The full envelope
  goes to `*_submission` alongside them. It is **deliberately never pruned**, unlike `*_squad`:
  a finished tournament is exactly when someone asks what a club looked like when it entered,
  and the consumers (tournament features still in development) don't exist yet. Treat the
  contents as untrusted client data, never as game truth.
- **`facilities` is accepted again, for the submission row only.** It must not go back on the
  entrant, the challenge or a squad row — it was removed from those because every bracket
  render decoded it and nothing read it, and that is still true. Note it has to be *declared*
  on the DTO to be stored at all: `MapRequestPayload` drops undeclared properties, so an
  undeclared key never reaches the service even to be kept verbatim.
- **`GET /api/competitions/{id}` serves a prebuilt document** from
  `competition_response_cache`; only the `viewer` key and `serverTime` are composed per
  request. The cache is self-validating against a **state fingerprint** — a hash over an
  explicit tuple, because an additive integer collided (a withdrawal and another club's
  resubmission cancelled out) and omitted status/cancellation/template inputs. It is read
  and written through **DBAL, never the ORM**: a failed ORM `flush()` closes the shared
  EntityManager, and in a cron tick that stranded the next claimed round permanently.
  **Prewarming is a queued message, never inline in a cron tick.** A tick has already
  loaded the round's entrants and Doctrine will not re-hydrate them, so building the
  document there while fingerprinting fresh SQL stores a stale bracket under a fresh key.
  `WarmCompetitionResponseMessage` is dispatched by the draw and resolve handlers once their
  work has committed, and Symfony's `DoctrineClearEntityManagerWorkerSubscriber` clears the
  EntityManager before each message — which is what makes the warm handler's reads clean and
  is the reason it is a separate message rather than a tail call. Warm failures are
  swallowed: the cache self-validates, so a miss just means the next reader rebuilds.
- **The match engines take no entities.** `resolve(MatchEngineSide, MatchEngineSide,
  MatchEngineContext)`. `resolveFriendly()` is gone — a friendly is `allowDraws: true` on
  the context. Resolving one used to fabricate five entities and reflect over a private id
  to supply one UUID as an RNG seed.

### Starter Pool Audit (cron-queued top-ups)
`app:starter-pool:audit` (hourly, `docker/starter-pool-audit.sh`) sweeps every country in
`StarterConfig::enabledCountries`, runs the **same** `StarterPackDiagnosticService` verdict
`app:starter-pack:check` reports, and for any country that is not `ok` queues bounded
`TopUpStarterPoolMessage` chunks onto the **`pool`** Messenger transport — drained every
minute by `docker/pool-consume.sh`. Queue-only by design: a cold top-up is ~20s of DB-heavy
work per country, and doing that for every enabled country inline in one cron tick is how a
box falls over.

**This is a deliberate, narrow exception to "pool warming is no longer cron-scheduled"**
(`docs/deploy/hetzner.md`) — it is not the old blanket warm returning. A healthy country
queues nothing however far its pool has drifted from the warm floors; only "this country
can no longer hand a new club its starting squad" triggers it. `app:pool:warm` is still
unscheduled and still the general-purpose tool.

Three guards, all in `StarterPoolAuditService` — read its docblock before changing any of
them:
- **It never queues work generation cannot finish.** Under the default `PoolConfig`
  (players generated at 12–13, starter draw floored at `playerFirstTeamAgeMin` 19) a newly
  generated player can **never** clear the age floor, so a player shortfall is reported as a
  *blocker* for an operator to decide about, not ground at forever. Same when
  `bandHoldsOnlyKids` (the diagnostic now returns this specifically for the audit). The
  naive implementation generates hundreds of rows an hour and never moves the verdict —
  this is the single most important behaviour in the class.
- **It will not stack.** `queueIsBusy()` counts undrained `pool` rows in `messenger_messages`
  and skips the sweep while the previous one is still draining (the counts a second sweep
  would read have not caught up yet). `--force` overrides for a hand-run.
- **Chunks re-check their own target.** `TopUpStarterPoolMessageHandler` clamps to
  `target - current` before generating, so a replay, a double dispatch, or an admin pressing
  **Warm** mid-drain costs nothing instead of overshooting the floor.

Other things worth knowing:
- Targets are per **nationality**, not per country — that is the unit generation addresses.
  Auditing against the pool-wide count would have all enabled countries read the same zero
  and each queue a full deficit for it.
- It covers **all five** starter staff roles. `app:pool:warm` only ever warms
  `MANAGER`/`COACH`/`CHAIRMAN`, so a country short on `DIRECTOR_OF_FOOTBALL` or
  `FACILITY_MANAGER` stays short no matter how often it is warmed by hand.
- Blockers are printed by the command and land in `/var/log/starter-pool-audit-cron.log`.
  **Nothing else surfaces them** — that log is the place to look when a country stays
  degraded with an empty queue.
- The decision is `StarterPoolAuditService::planFor()`, a pure static (diagnosis + current
  stock in, plan out), so the precedence above is asserted directly rather than through
  mocks — see `tests/Service/StarterPoolAuditPlanTest.php`.

### Request Flow (POST /api/sync)
```
JWT firewall → SyncController::sync()
  → #[MapRequestPayload] → SyncRequest DTO
  → SyncService::process()
      → ClubRepository::findByUser()
      → persist SyncRecord (always)
      → anti-cheat check → 409 if week < lastSyncedWeek
      → update Club aggregates + manager trait shifts
      → LeaderboardEntryRepository::findOrCreate() × 6
      → EconomicService: financial year-end + sponsor check
      → flush → return JSON
```

### World Initialization Flow
When a client first boots (`POST /api/club/initialize`):
```
ClubController → ClubInitializationService::initializeClub()
  → creates Club, sets paName + manager traits
  → StarterPackService::initialize() → builds snapshots, deletes consumed Player/Staff from DB
  → LeagueService::assignClubToStarterLeague() → places club in country/tier league
  → WorldInitializationService::buildLeaguesPack() → serializes full league pyramid
  → WorldInitializationService::buildTierPack() → NPC clubs + fixtures for the club's tier
```

### Avatar Appearance (backend-owned)
`Player`, `Staff`, `Scout`, `Agent`, `User` each carry a nullable `appearance` json column holding the pixel-sprite `SpriteConfig` shape (15 keys, always all present regardless of role: `hair, hairColor, headband, skin, face, facial, lip, primary, secondary, kit, shorts, socks, outfit, trousers, glasses`) **plus the seven `AvatarExtras` keys stored FLAT beside them** (`earring, armband, wristband, snood, headwear, neckwear, tattoos`), added by the sprite's V4 drop.

**Flat, not nested, and that is the whole reason it needs no migration.** V4 froze `AvatarConfig` and takes accessories/tattoos as a separate `extras` render option — but the web builder's own `onChange` emits **one flat object**, and the sprite ships `splitAvatarConfig()` precisely to take that apart. So the backend stores exactly what the builder emits: a stored blob can be handed straight to `splitAvatarConfig()`, and `composePlayerSpriteSvg()` does that at its seam so every caller gets accessories and tattoos for free. A pre-V4 blob splits to empty extras and renders exactly as it always did — verified, not assumed. `tattoos` is a **list**; `AppearanceType` re-indexes and filters it on save, because a sparse array would encode as a JSON object rather than a JSON list.

**Role-gating is the SPRITE's job, in three layers that must not be confused.** The sprite ignores `armband`/`wristband`/`snood` on a staff body and `headwear`/`neckwear` on a player, exactly as it already ignores the kit group on staff — so the form's field list never varies. The admin widget gates which controls it *offers* (so no control visibly does nothing), and `AppearanceGeneratorService` gates which it *rolls* (so stored data holds nothing that draws nothing). `Tattoo::visibleFor()` is a verbatim port of the sprite's `visibleTattoos()`: ink only lands on bare skin, so staff show knuckles/neck/teardrop always and the arm pair only in the `polo` or rolled-sleeve `shirt`, and `leg` is players-only. This replaced an earlier, structurally different 10-key design (`skinTone, hairStyle, ..., jerseyVariant`) — if you see that shape anywhere (old docs, old test fixtures, a stale row that predates a `--force` backfill), it's the previous design, not a variant of the current one. The backend owns generation:
- `AppearanceGeneratorService` produces the 15-key config from `(id, role, age, nationality?)`, deterministic by id. **No cross-repo RNG-stream bit-parity is preserved with any frontend generator** — unlike the design this replaced, the sprite's own drawing logic (`sprite.ts`/`PlayerSprite.tsx`, from `wunderkind-app`) is pure rendering with no generation/RNG code, so there is currently no frontend counterpart to stay bit-identical with.
- **Every entity stores all 15 keys, regardless of role** — Player gets meaningful `kit`/`shorts`/`socks` and fixed defaults for `outfit`/`trousers`/`glasses`; Staff/Scout/Agent (all three render as the sprite's `'staff'` body shape) get the reverse. This mirrors the sprite's own documented convention that a saved config may hold both groups' keys harmlessly, since each `type` ignores the other's.
- **Skin is an id, not a hex.** `App\Enum\Appearance\SkinId` (`s1`..`s6`, lightest→darkest) carries two hex shades per case (`baseColor()`/`shadeColor()`) — the sprite draws a base fill plus a darker shade for ears/neck/brows, unlike the old single-hex `SkinTone`. `App\Enum\Appearance\WorldRegion::skinWeights()` is nationality-weighted exactly as before, just re-keyed to `SkinId` values (same 9 regions, same weight numbers — the light→dark ordering carried over 1:1).
- **Generated extras are AGE-GATED, and that is the one non-obvious rule.** The web builder's rates (earring 25%, armband 10%, wristband 20%, snood 10%, headwear 30%, neckwear 50%, each tattoo 15%) assume an adult. `PoolConfig` generates players at **12–13** by default — this is a youth-academy game — and six independent 15% rolls would ink roughly 62% of them. So `rollExtras()` gates tattoos and earrings to **18+**, the same shape of rule `rollFacialHair()` already applies; kit accessories (armband/wristband/snood) are not gated, since a wristband on a youth player is ordinary. A test asserts twelve-year-olds are never inked.
- **The three sprite-vocabulary enums are exact mirrors of the sprite's own lists, in the sprite's own order** — `HairStyle` (16), `Face` (15), `Outfit` (8), all extended by the V4 drop (2026-10-31) from 9/9/4. Order is load-bearing, not cosmetic: `hairStyleWeights()`, `faceWeights()` and `outfitWeights()` are **positional arrays** combined with `::cases()`, so a case added out of order silently re-labels every weight after it, and a case added without extending the array is an `array_combine()` count error. That is deliberate — it makes a drifting enum fail loudly. `AvatarSpriteOptionParityTest` reads the sprite's `HAIR_STYLES`/`FACES`/`OUTFITS` out of `window.AVATAR_SPRITE_OPTIONS` on the generated bundle and asserts membership **and** order (skipping when `node` is absent, like `TrophyConfigFactoryTest`), so the next drop that adds a hairstyle cannot leave the backend unable to generate it. **V4's `AvatarExtras` (earrings, armband, wristband, snood, cap/beanie, lanyard/whistle/scarf, six tattoos) has no backend enum on purpose** — it is a render option this backend never passes; the vocabulary is exposed on the bundle so wiring it later needs no archaeology.
- **`hair` is weighted by age band, not uniform.** `AppearanceGeneratorService::hairStyleWeights(int $age)` returns a `HairStyle`-keyed percentage table (three bands: ≤35, 36–45, 46+), same shape/style as `WorldRegion::skinWeights()` — `mohawk`/`afro`/`cornrows` stay low (≤7%) at every band and taper further with age, in favour of practical short styles; every style keeps a nonzero chance rather than being excluded outright past a cutoff. `facial` (facial hair) is age-gated per role via `rollFacialHair()`: staff/scout/agent unchanged (20+, 60% chance of stubble/beard); **players are newly eligible too** (25+, 35% chance) — previously hardcoded to `'none'` unconditionally. `headband` is role-split too: staff/scout/agent keep the base 20% chance, but **players roll it at 1-in-1000** — a deliberately vanishing-rare cosmetic, not an even split.
- - **`outfit` (staff/scout/agent only) is weighted too** (`outfitWeights()`): the original four read as more "club official", so V4's `polo`/`shirt`/`gilet`/`rain` are additions rather than an even eighth each. Two of them change what skin the sprite leaves bare — `polo` and `shirt` show the forearms, `shirt` and `rain` hide the neck — which is the only reason that matters backend-side.
- `hairColor`, `lip`, and `primary`/`secondary` (`KitColor`, one shared 12-color palette for both player kits and staff outfits — no more vibrant-player-vs-muted-staff split) are hex-backed enums, same pattern as `SkinId`'s predecessor. **`hairColor` is weighted** (`AppearanceGeneratorService::hairColorWeights()`, same `HairColor`-keyed-percentage-table style as `hairStyleWeights()`) — `GINGER` (orange) is deliberately rare (5%); the age>45 grey-forcing chance still runs first and short-circuits this entirely. **`lip` is skin-linked, not independent:** `SkinId::S5`/`S6` (dark skin) always get the reserved `LipColor::DEEP_BROWN` (`#5c3822`) — every other skin tone picks uniformly from the rest of the palette, and never draws `DEEP_BROWN` via generation (it's still a valid manual admin choice for any entity).
- `face` is a fixed, generated-once expression (`App\Enum\Appearance\Face`, **15 cases** since the sprite's V4 drop) — a pure cosmetic pick, **not** tied to morale or personality. This is a deliberate simplification versus the old design, where eyes/mouth were computed live from morale at render time. **V4 reintroduces a `morale` render option (1–100) that overrides `config.face` across ten bands, and this backend deliberately does not pass it** — a stored identity sprite is not a live mood readout, and `Club.morale` is fan morale rather than a person's. `AvatarSpriteOptionParityTest` asserts that omission is a decision rather than an oversight. **Players always default to `neutral`** — only staff/scout/agent get a varied pick — in both the generator and the admin widget's Randomise button. That pick is **weighted, not uniform** (`AppearanceGeneratorService::faceWeights()`): six of V4's new faces are the unhappy end of the sprite's own morale ladder, and a uniform pick over fifteen would leave roughly a fifth of every staff pool looking miserable or distraught. `GLUM`/`MISERABLE`/`DISTRAUGHT` sit at 1% each — reachable, since the admin picker offers them, but never the norm. Same reasoning as `GINGER`'s 5% in `hairColorWeights()`.
- `AppearanceLifecycleSubscriber` (Doctrine `prePersist`) auto-fills `appearance` for any of the five entity types persisted without one, passing `getNationality()` through, so **every** creation path is covered centrally (don't hook individual construction sites). For `User` this only ever gives a default staff-shaped avatar at registration (see Owner Identity below for how the real account holder customizes it afterward). `app:backfill-appearances` fills pre-existing null rows; `app:backfill-appearances --force` additionally **unconditionally regenerates every row**, overwriting whatever it had — this is the one-time migration mechanism when the Appearance shape itself changes (as it did here), not a targeted single-field refresh. Both passes stream via `Query::toIterable()` with periodic `$em->clear()` rather than loading the full table at once (this command previously OOM'd production on ~36.5k rows via `findBy`/`findAll()` and is deliberately excluded from the post-deploy sequence — see `docs/deploy/hetzner.md`; run it manually).
- `appearance` is emitted verbatim (a passthrough of `getAppearance()`) in every player/staff/scout/agent serializer — `buildPlayerSnapshot`/`buildStaffSnapshot`/`buildScoutSnapshot`, `MarketDataService::serialize{Coach,Scout,Agent}`, and `ScoutSearchController::serializePlayer`. All four are schema-agnostic passthroughs of the whole JSON blob — none of them needed to change for this rewrite, and none would need to change for a future one either.
- The admin edit form (`AppearanceType`) renders identically for all 15 keys on every entity type, but takes a `person_type` (`'player'`|`'staff'`) form-type option — set per CrudController via `->setFormType(AppearanceType::class)->setFormTypeOptions(['person_type' => ...])` (this EasyAdmin version's `setFormType()` takes only the class name; options are a separate chained call) — that only decides which body shape the live preview draws and which of the kit/shorts/socks vs outfit/trousers/glasses sections the widget shows; it does not change what's stored. **`public/assets/avatar-compositor.js` is GENERATED — do not edit it by hand.** `bash scripts/build-avatar-compositor.sh` rebuilds it from the app's `src/components/avatar-sprite/avatar16.ts` (the 16-bit sprite, which replaced the 8-bit `sprite.ts` on 2026-10-13) by running it through esbuild — type-stripping only, no other transformation. That IS the "port it verbatim" rule, now mechanised: ~480 lines of pixel maths cannot be hand-transcribed twice and still be credibly verbatim. The build gates on the sprite's own pixel-hash parity fixture (12 cases) and fails rather than shipping a drifted port. The only hand-written part is `scripts/avatar-compositor/entry.ts`, which adapts the renderer to the `window` API that already existed. `composePlayerSpriteSvg(config, type, crop, scale, opts)` keeps its old signature — including the `'small'`/`'large'` crop names, now aliases for `'head'`/`'full'` — so every existing caller works untouched; `scale` is halved internally because the new grid is 2x per axis (38x68 full, 28x30 head, against 18x31 / 14x17). **`opts.outline` defaults to `false`**, even though `avatar16.ts`'s own `renderAvatar` defaults it to `true` — the admin previews and the landing page both want the unoutlined look, matching `composeKitSvg`'s own no-outline default; pass `{outline: true}` to opt back in. **Build tier is an `opts.height`/`opts.weight` prop on the compose call**, taking either a tier (`'small'|'medium'|'large'`, what the sprite itself expects) or a raw cm/kg number mapped through the bands below (`heightCm`/`weightKg` remain as aliases for older call sites). Also exposes `window.SKIN_SHADES`, `window.AVATAR_BODY_SIZE` and `window.DEFAULT_AVATAR_CONFIG`. **Build tiers are derived, never stored:** the sprite takes `height`/`weight` as `'small'|'medium'|'large'`, and `Player` already owns real cm/kg columns, so the admin preview maps them at render time (`AppearanceType`'s `height_cm`/`weight_kg` options, set from the entity in `PlayerCrudController`). Copying a derived tier into the `appearance` JSON would be a second source of truth that goes stale the moment a player's height changes. Entities with no measurements (Staff/Scout/Agent/User) pass null and draw the neutral medium build. The bands are **height (cm)** small 145–169 / medium 170–184 / large 185–200, **weight (kg)** small 58–69 / medium 70–84 / large 85–100. Out-of-band values clamp rather than defaulting — real data has 464 players above the 200cm ceiling.

### Sync v2: In-Club Player/Staff Appearance (client-authoritative)
Distinct from Avatar Appearance above, which is backend-generated at pool stage: once a
player/staff is signed into a club, their `Player`/`Staff` row is deleted (pool-consumption —
see Pool Lifecycle above), so there is no backend row left to hold an evolving in-game
appearance. `POST /api/sync`'s `playerStats[]`/`staffStats[]` (sync v2, both additive/optional
— absent or `undefined` on older clients) report each currently-owned player's/staff
member's **client-authoritative** `appearanceConfig` back to the backend for storage, the
same trust model as `Club.homeKitConfig`/`badgeConfig` (client-supplied verbatim, no
server-side validation) — this is not generation, and `AppearanceGeneratorService` is not
involved.
- **`PlayerCareerStat.appearanceConfig`** (from `playerStats[]`) is **personal-traits-only** —
  `hair/hairColor/headband/skin/face/facial/lip`, no kit colors (`kit`/`shorts`/`socks`/
  `primary`/`secondary`), since a player's kit is club-derived and never sent per-player.
  **Kit color is determined from the parent club** (confirmed explicitly by the human,
  not an inferred default) — `PlayerCareerStat::toFullAppearanceConfig()` merges this
  personal-traits config with `$club->getHomeKitConfig()`'s kit colors into one complete,
  renderable sprite config. Not wired into any endpoint yet — groundwork for whenever a
  consumer (an admin view, say) needs to actually render this.
- **`StaffCareerProfile`** (from `staffStats[]`) is a **brand-new entity**, not an extension of
  `PlayerCareerStat` — there is no goals/assists/rating concept for staff, so don't try to map
  one onto the other. `appearanceConfig` here carries the full staff sprite shape (persists
  `outfit`/`trousers`/`glasses` too, unlike players' subset above). Keyed by client-generated
  `staffId` the same way `PlayerCareerStat` is keyed by `playerId` (Staff rows are pool-only
  and deleted on consumption, same as Player) — `UNIQUE(club, staffId)`, upserted every sync.
  `staffRole` is a **free string, not the `StaffRole` enum** — the client's role set is wider
  (e.g. `scout`, `assistant_coach` aren't `StaffRole` cases; `Scout` is a separate entity
  backend-side) — storing it typed would reject legitimate values.
- **Both `appearanceConfig` fields use "omitted key preserves, explicit `null` clears"
  semantics** (`array_key_exists`, not `??`, in `SyncService::processPlayerCareerStats()`/
  `processStaffCareerProfiles()`) — an older client that doesn't send the key at all must not
  wipe out a config a newer client already reported for that player/staff member. Neither
  field is copied onto `PlayerCareerStatSnapshot` (identity/cosmetic data, not a stat worth
  historizing across season resets).

### Owner Identity (User, backend-owned)
`User` carries `name`, `nationality`, `gender`, `dob`, and `appearance` (the same 15-key sprite shape as Player/Staff/Scout/Agent) — the real account holder's own identity, single per account and read live by every club that account creates via `Club::getUser()` (no per-club copy). This **replaces** two removed, redundant blobs: the ad hoc `User::$managerProfile` (previously set unvalidated from the raw registration payload) and the structured but never-serialized `Club::$managerProfile` (previously set once at club creation from `ClubInitRequest::$manager`/`ManagerProfileInput`). `Club::$paName` (the fictional in-game "player agent" persona) is unrelated and untouched.
- **`AppearanceRole::OWNER`** is the role passed to the generator for `User` — it falls into the existing non-player ("staff shape") branching with zero code changes needed inside `AppearanceGeneratorService` itself, same as COACH/SCOUT/AGENT.
- **`OwnerAvatarController`** (`ROLE_CLUB`) exposes both `GET /api/owner-avatar` (reads back the current `{name, nationality, gender, dob, avatar}`) and **`POST /api/owner-avatar`**, a **partial update**: only keys actually present in the JSON body are applied (checked against the raw request array, not just the DTO's nullable properties, since an omitted field and an explicit `null` are indistinguishable on a plain nullable property) — omitted fields are left untouched. Avatar resolution: a client-supplied `avatar` is stored verbatim (the app's own avatar-builder UI is the source of truth, no per-field validation); otherwise, if `nationality`/`dob` changed this call **and** the appearance is still null, one gets generated (first-fill only — changing `name` later never silently overwrites a saved custom look).
- `gender` is restricted to `male`/`female` (same `Assert\Choice` the old `ManagerProfileInput` used).
- Admin (`UserCrudController`) has an "Owner Identity" fieldset with the same `AppearanceType`/`person_type: 'staff'` widget wiring as `AgentCrudController` — EDIT is now enabled (previously fully disabled); NEW/DELETE stay disabled (accounts are created via registration and removed via account deletion, not admin).

### Centralized User Ledger (UserLedger, backend-owned)
Now that a single owner avatar (see Owner Identity above) persists across every club a `User`
owns, `UserLedger` (table `user_ledger`) centralizes a user's cross-club earnings instead of
leaving a manager's dividend trapped inside whichever single club's `totalCareerEarnings` it
happened to land on. Today it holds exactly one kind of entry — `UserLedgerEntryType::DIVIDEND_DRAW`
— detected server-side from the sync payload's free-form `ledger[]` array
(`SyncRequest::$ledger`, the same array `SyncService` already archives verbatim onto
`SyncRecord::$payload`): any entry with `category === 'dividend_draw'` is a client-reported
dividend draw against the syncing club.
- **`UserLedgerService::recordDividendDraws()` is the single writer.** Both `SyncService::process()`
  (every live sync) and the one-time `app:backfill-user-ledger` command (replays every historical
  `SyncRecord` for payloads predating this feature) go through it, so the balance-chaining logic
  below exists in exactly one place.
- **Every row snapshots `balanceBeforePence`/`balanceAfterPence`** — the user's overall centralized
  balance immediately before/after that entry (`balanceAfterPence = balanceBeforePence + amountPence`)
  — so the running total is reconstructible from history alone, not trusted to only the latest row.
  `UserLedgerRepository::getCurrentBalance()` reads the latest row's `balanceAfterPence`.
- **`dividend_draw`'s `amount` is real pence — the one `ledger[].category` that is NOT
  100x-inflated.** Every pre-existing category carries that inflation (a bug in the client's
  existing on-device ledger-writing engine — see `sync_record.payload` field conventions in
  `.context/stages/03_data/output/schema.md`), but `dividend_draw` is a brand-new category
  with no shipped client code to inherit it from, so it was deliberately specified as
  correctly-scaled from the start (explicit human decision, not inferred) — same reasoning as
  `promises[].offer.amountPence`. **Never** route it through
  `LiveTelemetryService::ledgerAmountToPence()`. See `docs/api/user-ledger.md` for the
  frontend-facing contract.
- **Idempotent per `(sourceSyncRecord, sourceLedgerIndex)`** — the `uq_user_ledger_source_entry`
  unique constraint, checked via `UserLedgerRepository::existsForSource()` before inserting. This is
  what makes re-running the backfill command, or re-processing a resent sync, a safe no-op.
- **`sourceSyncRecord` is nullable with `ON DELETE SET NULL`, not `CASCADE`** — a rollback purges
  superseded `SyncRecord` rows (`SyncRecordRepository::deleteByClubFromWeek()`), but a dividend draw
  already folded into a user's balance must survive that purge (same reasoning as
  `PlayerCareerStatSnapshot::$syncRecord`). No other rollback special-casing: a dividend draw
  recorded against a later-rolled-back timeline isn't retroactively reversed, same precedent as
  `Transfer`/`PlayerCareerStatSnapshot`.
- **`app:backfill-user-ledger`** pre-filters `sync_record` via a raw `payload::jsonb -> 'ledger' @>
  '[{"category":"dividend_draw"}]'::jsonb` containment query (payload is stored as `json`, not
  `jsonb`, so the cast is explicit) rather than hydrating every historical sync — then replays
  candidates in `server_timestamp ASC` order (real-world chronological, not per-club, since the
  running balance is per-*user* across every club they own), keeping an in-memory per-user running
  balance for the whole pass rather than re-querying per row.
- **`UserLedgerCrudController`** (admin) is read-only, same pattern as `NotificationLogCrudController`.
- **The admin User edit page (`/admin/user/{id}/edit`) surfaces this.** `UserCrudController::configureCrud()` points `crud/edit` at `admin/user_edit.html.twig`, which extends `@EasyAdmin/crud/edit.html.twig` and overrides only `content_header_wrapper` (`{{ parent() }}` first) — the real form is untouched. `edit()` adds `clubsSummary`/`clubKitConfigs`/`overallBalancePence` onto EasyAdmin's own `KeyValueStore` rather than calling `$this->render()` directly (unlike `detail()`'s fully custom `user_profile.html.twig`), same technique as `PlayerCrudController::index()`'s `playerSummary` panel. Per club: composited home/away kit + badge previews (`kit-compositor.js`'s `composeKitSvg()`, 'small' size), last-sync week/league-position/form (via the shared `resultBadge` macro, extracted from `club_profile.html.twig` into `admin/_macros.html.twig` for this reuse), `totalCareerEarnings` vs. `UserLedgerRepository::getTotalDividendsByClub()`; plus the user's overall `getCurrentBalance()` at the top.

### Kit & Badge Identity (NpcClub, backend-owned)
`NpcClub` carries a nullable `identity` json column: `{home: KitVariant, away: KitVariant, badgeShape, badgePattern, badgeCentre, initials, badgeFill, badgeTrim, badgeSymbol}`, where a `KitVariant` is `{kit, primary, secondary, shorts, socks}` — two full, independent kits (a club's home and away colors), plus one shared badge. Matches `assets/Components/KitBadge/kitSprite.ts`/`KitSprite.tsx` in `wunderkind-app` (that component takes one `KitConfig` at a time — render it once per variant, e.g. `<KitSprite config={identity.home} />` / `<KitSprite config={identity.away} />`). `kit`/`primary`/`secondary`/`shorts`/`socks` (both variants) reuse the **exact same enums** as the player appearance system (`KitStyle`, `KitColor`, `KitPart`) so a club's kit never drifts from a player's kit vocabulary; `BadgeShape`/`BadgePattern`/`BadgeCentre` are new, cohabiting in `src/Enum/Appearance/` for the same reason.
- **The home kit is the single source of truth for `primaryColor`/`secondaryColor`.** Those two existing (still `NOT NULL`) string columns are no longer independently admin-editable (`NpcClubCrudController` hides them on the form, `->hideOnForm()`, but still shows them read-only on the detail page) — `NpcClub::setIdentity()` syncs them from `identity['home']['primary']`/`['secondary']` every time a non-null identity with a home kit is set, so this happens automatically on both admin save and generation. `NpcClubGenerationService`'s old separate 20-color color-pair generator (`pickColorPair()`) is gone, but its **WCAG-contrast-checked-pair rule lives on**: `randomKitVariant()`'s `primary`/`secondary` (called once each for home and away, independently) are picked via `pickContrastingKitColor()` — up to 20 random tries for a candidate scoring `contrastRatio() >= 3.0` against `primary`, falling back to whichever palette color scores highest — same algorithm as the old `contrastRatio()`/`relativeLuminance()`, just re-scoped to the smaller 12-color `KitColor` palette (every color in that palette clears 3.0 against at least white or black, so the fallback path is a safety net, not something normal generation actually hits).
- **One creation path, no lifecycle subscriber.** Unlike Player/Staff/Scout/Agent's `AppearanceLifecycleSubscriber` (multiple creation paths), `NpcClub` is only ever constructed in `NpcClubGenerationService::generateClubs()`, so `generateIdentity()` (which calls `randomKitVariant()` twice, once per side) is just a private method called inline there — no Doctrine event needed.
- `badgeCentre` allows `none` and `initials` as real, admin-settable choices, but the **generator (and the widget's Randomise button) exclude both** — a blank badge centre isn't a useful thing to roll randomly, and a generated club's `initials` are a mechanical name-abbreviation, not a designed badge centre. Generated clubs only ever land on `star`/`ball`/`crown`. Randomise also rolls `badgeFill` as 50% chance equal to the **home** kit's primary (not away's).
- `initials` is sanitized server-side on submit (`KitIdentityType::mapFormsToData()`: upper-cased, `A-Z0-9` only, max 3 chars) regardless of what the widget's text input sent — mirrors the frontend's `sanitiseInitials()`.
- The admin widget (`KitIdentityType` + `templates/admin/field/kit_identity.html.twig` + `public/assets/kit-identity-widget.js`) follows the same architecture as the appearance widget (native fields `.visually-hidden`, not `display:none`; swatches read hex straight off each hex-backed enum's option values). Three tabs — **Kit Home / Kit Away / Badge** — and the live preview itself swaps between home-kit-only, away-kit-only, and badge-only content depending on the active tab, rather than always showing one combined preview. The form's own child fields are flat and prefixed (`homeKit`, `awayPrimary`, ...) rather than using Symfony's nested-form-group machinery — simpler given `KitIdentityType` already has a custom `DataMapperInterface`, which nests them back into `home`/`away` on submit. **Shorts/socks/trousers-style `KitPart` fields must never be rendered as a full kit thumbnail** — shorts/socks aren't visible in the shirt-only crop used for the kit-style grid, so every option renders as an identical shirt icon regardless of which part color is selected; render plain color chips instead (`.ap-chip`/`.ap-chip-swatch` in `admin-appearance-widget.css`), resolved against that variant's own live primary/secondary (or the fixed white/black) — this bit both the player appearance widget and this one before being fixed. **`public/assets/kit-compositor.js` is GENERATED — do not edit it by hand.** `bash scripts/build-kit-compositor.sh` rebuilds it from `assets/Components/KitBadgeV2/kit16.ts` (the 16-bit kit+badge sprite, which replaced the 8-bit `kitSprite.ts` on 2026-10-13) through esbuild — type-stripping only — gated on that sprite's pixel-hash parity fixture (36 cases: kit and badge, each with the outline off and on). **The sprite draws no outline by default** (changed 2026-10-13); the 1px padding is still there either way, so the image size is identical and `composeKitSvg`'s optional 5th argument `{outline: true}` opts back in. Same mechanised-verbatim rule and same shape as `scripts/build-avatar-compositor.sh`; the two are deliberately separate self-contained scripts because their sources and fixture layouts differ. The one hand-written part is `scripts/kit-compositor/entry.ts`. `composeKitSvg(config, type, crop, scale)` keeps its old signature — the `'large'`/`'small'` crop names are now aliases for `'full'`/`'shirt'` — so the landing page, club profile and kit-identity widget all work untouched; `scale` is halved internally for the doubled grid (kit 34x40, badge 32x34, shirt crop 28x20, against 16x20 / 15x16). **`KitConfig` is unchanged between the two sprite generations** — same keys, values and defaults — so stored `NpcClub.identity` and `Club.homeKitConfig`/`awayKitConfig`/`badgeConfig` need no migration. Also exposes `window.cleanInitials` (the sprite's own initials cleaner, so it cannot disagree with `KitIdentityType::mapFormsToData()`), `window.KIT_SPRITE_OPTIONS` and `window.DEFAULT_KIT_CONFIG`.
- `LeagueImportExportService`'s club export/import (hand-maintained, guarded by a reflection-based coverage test in `LeagueImportExportRoundTripTest`) includes `identity` verbatim (nested shape and all — it's schema-agnostic passthrough, no changes needed there for the home/away restructure) — casts must check `!== null` before `(array)`, since casting a null JSON value directly would silently turn it into `[]` and break the round-trip.

### Trophy Identity (League, CompetitionTemplate, backend-owned)
`League` and `CompetitionTemplate` each carry a nullable `trophy` json column holding the 16-bit trophy sprite's own `TrophyConfig`: `{style, finish, ribbons, ribbonPrimary, ribbonSecondary}`. Stored and served **verbatim**, same passthrough treatment as `Player.appearance` and `NpcClub.identity`, and rendered by `composeTrophySvg()` admin/landing-side and `TrophySprite` in the app. Client contract: `docs/api/trophy-config.md`.
- **This replaced an asset-slug system, not a variant of one.** The old pair was `trophyImage` (`trophy-1`..`trophy-15`, naming a static black silhouette served from `public/images/trophies/{slug}.svg`) + `trophyColour` (`App\Enum\TrophyColour`: `silver`/`gold`/`gold_silver`, applied as a **CSS filter chain** that was duplicated across the admin leagues screen and the landing page's honours board, each carrying a comment to keep the two in step). All of that is gone — one renderer, no filter chains. If you see a `trophy-N` slug or a `brightness(0) invert(1) sepia(...)` chain anywhere (old docs, a stale export file, a `ClubSpotlight` row generated before the change), it's the previous design.
- **`TrophyStyle` (10 cases) and `TrophyFinish` (3) live in `src/Enum/Appearance/`** beside `KitStyle`/`KitColor`/`BadgeShape`, for the same reason those do: the values are the sprite's own ids and a config reaches `composeTrophySvg()` with nothing in between to translate a renamed case. **`ribbonPrimary`/`ribbonSecondary` reuse `App\Enum\Appearance\KitColor`** — the sprite's ribbon palette *is* the shared 12-colour kit palette, so a trophy's ribbons can take a club's own colours and can never drift from the kit vocabulary. `TrophyConfigFactoryTest` asserts all three lists (and the defaults) against the generated bundle's own exports rather than trusting a second copy.
- **Finish kept every option the old colour enum offered; only `gold_silver` was renamed, to the sprite's `twotone`.** Style has no artwork correspondence to preserve, so the 15 slugs spread across the 10 styles **in order** (`TrophyConfigFactory::legacyStyleOrder()`, which is `TrophyStyle::cases()` — **the enum's declaration order is therefore load-bearing**, and a test guards it). Only `trophy-1`..`trophy-8` were ever in use (one per league tier), so each tier landed on a distinct style and tier 1 kept the flagship `classic`. Migrated trophies keep ribbons **on**: the old design had no ribbon concept and off would make every converted trophy look plainer than the one it replaced.
- **`null` means "awards no trophy" and is load-bearing.** `ClubSpotlightService` lists a title with a null config rather than dropping real silverware over a missing admin setting (the honours board renders a count-only chip); `GameConfig::leagueTierDefaults` ships with none set. So `TrophyConfigFactory::validateNullable()` exists alongside `validate()`, `TrophyConfigType` maps an empty `style` back to `null` (surfaced as an "Awards a trophy" toggle, not an empty tile in the style grid — an empty tile among trophy thumbnails reads as a broken asset), and the quick-edit/tier-defaults forms keep an explicit "— None —". **Never default a null trophy into a visible cup.**
- **`TrophyConfigFactory` is the single validator**, all-static and dependency-free (following `CountryContentRegistry`) because `GameConfig` normalises its own rows through it and an entity can't be injected. It fronts admin form posts, the world import and the migration backfill; it fails soft per key (a bad enum or non-`#rrggbb` colour falls back, nothing throws). It deliberately has **no `random()`** — league and competition trophies are admin-chosen or come from the tier-defaults table, so nothing server-side rolls one; the admin widget's Randomise is `window.randomTrophyConfig()` from the compositor bundle, which implements the web builder's own rules (uniform style/finish, ribbons 60%, distinct ribbon colours).
- **`GameConfig::leagueTierDefaults` rows normalise on read AND write** (`normaliseTierRow()`). That column is plain stored JSON, so a row saved before this change still carries `trophyImage`/`trophyColour` — as does any config export file taken back then, and admins are told those are safe to commit and re-import. The migration deliberately does **not** touch that JSON; folding on read covers both, and folding on write converges it. `LeagueImportExportService` does the same for world export files, via `TrophyConfigFactory::fromLegacy()`.
- **`public/assets/trophy-compositor.js` is GENERATED — do not edit it by hand.** `bash scripts/build-trophy-compositor.sh` rebuilds it from `assets/Components/trophy16/trophy16.ts` through esbuild (type-stripping only), gated on that sprite's pixel-hash parity fixture (24 cases, covering shadow and outline on/off). Same mechanised-verbatim rule and same shape as `scripts/build-{avatar,kit}-compositor.sh`; the three are deliberately separate self-contained scripts because their sources and fixture layouts differ (this one's fixture is a flat `cases` map whose entries carry their own render `options`). The one hand-written part is `scripts/trophy-compositor/entry.ts`. **`composeTrophySvg(config, scale, opts)` does not halve `scale`** — unlike the avatar and kit compositors, which carry a `SCALE_RATIO` of 0.5 so pre-16-bit callers keep rendering at their old size. The trophy sprite has no 8-bit predecessor and no legacy call sites, so `scale` is dp per sprite px straight through (default 4, the integration doc's own). Two `opts` defaults deliberately diverge from `TrophySprite.tsx`, whose props are written for a standalone tile: **`background` defaults to `'none'`** (every caller here draws onto a surface that already has one — an EasyAdmin panel, a league card, a dark landing section) and `shadow` follows the background. Pass `{background: 'colour', shadow: true}` for the designed teal "cabinet tile" look, as the widget's main preview does. `outline` defaults to `false`, matching both the sprite and the other two compositors. Also exposes `window.randomTrophyConfig`, `window.TROPHY_SPRITE_OPTIONS`, `window.DEFAULT_TROPHY_CONFIG`, `window.TROPHY_BG` and `window.TROPHY_SPRITE_SIZE`.
- The admin widget (`TrophyConfigType` + `templates/admin/field/trophy_config.html.twig` + `public/assets/trophy-config-widget.js`) follows the appearance/kit widget architecture exactly — native fields `.visually-hidden` (not `display:none`), swatches reading hex straight off `KitColor`'s option values, thumbnail grids per enum field, a live preview, and Randomise. Single-panel rather than tabbed: a trophy is one object, so there's nothing to tab between. Registered per-CrudController via `->addFormTheme('admin/form/trophy_config_theme.html.twig')` plus `Field::new('trophy')->setFormType(TrophyConfigType::class)`; like `AppearanceType`/`KitIdentityType` it must tolerate EasyAdmin's injected `CollectionType` options (see Key Gotchas). The admin leagues screen's own three trophy surfaces (per-tier defaults table, league cards, quick-edit modal) are **not** widget instances — they post five flat fields (`trophyStyle`/`trophyFinish`/`trophyRibbons`/`trophyRibbonPrimary`/`trophyRibbonSecondary`) read back by `LeagueAdminController::trophyFromFields()`, and their option lists come from `DashboardController` (derived from the enums), never hand-written in Twig. **An unchecked checkbox posts nothing**, so an absent `ribbons` key means OFF there — the opposite of `TrophyConfigFactory`'s "absent key keeps the default" rule, which is why `trophyFromFields()` defaults it to `false` explicitly.
- **The 15 retired SVGs are still in `public/images/trophies/`, deliberately.** Nothing in this repo references them after this change, but a deployed app build still fetches them until the frontend ships its own `TrophySprite`. Delete them once it has.

### Facility Sprites (landing page, backend-owned)

- **`public/assets/facility-compositor.js` is GENERATED — do not edit it by hand.**
  `bash scripts/build-facility-compositor.sh` rebuilds it from
  `wunderkind-app/src/components/facility-sprites/{isoEngine,plotSite,stadiumConfig,stadiumSurrounds,trainingGroundConfig,medicalCentreConfig,scoutingConfig}.ts`
  through esbuild (type-stripping only). The one hand-written part is
  `scripts/facility-compositor/entry.ts`. Same mechanised-verbatim rule as the
  avatar/kit/trophy compositors.
  **The source moved and was renamed by the stadium v3 drop (2026-10-31):**
  `assets/Components/facilitiesV2/` no longer exists, every `*Sprite.ts` became
  `*Config.ts`, and `stadiumSurrounds.ts` is new — so the default source is now the app's
  own copy (read-only), the convention `build-avatar-compositor.sh` already used. A drop
  whose files are renamed again will fail the script's existence check rather than
  silently rebuild from nothing.
  **The gate reads two fixtures and 432 cases:** `parity.json` for training/medical/
  scouting, and `stadium-v3.parity.json` for the stadium — 131 `cases` (the v3 look) plus
  63 `v2Look` re-rendered with `STADIUM_V2_LOOK`, which is what proves v3 did not move
  anything it claimed to leave alone. The four-site fixture's own `stadium` map is skipped
  deliberately: it **is** `v2Look`, verified key-for-key and hash-for-hash. It also
  deep-checks the exported geometry against `stadium-v3.geometry.json` — a different class
  of drift from the pixel hashes, since those are the numbers a host sizes containers from.
  **Two fixture generations shape options differently** and the difference is invisible
  until a cropped facility tile silently renders as the whole site: the four-site fixture
  puts `rotation`/`time`/`selected`/`facility` FLAT on the case, stadium-v3 nests
  everything under `options`. The gate reads both and forwards whatever is there
  wholesale.
- **The stadium's look-affecting options are set EXPLICITLY at one seam**,
  `composeStadiumSvg()` in the two entry files — v3's contract requires it, because an
  option left implicit lets a future drop change what this backend renders without a line
  of this repo changing. `floodlightStyle: 'arch'` and `scoreboardPlacement: 'stand'` are
  v3's own defaults, deliberately restated. **They are a visible change from v2**: the
  pylons curve in over the corners and are shorter, and the scoreboard moves from the
  north-west corner onto the back stand's roof — which stand depends on `rotation`, so the
  stadium's geometry now depends on rotation and not just the camera. Spread
  `STADIUM_V2_LOOK` into `opts` at a call site to pin the old look for one surface.
- **`surrounds` is a render OPTION that travels as a config key, and exactly one place
  resolves that.** `StadiumConfig` is frozen in v3, so the five surroundings variants
  (parkland / suburban / terraces / city / industrial) arrive as an option — but the
  backend still has to get a per-club value to the browser.
  `FacilityConfigBuilder::surroundsFor()` therefore puts it **inside the stadium json
  blob** (no column, no migration, since the value is derived rather than authored), and
  `composeStadiumSvg()` lifts it out into the options and deletes it from the config, so
  what reaches the renderer is exactly a `StadiumConfig`. An explicit `opts.surrounds`
  wins; a payload without the key — every `ClubSpotlight` row written before v3 — falls
  through to `parkland`, which **is** the previous drop's trees-only look, so an old row
  renders as it always did rather than as someone else's city.
  **It is derived from the club's LEAGUE TIER**, the one signal the backend already holds
  that says anything about a club's setting: 1–2 `city`, 3–4 `suburban`, 5–6 `terraces`,
  7–8 `industrial`, no league `parkland`. Note tier 1 is the TOP division and 8 is where
  new clubs start, so the ladder reads downwards — inverting it would put brand-new clubs
  in a city centre and champions on an industrial estate. Only applies while
  `config.trees` is on; `trees: false` hides every variant.
- **All four sites live in ONE bundle** because `isoEngine` is ~32KB and every site imports
  it — four per-site bundles would ship four copies to a browser needing one. Exposes
  `composeStadiumSvg` / `composeTrainingGroundSvg` / `composeMedicalCentreSvg` /
  `composeScoutingSvg` (all `(config, opts, scale)`), the four `DEFAULT_*_CONFIG`, and
  `window.FACILITY_SITES` (ids, labels and game slugs per site). `opts` forwards
  `facility`, `rotation` and `time`, which is what makes single-facility tiles possible.
- **`scripts/build-stadium-compositor.sh` and `public/assets/stadium-compositor.js` still
  exist** and still ship the stadium alone. `composeStadiumSvg` has a byte-identical
  signature *and output* in both (re-verified after the v3 drop), but the landing page now
  loads the four-site bundle and **nothing references the stadium-only pair** — so it is
  pure maintenance cost: every sprite drop has to be applied to two scripts and two entry
  files, and this pair silently rotted once already (its default source path pointed at a
  directory that no longer exists). Retiring it is still an open call.
- **`src/Service/Facility/FacilitySpriteMap.php` is GENERATED by the same script.** The
  sprite drop authors a `slug` on every facility (plus `STADIUM_SLUGS`), so which game
  facility draws as which sprite id is stated once, upstream, in the file that draws it —
  `science` is `biometric_hub`, `rehab` is `hydro_pool`, and so on for all 22. The PHP is
  compiled from the built bundle rather than by re-parsing TypeScript, so it is exactly
  what the browser gets, and it is committed so nothing at runtime needs the external drop.
  `FacilitySpriteMapTest` guards the join the generator cannot see — that the sprites and
  the seeded `FacilityTemplate` rows agree on which facilities exist (its two DB-backed
  cases **skip** on `wunderkind_test`, which seeds no templates).
- **`ClubSpotlight::$facilities`** (json, nullable) carries all four sites as ready-to-render
  configs, built by `ClubSpotlightService::buildFacilityConfigs()`. `$stadiumConfig` is
  unchanged beside it — the landing already consumes that key, so the stadium is
  deliberately duplicated rather than folded in. Null on rows generated before the column
  existed, and null must read as "no payload", never as "empty": every `DEFAULT_*_CONFIG`
  is the web builder's **demo** config, so a page mistaking the two draws someone else's
  facilities. That is also why every facility is emitted with an explicit on/off boolean.
- **Levels are doubled AND clamped to 1-10** (`ClubSpotlightService::spriteLevel()`).
  `FacilityTemplate` levels run 1-5 and the sprites 1-10, but `club_facility.level` is
  written from the client's `facilityLevels` sync payload and is never validated against
  that range — live rows reach **11** for training, 10 for medical, 7 for scouting. Doubled,
  an 11 indexes past the end of a size table that stops at 10. `buildStadiumConfig()` now
  shares the same helper; it escaped the bug only because stadium levels happen to be ≤5.
- **`*Construction` is always false.** `club_facility` is `(club, slug, level)` and the sync
  payload is a flat `slug => int` map: the backend has no notion of an upgrade in progress,
  so claiming one would be invention. **Unknown slugs are skipped, not errors** — the table
  stores whatever the client sends, and live data holds five slugs with no `FacilityTemplate`
  at all (`away_stand`, `main_stand`, `kinetic_lab`, `neuro_shed`, `zenith_sanctuary`).

### Personality Matrix (Player, Staff, Scout)
The 8-spoke matrix (`determination, professionalism, ambition, loyalty, adaptability, pressure, temperament, consistency`, each **1–20**) lives in the `PersonalityProfile` `#[ORM\Embeddable]`, mapped onto `player`, `staff` and `scout` as `personality_<trait>` SMALLINT columns defaulting to 10.

- **Personality is independent of ability.** It does **not** derive from `potential`, `coachingAbility` or `experience` — how good an entity is says nothing about what it is like. (An earlier model anchored traits on those stats; it produced flat, interchangeable profiles and was removed.)
- **Generation is mould-driven, not per-trait.** `PersonalityGeneratorService::rollTraits(PersonalityContext)` runs a fixed six-step pipeline: pick a `PersonalityMould` by weight → base-roll all eight traits Gaussian(μ, σ) → apply the mould (dominants 15–20, flaws 1–7, moderates 8–13; `BALANCED` clamps everything to 7–14) → apply correlation rules → apply role floors → clamp 1–20. The point is trade-offs: a standout strength paid for with a real flaw.
- **`PersonalityMould` is NOT `PlayerArchetype`.** The mould is an internal generation shape, never persisted and never serialized. `PlayerArchetype` is the shipped catalogue the **client** classifies against after the fact, and stays the single source of archetype truth. The two layers share vocabulary — don't let them share code.
- **Precedence is load-bearing, in this order:** mould pins a trait → correlations may only touch traits the mould left free (the design spec is explicit that an archetype overrides a correlation) → role floors are applied last and override everything, because a manager who folds under pressure would not be a manager.
- **Correlations read a frozen snapshot**, never the mutating matrix. Evaluating them in sequence against live values let the Mercenary Divergence cap Loyalty and thereby erase the Club Stalwart trigger before it was tested — whichever rule ran first silently suppressed the other. `applyCorrelations()` is public so this precedence can be asserted directly rather than inferred from sampled output.
- **The μ=10.5 / σ=3.2 / ~5–8%-extremes baseline describes the _base roll_, not the emitted population.** Steps 3–5 push traits to the ends deliberately, so finished matrices are far more extreme than the underlying Gaussian. Assert the baseline against `gaussianInt()`, which is also the codebase's only Box-Muller draw (`PlayerGenerationService::bellCurveInt` delegates to it).
- **`PersonalityContext` carries the band**: `forPlayer(age)` (≤16 σ=4.2 with Pressure/Consistency skewed to μ=8; 17–20 σ=3.2; 21+ σ=2.8), `forStaff(role)` (σ=2.8; `MANAGER`/`COACH` floor Determination ≥11, Temperament ≥9, Pressure ≥12), `forScout()` (σ=2.8; Adaptability ≥13, Consistency ≥12).
- **Note on age bands:** `PoolConfig` defaults `playerAgeMin/Max` to **12/13** — this is a youth-academy game and generated players get `Guardian` rows. The 17+ bands exist because `PoolConfig` is admin-editable, but under default config only the youth band ever fires.
- **Staff/Scout generation is centralised on persist.** `PersonalityLifecycleSubscriber` (Doctrine `prePersist`) fills any Staff/Scout whose profile is still all-defaults, covering every creation path (`MarketPoolService`, `app:generate-market-data`, admin) — same pattern as `AppearanceLifecycleSubscriber`. It only fires on **creation**, so pre-existing pool rows keep whatever they had; regenerate the pool to roll them. **Player is deliberately excluded**: `PlayerGenerationService` rolls the matrix inside its blueprint because the result feeds back into the power/stamina/heart derivation.
- **`isDefault()` is the "ungenerated" signal.** An embedded value object is never null, so all-traits-at-10 is the only marker available.
- **`PersonalityProfile::toArray()` is the single serialized shape** — used by `buildPlayerSnapshot`, `buildStaffSnapshot`, `buildScoutSnapshot`, and `MarketDataService::serialize{Coach,Scout}`. Don't re-inline the eight keys. `Agent` has no matrix.

### Player↔Agent Association (world pack)
`Player` has a nullable `?Agent $agent` FK (a **many-players-to-one-agent** relationship — one agent represents several players). Agents are a persistent shared pool (never deleted on consume, unlike Player/Staff). Agent surfacing:
- `buildPlayerSnapshot` nests the agent under each player via `Agent::toSnapshotArray()` (`{id, name, commissionRate, reputation, experience, rating, nationality, dateOfBirth}` or `null`; excludes the internal `judgements`) — the single shared agent shape, also used by `ScoutSearchController::serializePlayer`. Don't re-inline that array.
- At world-pack generation (`buildLeaguesPack`/`buildTierPack`), the loaded pool (`AgentRepository::findAll()`) is first bounded by `selectBoundedAgentPool()` to `ceil(estimatedNpcPlayers / StarterConfig::worldPackPlayersPerAgent)` (default 12 → ~12 players/agent, capped at pool size), then `assignAgents($players, $boundedAgents)` **reassigns every NPC-club player** a random agent from that bounded subset before the player is snapshotted and deleted. Without the bound, distinct agents surfaced would scale with the whole pool (one agent per player). Association follows the same structural nesting as player↔club/staff↔club (there is no player↔club FK; association is the snapshot nesting).
- **Dependency:** the ratio caps at pool size, so for a full country pack to reach its target the agent pool must hold ≥ that many agents (`PoolConfig::agentPoolTarget`, default 100). Agent generation is additive and agents are never consumed, so pools can balloon — the world-pack bound makes surfacing correct regardless.
- Agent pool size is `PoolConfig::agentPoolTarget` (default 100), driving generation/replenishment in `MarketPoolService`. `MarketPoolService::generatePlayers` also assigns pool players a random agent at generation time; the world-pack pass reassigns.

### World Generation: Countries & League Tier Defaults (backend-owned)
Full repeatable process for adding a new playable country: `docs/world-generation/adding-a-country.md`.
- **`App\Enum\Country`** is the single source of truth for country code↔label↔nationality↔locale↔generation-capability (32 cases; `isGenerationCapable()` covers 25 of them — `ES, EN, DE, IT, FR, BR, AR, NL, PT, US, CA, JP, NG, PL` plus the 2026-10 expansion `SC, WA, BE, MX, TR, KR, IN, SA, CO, EG, TN`. **`SC` (Scotland) and `WA` (Wales) are pseudo-codes**, not ISO: neither nation has its own alpha-2 code and every `country` column is `VARCHAR(2)`, so a three-letter football code would need a migration across seven tables — the same reasoning that already made England `EN` rather than `GB`. `SC` collides with ISO's Seychelles, so a future Seychelles needs a different code rather than reassigning this one). **A `Country` case, a generation-capable country, and a player-visible country are three different things** — see the enum's own docblock; `PL` is generation-capable but still not in `StarterConfig::enabledCountries`. `Country::fromNationality()`/`labelMap()` exist specifically so `NameGeneratorService`/`WorldRegion`/admin templates key off this enum instead of hand-duplicating the nationality/label list — don't add a new standalone nationality or country-label list anywhere; extend this enum and its callers instead.
- **`App\Service\CountryContent\CountryContentRegistry`** is the single canonical home for per-country generation *content* — place/population/region/capital data, club-name suffixes, stadium-name formats, skin-tone `WorldRegion`, and `MarketPoolService` business-name cluster — all keyed by `Country`. This replaced four scattered, independently-drifting consts (`NpcClubGenerationService`'s own `PLACE_NAMES_BY_COUNTRY`/etc., `WorldRegion::NATIONALITY_REGIONS`, `MarketPoolService::NATIONALITY_MAP`). `NameGeneratorService`'s actual first/last name text pools deliberately stay in that file (too large to move safely) but are keyed off `Country::nationalityMap()` too, via `NameGeneratorService::nationalities()`, which now derives **every** demonym from the enum with no standalone addendum at all. (`'Polish'` used to be hardcoded in both `NameGeneratorService` and `WorldRegion` because it had a name pool but no `Country` case; adding `PL` removed both copies. `WorldRegionTest` now asserts every `WorldRegion` is reachable from some `Country`, so a region can't go orphaned like `EASTERN_EUROPE` had.) **`CountryContentRegistry::suffixLeadsName()`** is the one place club-name word order is decided: most countries compose `"{place} {suffix}"` (Madrid CF), but Polish is uniformly club-first (`Legia Warszawa`), so `PL` opts in and `NpcClubGenerationService::generateName()` flips the order. Absent means trailing — adding a country never needs to touch it unless its real convention is name-first. Every accessor degrades gracefully (generic placeholder places/`['FC']` suffixes/uniform skin-tone) for a country with no authored entry — see `CountryContentRegistry::missingCategories()`.
- **`GET /api/countries`** (public, cacheable) exposes the same `Country` mapping to clients as `{name: nationality demonym, country: display label, code}` rows — so the client never needs its own copy of this mapping (a real, confirmed drift source: `wunderkind-app`'s own nationality→code table missed newly-added nationalities). Every generated `Player`/`Staff`/`Scout` snapshot (`WorldPackSnapshotBuilder`) also now carries a `countryCode` field alongside `nationality`, derived via `Country::fromNationality()` — same mapping, no client-side lookup needed once a player object is already in hand. See `docs/api/countries.md`.
- **`app:country:bootstrap <CODE>`** chains league generation (all 8 tiers) → NPC club generation (all 8 tiers) → `StarterConfig::leagueAbilityRanges` seeding if missing → `app:pool:warm` → `app:worldpack:warm`, replacing what used to be 1 admin click + 8 admin clicks + 2 separate CLI commands. **`app:country:check <CODE>`** is the read-only audit of what's present/missing (registry content, name pool, league/club/worldpack-cache coverage, starter-config entries) — run it before assuming a country is fully stood up.
- **League financial/trophy/sponsor defaults are tier-only, not per-country**, and are now DB-backed: `GameConfig::leagueTierDefaults` (json, keyed `"1".."8"`, each `{promotionSpots, tvDeal, prizeMoney, leaguePositionPot, sponsorCount, trophyImage, trophyColour}`) replaced the old hardcoded `LeagueService::LEAGUE_TIER_DEFAULTS` const — `LeagueService::generateLeaguesForCountry()` now reads it via `GameConfig::getLeagueTierDefaultsForTier()`, so editing it changes what *every future country's* leagues are generated with. The admin "League Tier Defaults" bulk-edit screen (`/admin?routeName=admin_leagues_overview`, above the per-country league cards, which now default-collapsed) edits this table directly — one row per tier, shared across all countries — and its league-picker (checkbox-per-league + "select all") additionally cascades the submitted values onto whichever **existing** `League` rows are checked, via `LeagueAdminController::bulkEditTierDefaults()`. Covered automatically by `ConfigImportExportService`'s reflection-driven export (new `GameConfig` column, no denial needed).
- The 7 previously-hardcoded admin-template country/nationality lists (2× country `<select>` on the Generate screen, the `enabledCountries` checkbox list, 2× display-only country-label maps on Leagues/World Pack Cache, 2× nationality chip lists on the Player/Staff Pool screens) all now read from `Country::generationCapableLabels()`/`Country::labelMap()`/`NameGeneratorService::nationalities()` passed from their controllers — never hand-edit a country/nationality list in a `.html.twig` file again; a new country or nationality propagates to every one of these automatically.

### Server-Driven Messaging
Operator-authored announcements (`AdminMessage`), separate from the in-game `InboxMessage`
fiction. Full client contract: `docs/api/server-driven-messaging.md`.

- **Targeting reads `Club`; delivery is keyed to `User`.** Cohort axes (reputation, league tier,
  country, week, tutorial state) only exist on `Club`, so eligibility is evaluated against the
  polling club — but `MessageDelivery` is keyed `(user, message)`. That split matters:
  `ClubRepository::findByUser()` returns only the *most recently created* club, so a club-keyed
  delivery row would let a player start a new club and have every active announcement replay.
  Acking therefore needs no club at all.
- **Guests and registered accounts are the same thing here.** Both are plain `User` rows;
  nothing in this system branches on `isVerified()` or the `@guest.buildmyclub.local` domain.
  (Registration still creates a *new* `User` when a guest signs up with a real email, so an
  upgrading guest may re-see a dismissed message — that is an account-linking gap in
  `SyncController::register()`, not something delivery keying can fix.)
- **Delivery-once** is enforced by a `NOT EXISTS` clause in
  `AdminMessageRepository::findCandidatesForClub()` against `MessageDelivery` rows in a terminal
  status. The `uq_message_delivery` unique constraint is **load-bearing**:
  `AdminMessageService::acknowledge()` is a PostgreSQL `INSERT … ON CONFLICT DO UPDATE` against
  it, which is what makes a repeated ack idempotent. Catching
  `UniqueConstraintViolationException` from a `flush()` would not work — Doctrine closes the
  EntityManager on a failed flush.
- **Two-phase targeting.** The SQL query resolves broadcast/direct fully, but only proves that
  *some* audience group qualified. `AdminMessageService::isEligible()` then re-checks each group
  on its own terms in PHP — manual membership included. Skipping that lets a non-member through
  on a message that also carries a dynamic group.
- **`leagueTier` criteria are inverted** — tier 1 is the top division, 8 is where new clubs
  start. `AudienceCriteriaEvaluator` fails **closed**: an unrecognised criteria key makes the
  group match nothing, so an admin typo under-delivers rather than broadcasting to everyone.
- **`bodyHtml` is sanitized on write** by `AdminMessageCrudController::persistEntity()`/
  `updateEntity()` via the `admin_message` named sanitizer
  (`config/packages/html_sanitizer.yaml`, autowired as `HtmlSanitizerInterface $adminMessage`).
  `style`/`class` are stripped so admin CSS cannot bleed into the client theme. The API emits
  the stored HTML verbatim.
- **Admin `json` column fields need generic `Field::new()`**, not `TextareaField` —
  the Text configurator rejects an array value with "can't be converted into a string". See
  `AudienceGroupCrudController::configureFields()`.

### Languages & Translations (backend-owned)

A `Language`/`TranslationKey`/`Translation` system covering two independent domains that
share one storage model but are served to the client in different ways — don't conflate
them:

- **Generic UI copy** (`TranslationKey.entityType === null`) — brand-new strings with no
  existing source of truth. Served as a flat `{_meta, entries}` catalogue,
  `GET /api/translations/{code}` (and `/version` for cheap polling), matching
  `wunderkind-app/locales/<code>/translations.json`'s own shape so the app's existing
  locale-file format and this backend's output are interchangeable. `_meta.pluralSensitiveKeys`/
  `bandedReferences` are structural metadata about which *keys* need special client-side
  handling — identical across every language, not translated values — so they live on
  `TranslationKey`, never duplicated per language.
- **Narrative content** (`GameEventTemplate`/`FacilityTemplate`/`Excursion`/`PlayerArchetype`
  text — `entityType` set) — **localized in place** on those entities' own existing
  endpoints (`/api/events/templates`, `/api/excursions`, `/api/game-config`,
  `/api/archetypes`) via a `?lang=` param, **not** exposed through the catalogue above. This
  was a deliberate choice over routing narrative text through synthetic catalogue keys: it
  means none of the client's existing rendering code for these screens needs new lookup
  logic, only to pass the current language through. Full client contract:
  `docs/api/translations.md`. `PlayerArchetype.name` is the one translatable field that also
  feeds a cache-busting version hash (`PlayerArchetypeRepository::findAllWithVersionHash()`,
  same pattern `ExcursionRepository::findActiveWithVersionHash()` uses) — it was previously
  missing from that hash entirely (only `description` was, after an earlier fix), which
  `?lang=` support would have made a real bug: two languages differing only in `name` would
  have hashed identically.
- **The default (EN) value for a narrative field is never duplicated into a stored
  `Translation` row — it is always read live off the owning entity**
  (`GameEventTemplate::getTitle()` etc.) via `NarrativeTranslationService::getFieldValue()`.
  A stored EN mirror would go stale the instant an admin edits the entity through its normal
  CRUD form — the same class of bug `NarrativeFacilityTemplateRoundTripTest`'s docblock
  documents for `FacilityTemplate`'s own export/import split (`baseConstructionWeeks`
  exported and never read back). Generic UI-copy keys have no live source, so their EN
  values *are* ordinary stored rows, like any other language.
- **Explicit typed link columns, not key-string parsing.** `TranslationKey.entityType`/
  `entitySlug`/`fieldName` are the real link to a narrative field; `key` itself (e.g.
  `narrative.excursion.team_bonding.title`) is a derived, human-readable label only, never
  parsed to find the association — same precedent as `player_1.morale` vs bare
  `player.morale` elsewhere in this file: explicit slots over string convention.
  `NarrativeTranslationService::TRANSLATABLE_FIELDS` is the hand-maintained registry of
  which fields on each entity type are translatable (most columns — `slug`, `category`,
  `cost` — are deliberately not).
- **Admin editing**: each of `GameEventTemplateCrudController`/`FacilityTemplateCrudController`/
  `ExcursionCrudController`/`PlayerArchetypeCrudController` gets a row-level "Translations"
  action (`NarrativeTranslationController`) — a grid of translatable fields × enabled
  languages, EN shown read-only (edit it on the entity's own form instead). Generic UI-copy keys get
  their own CRUD screens (`LanguageCrudController`, `TranslationKeyCrudController`,
  `TranslationCrudController`). `TranslationKeyCrudController`'s index is grouped by key with
  one green/red presence icon column per enabled language (computed virtual columns, not real
  ones — see the EasyAdmin gotcha below), rather than EasyAdmin's default one-row-per-entity
  grid, plus an `IncompleteTranslationFilter` (`src/Filter/`) toggle narrowing to keys missing
  at least one *enabled* language — a correlated-subquery count comparison
  (`COUNT(DISTINCT translated-and-enabled languages) < COUNT(enabled languages)`), not a real
  column, so it stays correct as languages get enabled/disabled. Its own edit page
  (`admin/translation_key_edit.html.twig`, overriding `main` — same "override one block"
  technique as `UserCrudController`'s edit page, just below the form instead of above it) adds
  a per-language textarea + Save button grid, each saving independently over AJAX
  (`TranslationKeyCrudController::saveLanguage()`) rather than through the page's own form —
  a blank save clears that language's value (same convention as
  `NarrativeTranslationService::saveTranslations()`). Plus a bulk import/export screen
  (`/admin/translations/content|export|import`, mirroring `ConfigImportExportService`'s own
  trio) whose export format *is* the public catalogue shape — the app's own locale file can
  be uploaded there close to verbatim as the initial `en` seed. That screen's optional
  "clear existing" checkbox is scoped to **one language's generic values only** — never a
  full wipe like the narrative import's own checkbox — because a bulk import always carries
  just one language's file; clearing every language would destroy unrelated languages' work
  as collateral damage. `TranslationRepository::deleteGenericForLanguage()` deletes that
  language's rows first, then `TranslationKeyRepository::findGenericOrphaned()` prunes any
  key left with zero translations in *any* language (a key still holding a value elsewhere
  is never touched).
  `NarrativeImportExportService`'s export/import additionally carries a `translations`
  section (non-default-language values only, for the four narrative types) so a whole
  language's worth of narrative translations can move with a narrative-content backup —
  bumped `EXPORT_VERSION` to `3` for this, then to `4` when `Excursion` joined the service's
  own base-content export/import below.
- **`Excursion`'s own base content (title, body, cost, etc. — not just its translations) is
  also now part of `NarrativeImportExportService`'s bulk export/import**, via
  `Excursion::toArray()` (same self-verifying convention `FacilityTemplate::toArray()`
  already used) + `exportExcursions()`/`upsertExcursion()`. Previously Excursion was the one
  "narrative content" entity with no bulk JSON path at all — only `app:seed-excursions`
  (hand-maintained PHP array, still independent and unaffected) and direct admin edits
  touched it. Joining this service means `clearAll()` now wipes `Excursion` rows and purges
  its `TranslationKey`s too — a deliberate reversal of that method's previous explicit
  exclusion of Excursion (its old reasoning — "clearAll() never touches Excursion rows, so
  purging would orphan live translations" — no longer applies once Excursion is fully
  round-trippable like the other three).
- **`GameEventTemplate`/`PlayerArchetype`/`TacticalAdvantage` still export via hand-written
  field lists** (not a `toArray()` method) inside `NarrativeImportExportService` — verified
  complete for every currently-persisted column, but with no reflective test guarding
  against the next field silently going the way `FacilityTemplate`'s
  `baseConstructionWeeks` once did. `NarrativeFieldCoverageTest` closes that gap for
  `GameEventTemplate`/`PlayerArchetype` (reflects each entity's ORM columns against one
  exported row); `TacticalAdvantage` is skipped deliberately — 3 trivial fields, and no
  unique business key a test fixture could clean up without risking a real catalogue row.
- **Don't cache a `TranslationKey`'s children via `$key->getTranslations()`.** That inverse
  `OneToMany` collection is only populated by Doctrine's hydrator for a `TranslationKey`
  loaded *from* the database — one created earlier in the same request (e.g. by
  `ensureKeyFor()` during a save) has its `Translation` children persisted via the owning
  side only, and the inverse collection is never retroactively updated. Query via
  `TranslationRepository::findAllForKey()` instead; this bit `NarrativeImportExportService`'s
  own `exportTranslations()` during manual testing — silently returning zero rows for data
  that definitely existed. Relatedly: don't give `NarrativeTranslationService::ensureKeyFor()`
  an instance-level result cache either — it goes stale the moment something else deletes the
  row it points to, then feeds Doctrine a detached-entity reference on the next call
  (`ORMInvalidArgumentException`, confusingly far from the real cause). The one caller that
  needs duplicate-protection (`saveTranslations()`, saving the same not-yet-existing field for
  two languages in one call) dedupes with a local, call-scoped map instead — see its own
  docblock.
- **`app:seed-default-language` only acts when the `language` table is completely empty** —
  it creates the single bootstrap `en`/enabled/default row and is a permanent no-op
  afterward. Deliberately *not* an upsert-by-code like `app:seed-excursions`: an
  upsert-on-every-deploy model would permanently stomp an admin's later choice of a
  different default language. Paired with `app:backfill-narrative-translations`
  (idempotent, ensures every existing event/facility/excursion/archetype row's
  `TranslationKey`s exist — cheap, both run on every deploy). Archetype translations
  survive `app:seed-archetypes`' own truncate-and-reseed-by-slug behavior unscathed: the
  link is by slug *string*, not a DB foreign key to the (truncated, re-autoincremented)
  `PlayerArchetype` row, so a reseed never orphans them.

### Periodic Leaderboard Awards (backend-owned)

An operator-configured prize for topping a leaderboard over a period — weekly, monthly or
quarterly, to a configurable number of places — plus an immutable archive of every closed
period. Client contract: `docs/api/leaderboard-awards.md`; the reading side is in
`docs/api/leaderboard.md`. **No new API endpoint**, and both inbox endpoints are unchanged.

- **A period board is a GROWTH board, not a standing**, and that is the whole design.
  `LeaderboardEntry.score` has never been an accumulator: every writer re-reads a cumulative
  total and stores it absolutely (`SyncService` writes `Club::getReputation()`,
  `computeAggregateScores()` writes career totals). So **deleting rows at a period boundary
  resets nothing** — the next sync and the next five-minute cron tick put the identical
  numbers straight back. The existing weekly board is the proof: it has always got a fresh
  ISO-week key and always shown all-time career goals. Only a baseline resets a score.
- **`LeaderboardEntry::observeCumulative()` is the single writer for every non-`all-time`
  row**, and keeps three numbers: `baselineScore` (the cumulative at first sighting in this
  period), `cumulativeScore` (the truthful cumulative) and `score = max(0, cumulative -
  baseline)` (the growth the board ranks). `setScore()` is for `all-time` only. **Only the
  cumulative chains between periods**, so growth never compounds its own clamping, and the
  `max(0, …)` is a product rule rather than a safety net — `score` is `bigint unsigned` and
  growth genuinely goes negative, because `club_reputation` decays daily. Keeping the
  cumulative separately is exactly what stops the clamp corrupting the next baseline.
  Converting a new writer and forgetting one is the live hazard here:
  `DecayReputationCommand::syncLeaderboardScore()` touches **every** existing period row for
  a club, so left on `setScore()` it writes a standing where a growth belongs.
- **The baseline lives on the live period row**, not in a baseline table and not on the
  archive. `leaderboard_entry` already holds exactly one row per `(club, category, period)`
  — the right grain — so two columns add **zero rows**. The archive would be wrong: it is
  bounded to a display top-N, while a baseline is needed for every eligible club.
- **Baselines are stamped lazily**, at first observation inside the period (so up to one
  cron tick or one sync of drift at a boundary — ~0.01% of a month, accepted). That is what
  removes the pre-seeding pass, the baseline table, the seed archive and the "first period
  has no baseline" case. It also closes the disable→re-enable hazard **structurally**:
  `ActivePeriodService` stops emitting a disabled definition's key, so no rows exist, so on
  re-enable growth is measured from the re-enable and **a long-disabled definition cannot
  retro-award the all-time leader**.
- **`senderType` on the award's inbox message must stay `COMPETITION`.** It reads like
  system mail and it is not. `InboxService::acceptMessage()` routes **only** `COMPETITION`
  and `FRIENDLY` to `acceptCompetitionReward()`; a `SYSTEM` message carrying
  `offerData.effects` renders `requiresAccept: true` in
  `InboxController::serializeMessage()` and then hits `default => null` — **the player taps
  Accept and gets nothing, with no error anywhere.** And do **not** add a new
  `MessageSenderType` case: it needs no migration, so the cost is not schema, it is
  contract — a case a shipped client has never seen changes how the message renders. The
  discriminator is `offerData.type = 'LEADERBOARD_AWARD'`, which is the field clients key
  off anyway. `LeaderboardAwardDeliveryServiceTest` pins this **end to end**, by feeding the
  delivered message through the real `acceptMessage()` and asserting the balance moved — a
  `senderType` assertion alone would pass a rename to `SYSTEM`.
- **`effects` sits at the TOP level of `offerData`**, load-bearing twice over:
  `acceptCompetitionReward()` reads `$offerData['effects']`, and `serializeMessage()`
  derives `requiresAccept` from its non-emptiness.
- **No `UserLedger` row, ever.** That ledger's before/after chain is a dividend-draw
  (club→user transfer) record; a prize paid into a club balance is not a user-level balance
  change, and inserting one would corrupt `UserLedgerRepository::getCurrentBalance()` and
  the landing page's Rich List. The delivery test counts rows before and after to pin it.
- **Nothing mutates `Club` server-side.** Same reason as `RewardApplierService`:
  `SyncService::process()` does an absolute `Club::setBalance($request->balance)`, so a
  server-side `addFunds()` on the cron's schedule would just be overwritten by the next
  sync. The accept path is what makes that overwrite correct.
- **The archive header carries the claim, and the claim is raw.**
  `uq_leaderboard_period_archive_category_period` is a **plain** unique (both columns NOT
  NULL — which is why `category`/`cadence` are denormalised onto the header rather than
  keying on the nullable `definition_id`), and `LeaderboardPeriodCloseService::claim()`
  inserts `pending` through raw DBAL with an un-targeted `ON CONFLICT DO NOTHING`. Raw
  specifically because a `UniqueConstraintViolationException` out of `flush()` **closes the
  EntityManager**, which in a cron tick would strand every remaining definition. It
  auto-commits on its own, deliberately **outside** any wrapping transaction, so the claim
  survives a later failure rather than silently re-opening the period.
- **`claimedAt` is a lease, not a flag** — `CLAIM_LEASE_SECONDS = 300`, the same constant
  name and value as `CompetitionRound`. Reclaimed by a conditional `UPDATE … WHERE status =
  'pending' AND claimed_at < :reclaimBefore`, after which the abandoned attempt's partial
  entries are deleted wholesale (they are incomplete by definition, and discarding them is
  what lets the entry rewrite use the plain ORM without tripping `uq_lpae_archive_rank`).
- **Never commit a status ahead of the work it describes.** The archive's terminal status
  *and* the definition's `lastClosedPeriodKey` anchor are set on the entity, in the one
  flush that also writes every entry and every inbox message. The claim row is the gate; the
  anchor is the record. `LeaderboardPeriodCloseServiceTest` asserts that a failure during
  delivery leaves the archive `pending`, the anchor unmoved **and the live rows unpruned**.
- **The prune runs only after that flush has committed** — the `*_squad` precedent.
  Committing a delete before a failed flush would clear the board with nothing archived.
- **A closed period is read-only, and this must never be removed.**
  `LeaderboardCalculationService::getLeaderboard()`'s cold callback *writes* (it calls
  `recalculate()`, and `computeAggregateScores()` will upsert rows for any period string at
  all), so after a close prunes `period = '2026-M10'`, one public
  `GET /api/leaderboard/golden_boot?period=2026-M10` would resurrect the entire pruned board
  plus a junk row per club. Both doors are shut: the cold callback projects the archive
  instead, and `recalculate()` returns 0 early so neither the admin button nor the cron can
  resurrect one. A `pending` archive deliberately does **not** make a period read-only — its
  live rows are unpruned and still the truthful answer.
- **Due-ness is two parts, both required.** Catch-up from the anchor, **oldest first, one
  period per pass**, so a cron outage settles each missed period in order rather than
  silently skipping two months — *and* the chosen period must actually have ended, so clock
  skew or a stray `--force` cannot close a period that is still open.
- **Inline in the cron tick: no Messenger transport, no message class.** Per due definition
  the work is one indexed read, ≤100 entry inserts and ≤N messages, and the due count is
  bounded by the enabled definitions (≤12 categories × 3 cadences). That is nothing like a
  32-fixture match-engine round, which is what justified the `competition` transport. If the
  archive write ever has to cover tens of thousands of rows per definition per tick, split
  it onto its own message with a re-read clamp (the `TopUpStarterPoolMessageHandler`
  pattern). The only queued part is the push.
- **Spoof clubs are excluded from leaderboards as of this work** —
  `LeaderboardEntryRepository::applyEligibility()`, shared by the board read and the award
  read. A visible public behaviour change (spoof clubs vanish from live boards, shifting
  `total` and every rank below them), taken because paying a bot real money is the actual
  bug and because a prize paid to a club the board does not list is indefensible either way
  round. `findAllForCategoryAndPeriod()` stays deliberately unfiltered — the stale-score
  zeroing pass must see every stored row.
- **Cron: `docker/leaderboards-close-periods.sh`, every 10 minutes at :04.** Its schedule is
  the **tick granularity, not the award cadence** — cadence is per-definition and set in the
  admin. No crontab under Lando, so locally use
  `app:leaderboards:close-periods --force [--dry-run]`, or the **Close now** panel on a
  definition's detail page.

### Two Firewalls
- **`api`** — stateless JWT, covers `/api/*`; role `ROLE_CLUB` for game clients
- **`admin`** — session form_login, covers `/admin`; role `ROLE_ADMIN`

Symfony `RouterListener` runs at priority 32, `FirewallListener` at priority 8 — the router runs first. `json_login`'s `check_path` **must** be a real registered route or the router returns 404. The stub route in `SyncController::login()` exists for this reason.

### EasyAdmin Custom Routes

Rule + example live in `src/Controller/Admin/CLAUDE.md` (auto-loaded when working in that
directory): custom admin routes must always redirect through EasyAdmin's entry point.

### Key Gotchas
- **PostgreSQL** — migrated from MySQL 8.0. New migrations must use Doctrine Schema API or PostgreSQL syntax (no `AUTO_INCREMENT`, no `ENGINE=InnoDB`).
- **`rank`** is a reserved SQL word — `LeaderboardEntry` uses column name `rank_position`.
- **`hallOfFamePoints`** is **server-derived, not client-supplied** — `Σ GameConfig::$leagueWinPoints[tier]` over `SeasonRecord` rows with `finalPosition = 1` (`HallOfFameScoreService`). `SyncRequest::$hallOfFamePoints` is accepted but ignored. Recomputed in `LeagueService::concludeSeason()` and by `app:leaderboards:generate`; mirrored onto `Club::$hallOfFamePoints` so `/api/club/status` matches the board. **`reputation`** floors at 0. **`totalCareerEarnings`** adds deltas.
- **Leaderboard scores** are absolute values from Club state at sync time, not running sums.
- **Doctrine JSON dirty-check** — when a `json` column stores mixed PHP string/int types, Doctrine silently skips the UPDATE. Bypass with:
  ```php
  $em->getConnection()->executeStatement('UPDATE ... SET col = :val WHERE id = :id', ['val' => json_encode($data), 'id' => $id]);
  ```
- **Player deduplication** — `StarterPackService` uses `spl_object_id()` (not `array_unique(SORT_REGULAR)`) to deduplicate pool results. Doctrine returns the same PHP object for the same DB row; `array_unique` with `==` comparison is unreliable on entity proxies.
- **EasyAdmin admin grant**:
  ```bash
  lando psql -c "UPDATE \"user\" SET roles = '[\"ROLE_ADMIN\"]' WHERE email = 'you@example.com';"
  ```
- **Adding a persisted field is not done until it round-trips through Import/Export.** Three admin screens back up and restore domain data, and a field the service doesn't carry is silently lost on restore — the import reports no error, the value just comes back at its entity default. `ConfigImportExportService` (`GameConfig`, `StarterConfig`, `PoolConfig`) is **reflection-driven**: it walks every `#[ORM\Column]` property, so a new config field is covered automatically and the only decision is whether it belongs on `ConfigImportExportService::DENIED_PROPERTIES` (secrets and runtime state — the export file is documented to admins as safe to commit). `NarrativeImportExportService` and `LeagueImportExportService` are still hand-maintained lists that must be edited on **both** sides. All three are guarded by coverage tests (`tests/Service/ConfigImportExportCoverageTest.php`, `NarrativeFacilityTemplateRoundTripTest.php`, `LeagueImportExportRoundTripTest.php`) that fail when an entity gains a column the service doesn't handle — so a red build here means the export needs updating, not the test. `NarrativeTranslationRoundTripTest.php` guards the newer `translations` section the same way, but narrower — it only asserts every field named in `NarrativeTranslationService::TRANSLATABLE_FIELDS` has a working getter/setter, not a full column sweep (most columns on those three entities are deliberately not translatable).
- **`GameEventTemplate` JSON is client-interpreted, and the client is fussy.** The backend stores and serves `impacts`, `firingConditions`, `chainedEvents` and `severity` verbatim — nothing validates them, so a wrong key is not an error, it is an event that quietly does nothing. Three traps: `impacts` has **two shapes**, and the `player_reputation`/`player_milestone`/`player_morale`/`player_form` categories read **only** `{"stat_changes": [...]}` (a flat `[{target, delta}]` array on those fires the message and applies nothing); a legacy target **must carry its slot number** (`player_1.morale`, never `player.morale` — the client's entity map has no bare `player` key); and setting `firingConditions` at all **removes the template from the weekly random roll**, so a shape no evaluator claims makes the event permanently dead. Only eight personality traits exist (`determination, professionalism, ambition, loyalty, adaptability, pressure, temperament, consistency`, 1–20) — any other name is silently dropped. Full reference: `docs/event-guide.md`, mirrored in the admin help on `GameEventTemplateCrudController`. `app:events:repair` fixes existing rows; the seeders skip existing slugs unless you pass `--update`.
- **EasyAdmin custom form type on a `json`/array column** — a `Field::new('col')->setFormType(MyType::class)` where `col` is a Doctrine `json` type gets auto-configured by EasyAdmin as a collection, which injects `CollectionType` options (`allow_add`, `entry_type`, …) onto your form type and throws `The options ... do not exist`. Tolerate them in the type's `configureOptions()`: `$resolver->setDefined(['allow_add','allow_delete','delete_empty','entry_options','entry_type'])`. To render a fully custom widget for such a compound type, register a form theme via `$crud->addFormTheme(...)` (singular) and define a `{% block <blockPrefix>_widget %}` block (block prefix = the type class minus `Type`, snake_cased; `AppearanceType` → `appearance`). See `AppearanceType` + `templates/admin/form/appearance_theme.html.twig`.
- **A public GET on `/api/leaderboard/{category}` can WRITE, and is guarded in two places.** `getLeaderboard()`'s cold-cache callback calls `recalculate()`, and `computeAggregateScores()` will upsert rows for *any* period string — so an arbitrary `?period=` would mint a cache key and populate a whole junk board. The controller short-circuits anything failing `LeaderboardPeriod::isValidKey()` to the **same empty 200** it already returned (permissive by design — strict validation would be a contract change and could 400 a live client), and a **closed** period is projected from its archive instead of recomputed. Removing either guard lets one request resurrect a pruned board.
- **EasyAdmin virtual/computed index columns (a field with no real getter) need a dummy `->setValue(...)` placeholder, or `formatValue()` is silently ignored.** `CommonPreConfigurator` tries `PropertyAccessor::getValue()` for any field whose `getValue()` is still `null`; on failure it renders the "inaccessible" error template *regardless* of what `formatValue()` later computes — the check is "does `field.value` happen to be non-null already," not "is there a formatValue callable." Give the field any non-null placeholder (`->setValue('')`/`->setValue(false)`) to skip that path, then compute the real per-row value in `formatValue(fn ($value, $entity) => ...)`. Separately: **`BooleanField`'s own index/detail template reads `field.value` directly, never `field.formattedValue`** — unlike most fields (`TextField` renders `{{ field.formattedValue|raw }}`), so a virtual `BooleanField` can never show a computed value no matter how it's wired; use `TextField` instead and return icon HTML (`<i class="fa fa-check-circle text-success">`) from `formatValue()` — a string return is auto-wrapped in `Twig\Markup`, so it renders unescaped. See `TranslationKeyCrudController`'s per-language presence columns.

## API Endpoints

Full endpoint table (method, path, auth, description): `.context/stages/04_interfaces/output/routes.md`.
Admin UI is at `/admin` (session-based, `ROLE_ADMIN`).

## Key Services

| Service | Responsibility |
|---|---|
| `SyncService` | Sync processing, anti-cheat, leaderboard upsert, manager trait shifts |
| `EconomicService` | Financial year-end, sponsor contracts, player market value |
| `InboxService` | Generate and respond to inbox offers (sponsors, investors) |
| `MarketPoolService` | Generate and assign market entities; Player/Staff assign deletes entity and returns snapshot |
| `MarketDataService` | Serve market data to the client |
| `ClubInitializationService` | Create Club entity, set paName + manager traits, abbreviation |
| `StarterPackService` | Pull starting Player/Staff/Scout from pool; build snapshots; delete consumed Player/Staff |
| `PlayerGenerationService` | Procedurally generate a `Player` from position and source, including its `PersonalityProfile`. (Despite the name it does **not** read `PlayerArchetype` — archetypes are classified client-side.) |
| `AppearanceGeneratorService` | Deterministic sprite-config generation from `(id, role, age, nationality?)`; paired with `AppearanceLifecycleSubscriber` (prePersist auto-fill) — see Avatar Appearance above |
| `ArchetypeResolverService` | Admin-only preview of the dual (positive + negative) archetype a personality matrix resolves to — mirrors the documented client-side formula; see PlayerArchetype below |
| `PersonalityGeneratorService` | Rolls the 8-spoke Personality Matrix via the mould pipeline; shared by Player, Staff and Scout, which differ only in the `PersonalityContext` they pass. Paired with `PersonalityLifecycleSubscriber` (prePersist auto-fill for Staff/Scout) — see Personality Matrix above |
| `NpcClubGenerationService` | Generate NPC clubs with names, colors, facilities, ability by tier, and a kit+badge identity |
| `CountryContentRegistry` | Static per-country content (places, club-name suffixes, stadium formats, world region, business cluster), keyed by `Country` — see World Generation above |
| `TrophyConfigFactory` | The one place a trophy config is defaulted, validated and migrated (all-static, like `CountryContentRegistry`); fronts the admin forms, the world import and the migration backfill, and owns the legacy `trophy-N`/`gold_silver` mapping — see Trophy Identity above |
| `WorldInitializationService` | Build the full league pyramid + tier pack snapshot for a client; snapshot builders for Player/Staff/Scout |
| `LeagueService` | Assign clubs to leagues, conclude seasons, roll league sponsors |
| `FixtureGenerationService` | Generate match fixtures for a league season |
| `TransferLeaderboardService` | Rank players by transfer fee across clubs |
| `WorldPackCacheService` | Cache country/nationality worldpack data (`CountryWorldPackCache`) |
| `NameGeneratorService` | Procedural name generation for players and PA personas |
| `EmailVerificationService` | Send and validate email verification / password reset tokens |
| `ConfigImportExportService` | Export/import `GameConfig`, `StarterConfig`, and `PoolConfig` rows as JSON |
| `LeagueImportExportService` | Export/import `League` + `NpcClub` world data (used for admin-driven world pack management) |
| `NarrativeImportExportService` | Export/import event templates, facility templates, player archetypes, `TacticalAdvantage` rows, and (non-default-language only) narrative `translations` |
| `AdminMessageService` | Resolve pending announcements for a club, cap the payload, upsert acknowledgements, sanitize admin HTML |
| `AudienceCriteriaEvaluator` | Evaluate a DYNAMIC `AudienceGroup`'s JSON criteria against a `Club`, live at poll time |
| `DebugLoggingResolver` | The one place the in-app debug log panel's effective state is decided — `global GameConfig switch \|\| the account's own User flag`, additive only; all-static like `TrophyConfigFactory`. Shared by `GET /api/game-config` and `GET /api/club/status` so the two can't disagree — see Key Entities' `User` entry |
| `StarterPoolAuditService` | Sweeps every enabled country's starter-pack verdict and queues bounded pool top-up chunks (`pool` transport) for the ones that fall short — never for a shortfall generation cannot fix; see Starter Pool Audit above |
| `UserLedgerService` | Detect `dividend_draw` entries in the sync ledger and centralize them onto `UserLedger`, chaining before/after balance across clubs — see Centralized User Ledger above |
| `NarrativeTranslationService` | Find-or-create `TranslationKey`s for narrative fields; builds the per-language localization map the `?lang=` endpoints read; EN is always read live off the entity, never stored — see Languages & Translations above |
| `TranslationCatalogueService` | Builds the generic UI-copy `{_meta, entries}` catalogue (`/api/translations/{code}`), shared by the public endpoint and the admin bulk-export |
| `TranslationCatalogueImportExportService` | Bulk import/export of the generic UI-copy catalogue, one language at a time — a separate domain from `NarrativeImportExportService`'s own `translations` section |
| `LeaderboardPeriod` | The one place a leaderboard period key is produced, parsed, labelled or walked (all-static, like `TrophyConfigFactory`). Weekly/monthly/quarterly; the weekly key is byte-identical to the ISO-week key the game has always written — see Periodic Leaderboard Awards above |
| `ActivePeriodService` | Which periods get `leaderboard_entry` rows written right now: `all-time` + the current ISO week always, plus each **enabled** award definition's current key. The gating is what makes a disable→re-enable unable to retro-award |
| `LeaderboardPeriodCloseService` | Closes an ended period: claim (raw `ON CONFLICT`, leased), freeze the board, award each place, one flush, then prune. Never commits a status ahead of its work |
| `LeaderboardAwardDeliveryService` | Turns a frozen archive row into a claimable inbox prize via the unchanged accept path. **`senderType` must stay `COMPETITION`** — see Periodic Leaderboard Awards above |
| `PeriodAwardsBoardService` | The landing page's `#period-awards` rows. No snapshot row and no cache, because the archive already *is* the cron-written denormalised row |

## Key Entities (non-obvious fields)

- **User** (game account, distinct from `Admin`) — `email`, `password`, `roles`, `clubs` (OneToMany); owner identity: `name`, `nationality`, `gender`, `dob`, `appearance` json — see Owner Identity above. **`debugLoggingEnabled`** (bool, default false) is a per-account override for the in-app debug log panel, **additive to** the global `GameConfig::$debugLoggingEnabled`: `DebugLoggingResolver::resolve()` serves `global || per-user` under the unchanged `debugLoggingEnabled` key, so it can only switch the panel ON while the global switch is off and can never switch it OFF for an account the global switch covers. **Two endpoints carry the key and must not drift** — `GET /api/game-config` (public, so the per-user half only applies when the client sends its Bearer token; anonymous callers get the global value alone, and the response is `Cache-Control: private` because one key now varies per account) and `GET /api/club/status` (`ROLE_CLUB`, so the override always applies — the reliable read for a signed-in client, and the reason the mirror exists). Client contract: `docs/api/debug-logging.md`. `getUserIdentifier()` returns email; no uniqueness constraint on the `Club` FK (a user can own more than one club — `ClubRepository::findByUser()` resolves the most recent; `ClubRepository::findAllByUser()` returns every club they own).
- **UserLedger** — centralized, cross-club financial audit trail for one `User`; currently only `DIVIDEND_DRAW` entries (`UserLedgerEntryType`). `amountPence` (signed pence, corrected from the ledger's 100x-inflated raw value), `balanceBeforePence`/`balanceAfterPence` (running cross-club balance snapshot per row), `occurredAt` (in-game date) vs `recordedAt` (real-world date). `ManyToOne` → `user:User` (not nullable, `CASCADE`), `club:Club` (not nullable), `sourceSyncRecord:?SyncRecord` (nullable, `SET NULL`). See Centralized User Ledger above.
- **Club** — `reputation`, `totalCareerEarnings`, `hallOfFamePoints`, `lastSyncedWeek`, manager traits (`temperament`/`discipline`/`ambition` 0–100 clamped setters), `paName`, `financialYearStart`, `balance`, `country`, `abbreviation`
- **Player** — `position` (PlayerPosition), `status` (PlayerStatus), `recruitmentSource`, `currentAbility`, `potential` (hard-capped, `currentAbility ≤ potential`); embeds `PersonalityProfile` (8 traits 0–100); ManyToMany self-ref siblings; nullable `?Agent $agent` FK (many players → one agent; assigned in `MarketPoolService` and reassigned at world-pack generation; surfaced in every player snapshot — see Player↔Agent Association); `appearance` json (see Avatar Appearance). **No club FK** — pool entity, deleted on consume.
- **Staff** — `role` (StaffRole), `coachingAbility`; `appearance` json; embeds `PersonalityProfile` (8 traits 1–20; `MANAGER`/`COACH` carry role floors). **No club FK** — pool entity, deleted on consume.
- **Scout / Agent** — pool entities (`Scout` no club FK, not deleted on assign); both carry `appearance` json. `Scout` also embeds `PersonalityProfile` (floored on adaptability/consistency); `Agent` does **not**. Note `Scout`/`Agent` use a single `name` field, not `firstName`/`lastName`.
- **PlayerArchetype** — curated catalogue of 20 personality archetypes, `polarity` (`ArchetypePolarity`: positive/negative, 10 each) + unique `slug` + `traitWeights` (json). Classification is **client-side**: the backend is a definitions catalogue only — there is no archetype FK on `Player`, and the client resolves one positive and one negative per player. `traitWeights.formula` keys must be exactly the eight `PersonalityProfile` fields and weights are **signed** (positive = "High trait", negative = "Low trait", absolute values sum to 1.0); traits are stored 1–20 and the client normalises to 0–100 before comparing to `threshold`. Seeded via `app:seed-archetypes` (truncates first), which now runs on both deploys. `ArchetypeResolverService` is an **admin-only** read-only mirror of that client-side scoring — it powers the resolved-archetype panel on the Player/Staff edit pages and changes nothing about gameplay; if the formula ever changes client-side, change it there too. `name`/`description` are translatable (see Languages & Translations) — `GET /api/archetypes?lang=` localizes them in place, same convention as events/facilities/excursions.
- **League** — `country`, `tier` (1–8), `promotionSpots`, `tvDeal`, `prizeMoney`, `leaguePositionPot`, `sponsorCount`, `trophy` json (nullable; the trophy sprite's config — see Trophy Identity above, and note `null` means "awards no trophy"); has `LeagueSponsor` collection. Financial/trophy/sponsor values at creation come from `GameConfig::getLeagueTierDefaultsForTier()` (tier-only, shared across every country) — see World Generation above.
- **NpcClub** — `country`, `tier`, `reputation`, `balance`, `stadiumName`, `primaryColor`/`secondaryColor` (synced from `identity.home`, not independently admin-editable — see below), `playingStyle`, `financialApproach`; grouped into leagues for the world pack. `identity` json (nullable, see Kit & Badge Identity below) — home kit + away kit + club badge
- **FacilityTemplate** — canonical slug shared with frontend; `category` (TRAINING/MEDICAL/SCOUTING), `baseCost`, `weeklyUpkeepBase`, `matchdayIncome`, `matchdayIncomeMultiplier`; seeded via admin
- **GameConfig** — singleton row; all global gameplay constants (XP rates, injury chances, wage multipliers, attendance formulas, `leagueTierDefaults` — whose rows normalise through `normaliseTierRow()` on read and write, see Trophy Identity above — etc.); every `#[ORM\Column]` is exported by `ConfigImportExportService` unless denied
- **StarterConfig** — singleton row; league player ability ranges + fan base growth curves; JSON dirty-check workaround applies here; covered by `ConfigImportExportService` reflection
- **LeaderboardEntry** — UNIQUE(club, category, period); `rank_position` column (not `rank`). `baselineScore`/`cumulativeScore` (both nullable) make every non-`all-time` period a **growth** board via `observeCumulative()` — see Periodic Leaderboard Awards above. `all-time` keeps `setScore()` and leaves both NULL
- **LeaderboardAwardDefinition / LeaderboardAwardPlacement** — admin config: which chart awards, at what cadence, to how many places, with what prize. `isEnabled` defaults **false**; UNIQUE(category, cadence) is load-bearing (it is what makes the archive's claim key sound); there is no placements-count column because the highest configured `place` *is* the depth. `lastClosedPeriodKey` is a monotonic runtime anchor, hidden on the admin form
- **LeaderboardPeriodArchive / LeaderboardPeriodArchiveEntry** — immutable history, one header per closed period plus its frozen board. The header **carries the close claim** (plain UNIQUE(category, period_key), which is why category/cadence are denormalised) and `claimedAt` is a 300s lease. The entry row doubles as the award record, denormalises `club_id`/`user_id` with **no FK** so it survives club and account deletion, and freezes kit/badge/country/`displayLabel` so the landing page still renders a deleted club. `inbox_message_id` is a plain string, not a FK
- **GameEventTemplate** — narrative event definitions; `impacts`/`firingConditions`/`chainedEvents` are client-interpreted JSON edited as raw JSON in the admin (see Key Gotchas and `docs/event-guide.md`); `severity` is only read on the `NPC_INTERACTION` path
- **InboxMessage** — `senderType` (MessageSenderType), `offerData` (json), `status` (MessageStatus)
- **Transfer** — fee + agentCommission in pence/cents; `getNetProceeds()` helper; `occurredAt` (client) + `syncedAt` (server); `player_id` is `ON DELETE SET NULL`
- **MatchResult** — per-club match record; `goalsFor`, `goalsAgainst`, `week`, `season`, `fixtureId` (unique), `opponentClubName`, `isHome`, `homeGoals`, `awayGoals`, `round`, `playedAt`, `yellowCards`; FK to `Club`
- **TacticalAdvantage** — matchup table row: `style` vs `opponentStyle` (both `PlayingStyle`) → `multiplier` (float); seeded via `NarrativeImportExportService`
- **Admin** — separate admin user entity (`UserInterface`); `email`, `password`, `name`, `department`, `accessLevel`; always `ROLE_ADMIN`; created via `app:admin:create`
- **BetaRequest** — beta-access waitlist entry; `email`, `code`, `valid`, `attempts`, `expiresAt`, `verifiedAt`; verified via `/api/beta-request/verify`
- **PoolConfig** — per-country/tier configuration for how many entities to pre-warm in the pool; covered by `ConfigImportExportService` reflection
- **AdminMessage / AudienceGroup / AudienceGroupMember / MessageDelivery** — server-driven messaging; `MessageDelivery` is keyed `(user, message)` while targeting reads `Club` (see Server-Driven Messaging)
- **SeasonRecord / SeasonSnapshot / SeasonRatingsSnapshot** — historical season data persisted at `conclude-season`
- **Language** — admin-configured language row; `code` (2-letter, treated immutable once shipped), `isEnabled`, `isDefault` (exactly one at a time, enforced in `LanguageCrudController`, not a DB constraint), `sortOrder`
- **TranslationKey** — a translatable string. `entityType`/`entitySlug`/`fieldName` (all null = generic UI-copy key; all set = linked to one narrative field — explicit columns, never parsed from `key`); `isPluralSensitive`/`bandedReferences` (structural, per-key, language-independent). `UNIQUE(key)` and `UNIQUE(entityType, entitySlug, fieldName)` — the latter relies on Postgres treating `NULL`s as distinct so multiple generic rows never collide. `OneToMany` → `translations` (cascade remove, `orphanRemoval: true`) — see Languages & Translations above for why that collection must never be read directly off an in-memory instance.
- **Translation** — one language's value for one `TranslationKey`. `UNIQUE(translationKey, language)`; an empty string is a deliberate value, "untranslated" is the absence of a row.
