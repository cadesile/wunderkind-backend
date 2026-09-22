# Structure — Module Boundaries

See `.context/shared/stack.md` for app-directory/stack facts — not
restated here.

## `src/` top-level modules

- **`Controller/`** — HTTP entry points, split into two sub-namespaces:
  - `Controller/Api/` (24 files) — mobile/public JSON API endpoints, using
    `#[Route('/api/...')]` attributes with `IsGranted`/`MapRequestPayload`
    (e.g. `CompetitionController.php`: `/api/competitions`, DTO-mapped
    register/resubmit actions).
  - `Controller/Admin/` (44 files) — EasyAdmin CRUD controllers for the
    back-office (e.g. `ActiveCompetitionCrudController.php`,
    `DashboardController.php`).
- **`Entity/`** — Doctrine ORM entities (`Club`, `Player`, `Staff`, `Scout`,
  `Agent`, `League`, etc.), plus a `Competition/` subfolder for the newer
  competitions feature (`ActiveCompetition`, `CompetitionEntrant`,
  `CompetitionRound`, ...) and a `Concern/` subfolder. `Club.php` uses a
  UUIDv7 PK and business fields like `reputation`, `hallOfFamePoints`.
- **`Repository/`** — one Doctrine `ServiceEntityRepository` per entity plus
  custom query methods (e.g. `ClubRepository::findByUser`), mirroring
  `Entity/`'s shape including its own `Competition/` subfolder.
- **`Service/`** — the bulk of business logic (economy, leaderboards, match
  simulation, world generation, imports/exports), with sub-namespaces
  `Admin/`, `Appearance/`, `Competition/`, `MatchEngine/`, `Personality/`,
  `Notification/` (push notifications — see below). e.g.
  `EconomicService.php`, `LeaderboardCalculationService.php`,
  `SyncService.php`, `WorldInitializationService.php`.
- **`Message/` / `MessageHandler/`** (added 2026-09-19) — Symfony
  Messenger message DTOs + handlers, the **first use of Messenger in
  this codebase**. Currently two: `SendPushNotificationMessage` (async
  FCM send) and `ResolveAdminMessageAudienceForPushMessage` (eager
  admin-broadcast push-audience resolution, deferred off the admin's
  save request). Both route to a single `async` transport
  (`config/packages/messenger.yaml`) — Doctrine transport in prod/dev
  (`MESSENGER_TRANSPORT_DSN`, `auto_setup=1` so its table self-creates,
  though the migration already creates it), `in-memory://` under
  `when@test` so functional tests can assert dispatched envelopes
  without a real consumer. Drained by `docker/messenger-consume.sh`
  every 1 minute (see Deployment wiring below) — not a long-running
  worker, matching this codebase's existing cron-driven-command
  convention rather than introducing a new "daemon" pattern.
- **`EventSubscriber/`** — 2 files, Doctrine lifecycle listeners
  (`#[AsDoctrineListener(event: Events::prePersist)]`). e.g.
  `PersonalityLifecycleSubscriber.php` auto-generates a `PersonalityProfile`
  for `Staff`/`Scout` on prePersist — explicitly excludes `Player`, since
  `PlayerGenerationService` handles that path itself.
- **`Security/`** — currently one file,
  `VerificationAwareAuthenticationSuccessHandler.php`: a decorator
  implementing `AuthenticationSuccessHandlerInterface` that blocks login
  with HTTP 403 for unverified `User`s and stamps `lastLoginAt` on success.
- **`Doctrine/Function/`** — custom DQL function registration;
  `RandFunction.php` maps DQL `RAND()` to Postgres `RANDOM()`.
- **`Dto/`** — request/response DTOs validated with
  `Symfony\Component\Validator` attributes (e.g. `SyncRequest.php` uses
  `#[Assert\Uuid]`, `#[Assert\Positive]`), used with `#[MapRequestPayload]`
  in API controllers; has `Competition/` and `Leaderboard/` subfolders.
- **`Enum/`** — native PHP enums for domain vocabulary (`Formation`,
  `PlayerPosition`, `MessageStatus`, `Tier`, etc.), with `Appearance/` and
  `Competition/` subfolders.
- **`Form/Type/`** — custom Symfony form types that exist specifically to
  make EasyAdmin work with JSON columns; `JsonTextareaType.php`'s own
  comment explains EasyAdmin auto-configures a custom form type bound to a
  Doctrine `json` column and injects options that would otherwise throw.
- **`Command/`** — console commands (`app:*`), mostly seeding/backfill/
  generation jobs invoked by cron scripts under `docker/` or manually
  (`GenerateLeaderboardsCommand.php`, `CompetitionProcessRoundsCommand.php`,
  `SeedArchetypesCommand.php`, etc.).
- **`Exception/`** — a handful of domain exceptions
  (`ClubMismatchException`, `ClubNameTakenException`,
  `SocialPostingException`).
