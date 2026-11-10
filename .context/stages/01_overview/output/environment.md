# Dev Environment

See `.context/shared/stack.md` for language/framework/database facts —
not restated here.

## Local dev — Lando (`.lando.yml`)

- Recipe: `symfony`, nginx, webroot `public/`
- **PHP 8.5.7, set by an image override — not by the recipe's `php:` key.** That key
  still reads `'8.4'` on purpose: Lando 3.25.6's `symfony` builder keeps its own
  version allowlist that stops at 8.4, and `php: '8.5'` aborts `lando start` with
  `symfony-php version 8.5 is not supported`. The real version comes from
  `services.appserver.overrides.image: devwithlando/php:8.5-fpm-7`. Lando can only
  boot images from that family (`/lando-entrypoint.sh` lives inside them, so the
  official `php:` images are not substitutable), and the family is rebuilt only every
  few months — hence 8.5.7 (Jun 2026) rather than the `Dockerfile`'s 8.5.12. **Local
  dev therefore lags upstream security patches by design; treat it as unpatched.**
- Lando's images ship **no composer binary**, so the `composer` tooling command needs
  the `build_as_root` installer step in `.lando.yml` — without it `lando composer`
  fails with `composer: executable file not found in $PATH`.
- Database service `database`: Postgres 16, forwarded to host port 5433,
  creds `wunderkind`/`wunderkind`, db name `wunderkind`
- App server exposed on `127.0.0.1:52100:80`
- `post-start` event auto-generates a JWT keypair into `.env.local` if
  `JWT_SECRET_KEY` isn't already set — first-run bootstrap for
  `lexik/jwt-authentication-bundle`
- Tooling shortcuts: `lando php`, `lando composer`, `lando symfony` (runs
  `php bin/console`), `lando psql`

## Keeping PHP and dependencies patched

- **Audit with `lando composer audit`** (0 advisories as of 2026-10-21). Composer blocks
  advisory-affected versions from even loading (`policy.advisories.block`, default on), so a
  `MAJOR.MINOR.*` pin can make the whole resolve fail as a unit the moment every version on
  that branch is affected and the fix lands only on the next minor — which is exactly what
  forced Symfony 8.0.* -> 8.1.*. See `.context/shared/stack.md`'s dependency-floor bullet.
  Consequence: a red `composer audit` may need a **minor** bump, not a patch bump; don't
  assume the pin can hold.
- **Two PHP versions are in play and they are not the same** — Lando 8.5.7, deployed 8.5.12
  (see above). Verify a PHP upgrade in BOTH: `lando php vendor/bin/phpunit --no-coverage`
  for the suite, and `docker build .` for the image, because the base image breaks
  independently of the code (the PHP 8.5 opcache trap below was invisible to the suite and
  failed only the build).
- **Upgrade order that actually catches things:** bump the `Dockerfile` pin -> `docker build .`
  -> bump `.lando.yml`'s image override -> `lando rebuild -y` -> `lando composer update`
  -> `lando composer audit` -> full suite -> `lando php bin/console lint:container`.

## Developing against realistic data — `scripts/pull-prod-db.sh`

