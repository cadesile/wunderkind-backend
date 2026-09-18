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