- **`ApiResource/`** — present but empty (just a `.gitignore`) — not
  currently used, despite API Platform being installed
  (`config/packages/api_platform.yaml` exists).

## Config layout (`config/`)

Standard Symfony structure: `bundles.php`, `services.yaml` (DI/service
wiring), `preload.php`/`reference.php`, `routes.yaml` + `routes/` (one
import file per bundle: `api_platform.yaml`, `easyadmin.yaml`,
`framework.yaml`, `security.yaml`), `jwt/` (JWT keypair + gitignore), and
`packages/` with one YAML per bundle — notably `security.yaml`,
`doctrine.yaml`, `lexik_jwt_authentication.yaml`,
`gesdinet_jwt_refresh_token.yaml`, `nelmio_cors.yaml`, `api_platform.yaml`,
`mailer.yaml`, `monolog.yaml`, `html_sanitizer.yaml`, `cache.yaml`,
`csrf.yaml`, `validator.yaml`, `twig.yaml`/`twig_component.yaml`,
`translation.yaml`, `property_info.yaml`, `nyholm_psr7.yaml`,
`doctrine_migrations.yaml`, `routing.yaml`.

## Deployment wiring

- **`Dockerfile`** — single image `php:8.4-fpm-alpine`, installs
  `pdo_pgsql`, `intl`, `opcache`; runs
  `composer install --no-dev --optimize-autoloader --no-scripts`; copies
  `docker/nginx.conf` and `docker/supervisord.conf`;
  `ENTRYPOINT ["/usr/local/bin/jwt-entrypoint.sh"]`,
  `CMD ["/usr/bin/supervisord", ...]`. Bakes a busybox cron table into the
  image (`/var/spool/cron/crontabs/root`) with 11 scheduled jobs:
  `pool-warm.sh`/`worldpack-warm.sh` (every 6h),
  `leaderboards-generate.sh` and `competition-send-round-reminders.sh`
  (every 5 min — the latter pushes `ROUND_RESOLVING_SOON` to `DRAWN`
  rounds nearing `matchesResolveAt`, see `CompetitionRoundReminderService`),
  `post-community-stat-tick.sh` and `telemetry-generate.sh` (every 15 min),
  `competition-provision-instances.sh` (every 10 min — since 2026-09-20
  also dispatches `NEW_COMPETITION_OPEN`'s audience resolution whenever it
  actually creates a new instance, not on every tick),
  `competition-draw-rounds.sh` and `competition-resolve-rounds.sh`
  (added 2026-09-22, replacing the old single `competition-process-rounds.sh`
  — the round lifecycle's draw and resolve phases are now decoupled, each
  with its own 1-min cron entry — see `CompetitionDrawService`/
  `CompetitionResultsService` in `04_interfaces/output/services.md`),
  `competition-auto-fill-spoof-entrants.sh`
  (dev/testing convenience added 2026-09-20 — see
  `CompetitionAutoFillService`), and `messenger-consume.sh` (all four
  every 1 min — the finest auto-fill delay is 5 min, and the last one
  drains the async Messenger transport for push notifications, added
  2026-09-19).
- **`docker/supervisord.conf`** — runs `php-fpm`, `nginx`, and `crond`
  together inside the container, all logging to stdout/stderr.
- **`docker-compose.dev.yml` / `docker-compose.prod.yml`** — near-identical
  single-`app` + single-`postgres` stacks per environment (image tags
  `:dev`/`:prod` from `ghcr.io/cadesile/wunderkind-backend`), each Postgres
  persisted to a distinct host path
  (`/mnt/volume-wkf/wunderkind/{dev,prod}/pgdata`) and deliberately NOT
  joined to the `web` network. Both `app` services join an external `web`
  network plus `default`; `APP_ENV` is always `prod` in both tiers (dev
  runs the same optimised prod container as production, per the file's own
  comment).
- **`deploy/proxy/`** — a separate, hand-deployed shared Caddy 2 reverse
  proxy owning TLS termination and host ports 80/443, joining the same
  external `web` network to reach the app containers by name.
- **`.github/workflows/deploy-prod.yml`** (push to `master`) /
  `deploy-dev.yml` (push to `dev`) — otherwise identical: build+push image
  to GHCR, `scp` the matching compose file to the Hetzner host, write
  `.env` from GitHub secrets, `docker compose pull && up -d`, then a fixed
  sequence of `docker compose exec app php bin/console ...` calls
  (`doctrine:migrations:migrate`, then several idempotent seed/backfill
  commands), `assets:install --symlink`, then
  `docker image prune -a -f --filter "until=24h"`. `app:backfill-appearances`
  and `cache:clear` are deliberately *not* run (documented inline as
  OOM/permission reasons).
- **`.lando.yml`** — local dev via the Lando `symfony` recipe; see
  `.context/stages/01_overview/output/environment.md`.