**Trigger phrase**: when the human says something like "pull prod DB", "pull the prod
database", or "refresh my local DB from prod", that means: run `bash
scripts/pull-prod-db.sh --yes` now, in this session — don't just point at the script,
actually run it (the human has already made the PII call below; re-confirming in a shell
prompt the agent itself would be driving is redundant). If the human instead says they
want a *fresh/empty/clean* local DB, that's `scripts/reset_and_seed.sh`, a different
script — don't conflate the two just because both touch the local database.

- Pulls a `pg_dump` from the production Hetzner box (`docs/deploy/hetzner.md`) over SSH
  and restores it into the local Lando database, **replacing** whatever's there.
- **Raw, not scrubbed — a deliberate, explicit human decision** (confirmed via
  `AskUserQuestion` when this script was built, 2026-10-03), not a default assumption to
  revisit unprompted: the restored copy includes real user PII verbatim (emails, owner
  identity, GDPR `DeletionRequest` rows). OAuth tokens on `social_account_connection`
  decrypt to nothing locally (encrypted with the prod-only `SOCIAL_TOKEN_ENCRYPTION_KEY`,
  which the local env doesn't have) and FCM device tokens can't receive a push triggered
  from a local environment — both effectively inert once local, not live secrets.
- Dump files land under `var/prod-dumps/` (gitignored via the existing root `/var/`
  pattern) and are deleted after a successful import by default — pass `--keep-dump` to
  retain one, then `--from-dump var/prod-dumps/<file>.sql` to re-restore it without
  re-pulling from prod (useful when iterating on the restore step itself, or re-running
  after a `reset_and_seed.sh` wipe).
- Requires SSH access to the production host and Lando running locally
  (`lando start`) — same SSH key already used for manual deploy/runbook operations.
- Handles two environment quirks that aren't obvious from the script alone if it ever
  needs debugging: Lando's `postgres:16` service does **not** make the app DB user
  (`wunderkind`) a superuser (unlike prod's `POSTGRES_USER` convention) — dropping/
  recreating the database must connect as the built-in `postgres` role instead; and newer
  `pg_dump` point releases (confirmed on prod: 16.15) wrap dumps in `\restrict`/
  `\unrestrict` meta-commands that an older local `psql` (confirmed: 16.6) doesn't
  recognize — the script strips those lines before restoring regardless of which side is
  newer.
- Full test suite is unaffected either way: functional tests run against the separate
  `wunderkind_test` database (see root `CLAUDE.md`'s Testing section), never the `wunderkind`
  dev database this script touches.

## ⚠️ `wunderkind_test` is NOT migration-tracked — a new migration never reaches it

It is built by `doctrine:schema:create` from **entity metadata**, so
`doctrine:migrations:migrate --env=test` fails on already-applied DDL and a migration you have
just written has no effect there at all. The suite then fails in ways that look nothing like
your change.

**What that looks like (2026-11-02):** a full run reported **36 failures** across WorldPack,
StarterConfig and competition tests. Thirty-five were one missing column
(`starter_config.setup_tutorial_config`, added by an earlier commit and never applied here)
cascading through everything that loads a `StarterConfig`. Exactly one was real. The noise is
the danger: it is easy to read a wall of red as "my change broke everything" or, worse, as
"this suite is always flaky" and push past it.

**`wunderkind_test` is shared across branches, and that is a trap when you switch.**
There is ONE test database for every branch, so it is only ever in sync with whichever
branch last synced it — and `master` can sit far behind `development`. Checking out the
other branch and running the suite then fails in bulk for reasons that have nothing to do
with your change, and the direction of the failure tells you which way the mismatch runs:

- DB **behind** the entities -> `Undefined column: ... does not exist` (e.g. on
  `development`, `location_config.big_share_percent`).
- DB **ahead** of the entities -> `Not null violation` on a column the older branch's
  entity never sets (e.g. on `master`, `club.name_normalised` — 137 errors, none real).

**Do not "fix" this by re-syncing the shared DB**: that just moves the breakage to the
other branch. `config/packages/doctrine.yaml` sets
`dbname_suffix: '_test%env(default::TEST_TOKEN)%'`, so a per-branch database is one env
var away and costs nothing:

```bash
# the wunderkind role cannot CREATE DATABASE - go through the postgres superuser
docker exec wunderkindbackend_database_1 \
  psql -U postgres -c 'CREATE DATABASE wunderkind_testmaster OWNER wunderkind;'
lando ssh -s appserver -c 'TEST_TOKEN=master php bin/console doctrine:schema:create --env=test'
lando ssh -s appserver -c 'TEST_TOKEN=master php vendor/bin/phpunit --no-coverage tests/...'
```

`TEST_TOKEN` must be set INSIDE the container (`lando ssh -s appserver -c '...'`); prefixing
`lando php` sets it on the host CLI, where Symfony never sees it. Verify with
`doctrine:schema:update --dump-sql --env=test`, which should report nothing to update —
that zero-drift baseline is what makes a failure meaningful instead of noise.

**One caveat: `schema:create` builds structure, not content.** A fresh per-branch DB has no
`game_event_template` rows, so `MatchNarrativeGeneratorService` generates an empty timeline
and any test asserting on a narrative payload fails for that reason alone (seen on
`CompetitionControllerTest::testShowIncludesResultsOnceRoundsAreProcessed`). Copy the
content across rather than debugging it:

```bash
docker exec wunderkindbackend_database_1 bash -c \
  "pg_dump -U postgres -d wunderkind_test --data-only --table=game_event_template \
   | psql -U postgres -d wunderkind_testmaster -q"
