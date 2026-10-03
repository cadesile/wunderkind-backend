# Dev Environment

See `.context/shared/stack.md` for language/framework/database facts —
not restated here.

## Local dev — Lando (`.lando.yml`)

- Recipe: `symfony`, PHP 8.4, nginx, webroot `public/`
- Database service `database`: Postgres 16, forwarded to host port 5433,
  creds `wunderkind`/`wunderkind`, db name `wunderkind`
- App server exposed on `127.0.0.1:52100:80`
- `post-start` event auto-generates a JWT keypair into `.env.local` if
  `JWT_SECRET_KEY` isn't already set — first-run bootstrap for
  `lexik/jwt-authentication-bundle`
- Tooling shortcuts: `lando php`, `lando composer`, `lando symfony` (runs
  `php bin/console`), `lando psql`

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
