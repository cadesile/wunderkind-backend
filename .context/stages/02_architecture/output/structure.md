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
  without a real consumer. Since then two more sub-namespaces have been
  added, each with its own transport: `WorldPack/` (`worldpack`, added
  2026-09-28) and `Pool/` (`pool`, added 2026-10-26 —
  `TopUpStarterPoolMessage`, one bounded chunk of pool generation for one
  nationality, queued by `app:starter-pool:audit`; see
  `StarterPoolAuditService` in `04_interfaces/output/services.md`).
  Drained by `docker/messenger-consume.sh`
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
- **`Filter/`** — custom EasyAdmin `FilterInterface` implementations, for
  filter logic too complex for EasyAdmin's built-in property filters.
  Currently one: `IncompleteTranslationFilter` (used by
  `TranslationKeyCrudController`) — a correlated-subquery count comparison
  (translated-and-enabled-language count vs. enabled-language count) with
  no real backing property, not a real column, so it stays correct as
  languages get enabled/disabled.
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

- **`Dockerfile`** — single image `php:8.5.12-fpm-alpine` (exact patch pin, not a floating tag), installs
  `pdo_pgsql`, `intl` — **not** `opcache`, which PHP 8.5 compiles in statically, so
  installing it as an extension fails the build outright (`cp: can't stat 'modules/*'`);
  it is still enabled, `opcache.enable` defaulting to On for FPM. Runs
  `composer install --no-dev --optimize-autoloader --no-scripts`; copies
  `docker/nginx.conf`, `docker/supervisord.conf` and `docker/php.ini` (the last
  landing at `/usr/local/etc/php/conf.d/zz-hardening.ini` — `zz-` so it sorts
  after the base image's own ini files and wins; currently just
  `expose_php = Off`. **Lando does not read it** — local dev boots a
  `devwithlando` image, not this one);
  `ENTRYPOINT ["/usr/local/bin/jwt-entrypoint.sh"]`,
  `CMD ["/usr/bin/supervisord", ...]`. Bakes a busybox cron table into the
  image (`/var/spool/cron/crontabs/root`) with 10 scheduled jobs
  (`pool-warm.sh`/`worldpack-warm.sh`, every 6h, removed 2026-09-28 — World
  Pack Cache generation no longer draws from the shared pool, so there's
  nothing left for a scheduled top-up to feed; `app:pool:warm`/
  `app:worldpack:warm` remain real, manually-runnable commands, just no
  longer scheduled — see `docs/deploy/hetzner.md`'s Cron section):
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
  `CompetitionAutoFillService`), `messenger-consume.sh`,
  `worldpack-consume.sh`, and `pool-consume.sh` (all six every 1 min — the
  finest auto-fill delay is 5 min; the last three drain separate Messenger
  transports, `async` for push notifications (added 2026-09-19), `worldpack`
  for World Pack Cache tier/club generation (added 2026-09-28, split out
  from `async` once a country regenerate's 100+ DB-heavy club-generation
  messages started delaying push delivery by sharing one queue) and `pool`
  for starter-pool top-up chunks (added 2026-10-26, split off for the same
  reason — see `config/packages/messenger.yaml`)), plus
  `starter-pool-audit.sh` (hourly at :35, added 2026-10-26 — runs
  `app:starter-pool:audit`, which sweeps every enabled country's
  starter-pack verdict and queues `pool` chunks for any that fall short;
  queue-only, so the tick itself is cheap. Note this is a deliberate,
  narrow exception to "pool warming is no longer cron-scheduled" — it acts
  only on a country that cannot currently serve a starter pack, never as a
  blanket warm; see `01_overview/output/deployment.md` and
  `docs/deploy/hetzner.md`).
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
  external `web` network to reach the app containers by name. Routes
  `buildmyclub.co.uk` + `www.` + `api.` to `wunderkind-prod`, and `dev.` +
  `api.dev.` to `wunderkind-dev` — **four prod-side/dev-side hostnames with no
  path rewriting**, so the `api.` hosts still need the `/api` prefix. Deployed by
  hand on purpose: a per-branch workflow would have both tiers fighting over the
  same Caddyfile path.
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

## Location & land economy module (added 2026-11-03)

A new vertical slice, following the existing `Competition` / `Friendly` / `Leaderboard`
sub-namespace convention:

```
src/Entity/Location/        13 entities (geography, holds, claims, estimates, enrichment)
src/Enum/Location/           9 enums
src/Repository/Location/     9 repositories
src/Service/Location/        7 services + Enrichment/ (6 more)
                             + Suggestion/ (town names) + CountryEnrichment/ (the country row)
src/Exception/Location/      PlotUnavailableException
src/Message/Location/        EnrichLocationMessage
src/MessageHandler/Location/ EnrichLocationMessageHandler
```

Plus `src/Command/` gains `SeedLocationsCommand`, `EnrichLocationsCommand`,
`LocationRivalsCommand`, `RefreshStadiumEstimatesCommand`, `EnrichCountryProfileCommand`;
`src/Controller/Api/` gains `PlotHoldController` and `PlotClaimController`;
`src/Controller/Admin/` gains three CRUD controllers, `LocationEnrichmentController` and
`LocationDashboardController`.

**FIVE AI FEATURES NOW, ALL THE SAME SEVEN-FILE SHAPE** (interface, Claude
implementation, `Unconfigured*`, factory, validator, result, service), added 2026-11-11
and 2026-11-12:

```
src/Service/Location/Enrichment/        one town   — coordinates, population, land value
src/Service/Location/Suggestion/        town names — more towns for a country
src/Service/Location/CountryEnrichment/ the COUNTRY row — bbox, land band, reference codes
src/Service/CountryContent/Suggestion/  club-naming content — suffixes, stadium formats
                                        AND person names — first names, surnames
```

The repetition is the point: a fifth AI feature is a directory of small classes against
the shared `App\Service\Ai\AnthropicJsonClient`, not a new way of doing things. All five
read the SAME `LOCATION_ENRICHER_PROVIDER` / `_MODEL` / `_API_KEY` trio — one provider, one
key — and all five fall back to a loud refusal rather than to silence. **Every one is wired
by a FACTORY, never an alias**: `alias: '%env(...)%'` does not compile, which is the mistake
to not make a sixth time.

**But they do not all queue, and that distinction is the useful one.** Town enrichment and
the town pull are queued because 865 messages and a 12,000-token generation respectively
demand it. `CountryEnrichment` and `CountryContent\Suggestion` are **one short call each**
and run synchronously in the admin request: a queue there would buy a transport, a handler,
a consumer script and a progress poll in exchange for making a two-second call feel slower,
and would lose the thing that makes the buttons worth having — the operator seeing which
fields landed and which were refused while still looking at the country.

`src/Service/CountryContent/` also gained `CountryNamingContentProvider`,
`NamingContentValidator`, `NamingContentService` and `ValidatedNamingContent`, which put a
DB override layer over `CountryContentRegistry`'s PHP consts **without replacing it** — the
registry's statics are still called from enum methods that have no container. And
`src/Service/` gained `StarterAbilityRangeService`, lifted out of `app:country:bootstrap`
so an admin button and the command cannot drift.

**The same directory then gained the person-name equivalent, 2026-11-12**:
`NamePoolRegistry` (2,492 lines — the pool constant lifted verbatim out of
`NameGeneratorService`, which went from 2,706 lines to 214), `NamePoolProvider`,
`NamePoolValidator`, `NamePoolService`, `ValidatedNamePool` and six files under
`Suggestion/`. **Extracting the constant first, as a no-behaviour-change move, is what made
the rest small**: it relocated 2,415 lines with zero test edits, which decoupled the giant
diff from every design decision after it. `NamePoolProvider` is the one memoised provider in
the codebase and its docblock says why — see the services stage.

**Messenger now has SIX transports.** `enrichment` was added alongside `async`,
`worldpack`, `pool`, `competition` and `registration`, with the same justification each
of those carries: a sweep is up to 865 external API calls of unpredictable latency and
must not get in front of latency-sensitive competition rounds. It is the only queue whose
work costs money per message, which is why
`LocationEnrichmentOrchestrator::TRANSPORT_QUEUE` counts undrained rows before allowing
another sweep. Drained by `docker/enrichment-consume.sh` (still to be added to the
`Dockerfile` crontab).

**The AI provider is deliberately not chosen.** Everything sits behind
`LocationEnricherInterface` and its three siblings, each selected in `config/services.yaml`
by a FACTORY rather than an alias — `alias: '%env(...)%'` does not compile, so picking a
provider from an environment variable has to happen in PHP. Each falls back to its
`Unconfigured*` implementation, which throws and says in the log whether the provider was
not selected or selected-but-unusable. Nothing else in the feature names a vendor.

## Club buyout module (added 2026-11-14)

A small module, but it crosses more layers than its size suggests, and the pieces are only
correct together:

- **`src/Service/Club/`** — `ClubSaleValuationService` (what we offer),
  `ClubSaleService` (credit → scrub → receipt), `ClubScrubService` (delete one club, owner
  survives). See `04_interfaces/output/services.md` for the reasoning in each.
- **`src/Entity/Club/ClubSale.php`** + `src/Enum/Club/ClubSaleStatus.php` +
  `src/Repository/Club/ClubSaleRepository.php` — the authorisation and receipt, and the
  queue's work order. Table `club_sale` (`Version20261114102300`).
- **`src/Message/Club/SellClubMessage.php`** + `src/MessageHandler/Club/` — the queued
  execution, on its own **`clubsale`** transport.
- **`src/Controller/Admin/ClubSaleAdminController.php`** +
  `templates/admin/_club_sale_dialog.html.twig` + the `Est. Value` column and `Sell`
  control added to `templates/admin/user_profile.html.twig`.
- **`src/Command/SellClubsBatchCommand.php`** (`app:clubs:sell-batch`) — the go-live batch,
  which queues onto the same handler rather than reimplementing the work.
- Two extractions, each because a second consumer appeared and a copy would have drifted:
  `src/Service/Location/TownNameMatcher.php` (from `NpcClubLocationLinker`) and
  `src/Service/Admin/MoneyInput.php` (from `LeagueAdminController`).

**Deployment additions:** `docker/clubsale-consume.sh` plus its `COPY`, crontab line (every
1 min) and `chmod` entry in the `Dockerfile`, bringing the baked cron table to 13 consumer/job
entries. That consumer is deliberately separate from `messenger-consume.sh` on **blast
radius** rather than throughput — it is the only queue that credits real money and then
irreversibly deletes player data, so pausing it pauses exactly the destructive work.

**The cross-layer invariant worth holding onto:** the web request only ever RECORDS and
QUEUES. Crediting and deleting happen in the worker, because a request that does both has no
safe retry and a browser will retry.
