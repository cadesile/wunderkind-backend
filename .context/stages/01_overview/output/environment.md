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

## Deployed dev/prod — Docker Compose

- `docker-compose.dev.yml` and `docker-compose.prod.yml` are structurally
  identical: both run the app container built from the root `Dockerfile`,
  image `ghcr.io/cadesile/wunderkind-backend:{dev,prod}`
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
