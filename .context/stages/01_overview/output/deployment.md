# Deployment

**Hand-written, not generator output.** Full runbook: `docs/deploy/hetzner.md`.

## Topology

One Hetzner box (`204.168.207.171`), everything under `/mnt/volume-wkf/wunderkind/`.
A **host-level Caddy container owns ports 80/443** and routes by hostname to app
containers over an external docker network named `web`.

```
:80/:443 -> caddy -> wunderkind-prod  -> postgres-prod
                  -> wunderkind-dev   -> postgres-dev
```

| Hostname | Env | Container |
|---|---|---|
| `buildmyclub.co.uk`, `www.buildmyclub.co.uk`, `api.buildmyclub.co.uk` | prod | `wunderkind-prod` |
| `dev.buildmyclub.co.uk`, `api.dev.buildmyclub.co.uk` | dev | `wunderkind-dev` |

**The app containers serve plain HTTP and bind no host ports.** They are reachable only
by container name on the `web` network. `docker/nginx.conf` is a single hostname-agnostic
`server_name _` vhost, so the same image backs every environment.

Caddy issues and renews its own Let's Encrypt certs into the `caddy_data` volume — that
volume must persist or every recreate re-issues and hits rate limits. There is no certbot
any more.

The proxy (`deploy/proxy/`) is **shared infrastructure, deployed by hand**, never by a
per-branch workflow — two workflows would fight over the same path.

## Branch → environment

| Push to | Workflow | Image tag | Server path |
|---|---|---|---|
| `master` | `.github/workflows/deploy-prod.yml` | `:prod` | `/mnt/volume-wkf/wunderkind/prod` |
| `dev` | `.github/workflows/deploy-dev.yml` | `:dev` | `/mnt/volume-wkf/wunderkind/dev` |

Both build → push to GHCR → scp the compose file → write `.env` from secrets → `pull` +
`up -d` → migrate → seed. The two workflows are deliberately kept structurally identical;
`deploy-dev.yml` was derived from `deploy-prod.yml`.

The per-environment `.env` on the box is **regenerated from scratch on every deploy**.
Hand edits there are lost on the next push.

## Non-obvious constraints

- **`TRUSTED_PROXIES=private_ranges` is required in every proxied environment.** Without
  it Symfony reads the docker bridge address as the client, ignores `X-Forwarded-Proto`,
  and emits `http://` URLs — breaking the admin `form_login` redirect and every absolute
  link in an email.
- **Never add `ngx_http_realip_module` config to `docker/nginx.conf`.** Rewriting
  `$remote_addr` to the original client puts a *public* IP in `REMOTE_ADDR`, which then
  fails the `private_ranges` check and reintroduces exactly the bug above. nginx real_ip
  and Symfony `trusted_proxies` do the same job and conflict; Symfony owns it.
- **The apex `buildmyclub.co.uk` must stay listed in the Caddyfile.** `www` is a CNAME to
  it and it has its own A record, so apex traffic reaches the box. Caddy matches hostnames
  strictly where nginx was absorbing it into a default vhost.
- **JWT secrets are base64-encoded PEMs** — `docker/jwt-entrypoint.sh` runs `base64 -d`.
  Each environment gets its own keypair.
- **`CORS_ALLOW_ORIGIN` is a regex**, not a literal (`origin_regex: true`).
- **Two post-deploy commands are deliberately omitted** and must not be added back:
  `app:backfill-appearances` (hydrates every row in one `findBy()`; OOMed on prod's ~36.5k
  appearance-less Staff rows) and `cache:clear` (runs as root via `exec` and was
  OOM-killed 2026-08-15; the entrypoint already warms the cache as `www-data`).
- **`app:seed-archetypes` truncates** — the archetype catalogue is code-owned and admin
  edits to it do not survive a deploy.
- The image bakes a crontab (`pool-warm`, `worldpack-warm` 6-hourly; `leaderboards-generate`
  every 5 min) that runs in **every** environment, dev included.

## Secrets

Per environment, prefixed `PROD_` / `DEV_`: `DB_PASSWORD`, `APP_SECRET`,
`CORS_ALLOW_ORIGIN`, `CLUB_STARTING_BALANCE`, `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`,
`MAILER_DSN`, `MAILER_FROM`, `MAILER_FROM_NAME`, `SOCIAL_TOKEN_ENCRYPTION_KEY`,
`FACEBOOK_APP_ID` / `_APP_SECRET` / `_REDIRECT_URI`, `TWITTER_CLIENT_ID` /
`_CLIENT_SECRET` / `_REDIRECT_URI`. Shared: `HETZNER_IP`, `HETZNER_SSH_KEY`.

`APP_URL`, `DEFAULT_URI` and `TRUSTED_PROXIES` are written as literals in each workflow's
heredoc rather than as secrets — not sensitive, and they must differ per environment or a
dev deploy emits production links.

## Standing up a new environment: what the deploy does NOT do

The workflow migrates and seeds, but a fresh environment is not usable until these are done
by hand. Both were needed for dev and neither is in any workflow:

- **Create an admin user.** `app:admin:create <email> <password> [--name] [--department]`
  run inside that environment's container. The seeders do not create one, so `/admin` has
  no account to log into. (Dev had 0 `admin` rows after a fully green first deploy.)
- **Set the app download URLs.** `GameConfig::androidDownloadUrl` / `iosDownloadUrl`, edited
  in that environment's own admin and served by `GET /api/app-links`. They are
  per-environment, so the dev landing page distributes the dev APK independently of prod.

`scripts/setup-dev-secrets.sh` creates the `DEV_*` GitHub secrets and documents the two
formats that are easy to get wrong (base64 PEMs, base64 32-byte sodium key). Note that
`gh secret set --body ''` blocks reading the value from stdin — the four Facebook/Twitter
credential secrets are therefore deliberately never created, since GitHub substitutes an
empty string for a secret that does not exist.

## History

The dev environment replaced a dormant `staging` stack (`:8080`, reachable only as
`http://204.168.207.171:8080`, `workflow_dispatch` only, ran 1 of 5 seeders, no `MAILER_*`).
**There is no staging tier any more.** The frontend's `staging` EAS profile and OTA
workflow, which pointed at that IP, were retired at the same time.