```

**Syncing the test DB:**

1. `lando php bin/console doctrine:schema:update --dump-sql --env=test` — shows the real diff.
   Run this BEFORE trusting any full-suite result; a non-empty diff means you are about to
   debug someone else's drift.
2. `--force` aborts on any column needing a default for existing rows (`contains null values`),
   which is the common case, so it often cannot be used. Fall back to the one statement:
   `lando psql -d wunderkind_test -c "<ALTER …>"`.
3. **A column with a PHP-level entity default also needs its VALUE seeded** into existing rows.
   `schema:create` creates the column, not the content, so a row that predates the column stays
   empty and content assertions fail even once the schema matches — that was the
   `setup_tutorial_config` case, and filling the column with `{}` fixed the 500s while leaving
   four assertions failing until the entity's own default was written into the row.

The same applies to any FK/nullability change: `Version20261102140000` (competition_entrant) had
to be applied to `wunderkind_test` by hand before its regression test could pass.

## Deployed dev/prod — Docker Compose

- `docker-compose.dev.yml` and `docker-compose.prod.yml` are structurally
  identical: both run the app container built from the root `Dockerfile`,
  image `ghcr.io/cadesile/wunderkind-backend:{dev,prod}`
- **Four public hostnames, all proxying to the same container, no path rewriting**
  (`deploy/proxy/Caddyfile`): prod serves `buildmyclub.co.uk`, `www.buildmyclub.co.uk`
  and **`api.buildmyclub.co.uk`**; dev serves `dev.buildmyclub.co.uk` and
  **`api.dev.buildmyclub.co.uk`**. The `api.` hosts are the intended client entry
  points, but because nothing rewrites the path **the `/api` prefix is still
  required there** — `api.buildmyclub.co.uk/api/telemetry` works,
  `api.buildmyclub.co.uk/telemetry` 404s. Worth stating because the hostnames were
  previously recorded nowhere in `.context/` (only the Caddyfile and a comment in
  `docker-compose.dev.yml`), which is an easy way to document the wrong base URL in a
  client contract.
- TLS and host ports 80/443 are owned by a shared Caddy proxy
  (`deploy/proxy/`), reached over an external `web` Docker network — these
  stacks themselves bind no host ports
- Dev stack runs the same optimised prod container as production
  (`APP_ENV: prod` even in the dev compose file — dev is a deployment tier
  name, not a Symfony env name)
- **PHP is pinned to an exact patch (`php:8.5.12-fpm-alpine`), not a floating
  `8.5` tag** — a rebuild can't silently land on a different PHP than the one
  the image was verified against, and the deployed version is auditable from
  the `Dockerfile` alone. Bump it deliberately on each PHP security release
  (8.5.12 carries the 2026-10-21 GHSA-w8hw-w9fx-j79w / GHSA-6wmf-4rv5-p3rp
  fixes). The build pulls the base fresh every time — `docker/build-push-action`
  on an ephemeral GitHub runner has no local base-image cache — so the pin is
  for reproducibility and audit, not to defeat caching.
- **`opcache` must not appear in the `Dockerfile`'s `docker-php-ext-install` list.**
  PHP 8.5 compiles Zend OPcache in statically, so installing it as an extension builds
  no module and fails the whole build (`cp: can't stat 'modules/*'`). It is active
  anyway — `opcache.enable` defaults to On for FPM, `opcache.enable_cli` to Off.
- **Version-disclosure headers are suppressed in the deployed container** —
  `expose_php = Off` (`docker/php.ini`) kills `X-Powered-By: PHP/<exact patch>`, and
  `server_tokens off` (`docker/nginx.conf`) reduces `Server: nginx/<version>` to a bare
  `nginx`. Both previously published the exact build on every response, which makes
  matching the next advisory a lookup rather than a probe. `server_tokens off` is the
  only knob core nginx offers; removing the header outright needs the third-party
  headers-more module. Caddy fronts these stacks but passes the upstream `Server`
  header through, so fixing it at nginx is what actually changes the public response.
  **This does not apply to local Lando**, which serves via its own bitnami nginx and
  a `devwithlando` PHP image — neither reads these files, so local responses still
  carry versions.
- Postgres runs as a sibling container (`postgres-dev`/equivalent),
  deliberately kept off the `web` network so it's unreachable from the
  proxy or the other stack
- Required env vars (names only, from `.env` / compose files — never
  values): `APP_ENV`, `APP_SECRET`, `APP_URL`, `DEFAULT_URI`,
  `TRUSTED_PROXIES`, `DATABASE_URL`, `CORS_ALLOW_ORIGIN`, `JWT_SECRET_KEY`,
  `JWT_PUBLIC_KEY`, `CLUB_STARTING_BALANCE`, `MAILER_DSN`, `MAILER_FROM`,
  `MAILER_FROM_NAME`, `BETA_INVITE_MAILER_FROM`,
  `SOCIAL_TOKEN_ENCRYPTION_KEY`, `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET`,
  `FACEBOOK_REDIRECT_URI`, `TWITTER_CLIENT_ID`, `TWITTER_CLIENT_SECRET`,
  `TWITTER_REDIRECT_URI`
