# Hetzner Deployment Runbook

Everything runs on one Hetzner box (`204.168.207.171`), under
`/mnt/volume-wkf/wunderkind/`. SSH as the `deploy` user.

## Topology

```
                    :80 / :443
                         │
                   ┌─────▼─────┐
                   │   caddy   │   terminates TLS, routes by hostname
                   └──┬─────┬──┘   (network: web)
        ┌─────────────┘     └─────────────┐
┌───────▼────────┐                ┌───────▼────────┐
│ wunderkind-prod│                │ wunderkind-dev │   nginx + php-fpm, HTTP only
└───────┬────────┘                └───────┬────────┘   (networks: web + own default)
┌───────▼────────┐                ┌───────▼────────┐
│  postgres-prod │                │  postgres-dev  │   not on `web`
└────────────────┘                └────────────────┘
```

| Hostname | Environment | Container |
|---|---|---|
| `buildmyclub.co.uk`, `www.buildmyclub.co.uk`, `api.buildmyclub.co.uk` | prod | `wunderkind-prod` |
| `dev.buildmyclub.co.uk`, `api.dev.buildmyclub.co.uk` | dev | `wunderkind-dev` |

All five names are `A`/`CNAME` → `204.168.207.171`. The apex has its own `A` record and
`www` is a `CNAME` to it, so **the apex must stay listed in the Caddyfile** — Caddy matches
hostnames strictly and would 404 apex traffic otherwise.

The app containers bind **no host ports**. They are reachable only by container name over
the external `web` docker network. TLS certificates are issued and renewed by Caddy over
HTTP-01 and stored in the `caddy_data` named volume — that volume must persist, or every
recreate re-issues and walks into Let's Encrypt rate limits.

## Directory layout

```
/mnt/volume-wkf/wunderkind/
├── proxy/     docker-compose.proxy.yml, Caddyfile   (deployed BY HAND)
├── prod/      docker-compose.prod.yml, .env, pgdata (deployed by CI from master)
└── dev/       docker-compose.dev.yml,  .env, pgdata (deployed by CI from dev)
```

The proxy is shared infrastructure and is **not** deployed by any workflow. If both the
prod and dev workflows scp'd it they would fight over the same path.

The per-environment `.env` files are **regenerated from scratch on every deploy** by the
workflow heredoc. Anything hand-edited on the box is lost on the next push.

## Deploys

| Push to | Workflow | Image tag | Target |
|---|---|---|---|
| `master` | `.github/workflows/deploy-prod.yml` | `:prod` | `/mnt/volume-wkf/wunderkind/prod` |
| `dev` | `.github/workflows/deploy-dev.yml` | `:dev` | `/mnt/volume-wkf/wunderkind/dev` |

Both build the image, push to GHCR, scp the compose file, write `.env` from secrets,
`pull` + `up -d`, then run migrations and seeders.

Two commands are **deliberately excluded** from the post-deploy sequence; do not add them:

- `app:backfill-appearances` — hydrates every matching row in a single `findBy()` and
  exhausted the 128MB container limit on prod's ~36.5k appearance-less `Staff` rows.
- `cache:clear` — `up -d` already recreates the container, and `jwt-entrypoint.sh` runs
  `cache:warmup` as `www-data`. Running `cache:clear` via `docker compose exec` rebuilds
  the cache as **root** (exec does not drop privileges) and was OOM-killed on 2026-08-15.

Note `app:seed-archetypes` **truncates** its table first — the archetype catalogue is
code-owned and admin edits to it do not survive a deploy.

## Secrets

Per environment, prefixed `PROD_` / `DEV_`:

`DB_PASSWORD`, `APP_SECRET`, `CORS_ALLOW_ORIGIN`, `CLUB_STARTING_BALANCE`,
`JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `MAILER_DSN`, `MAILER_FROM`, `MAILER_FROM_NAME`,
`SOCIAL_TOKEN_ENCRYPTION_KEY`, `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET`,
`FACEBOOK_REDIRECT_URI`, `TWITTER_CLIENT_ID`, `TWITTER_CLIENT_SECRET`,
`TWITTER_REDIRECT_URI`.

Shared: `HETZNER_IP`, `HETZNER_SSH_KEY`.

Two traps:

- **JWT keys are base64-encoded PEMs.** `docker/jwt-entrypoint.sh` runs `base64 -d` on
  them. Generate them with
  `php bin/console lexik:jwt:generate-keypair --no-pass` then `base64 -i config/jwt/private.pem`.
  Each environment gets its **own** keypair — never reuse prod's.
- **`CORS_ALLOW_ORIGIN` is a regex**, not a literal
  (`origin_regex: true` in `config/packages/nelmio_cors.yaml`), e.g.
  `^https://dev\.buildmyclub\.co\.uk$`.

`APP_URL`, `DEFAULT_URI` and `TRUSTED_PROXIES` are written as literals in each workflow's
heredoc rather than as secrets — they are not sensitive and they must differ per
environment or a dev-issued email would link to production.

`TRUSTED_PROXIES=private_ranges` is required in every proxied environment. Without it
Symfony sees the docker bridge address as the client, ignores `X-Forwarded-Proto`, and
generates `http://` URLs — which breaks the admin `form_login` redirect.

## One-time server setup

```bash
docker network create web
mkdir -p /mnt/volume-wkf/wunderkind/proxy
# from a workstation:
#   scp deploy/proxy/* deploy@204.168.207.171:/mnt/volume-wkf/wunderkind/proxy/
cd /mnt/volume-wkf/wunderkind/proxy
docker compose -f docker-compose.proxy.yml up -d
```

Port 80 must be free when Caddy first starts or its HTTP-01 challenge fails, so bring the
app stacks down first if they still bind host ports.

For a new environment, also create its data directory before the first deploy:

```bash
mkdir -p /mnt/volume-wkf/wunderkind/dev/pgdata
```

## History: the staging tier

The dev environment replaced a dormant `staging` stack that lived at
`/mnt/volume-wkf/wunderkind/staging`, bound host port 8080, and was reachable only as
`http://204.168.207.171:8080`. Its workflow trigger was `workflow_dispatch`, it ran only a
subset of the seeders, and it had no `MAILER_*` configuration at all.

It was repurposed rather than kept alongside dev, so **there is no staging tier any more.**
Anything that referenced `204.168.207.171:8080` — notably the frontend's `staging` EAS build
profile and its OTA workflow — was retired at the same time. If a pre-prod tier is wanted
later it must be added back as a genuinely new environment; do not assume the old staging
directory or its pgdata still mean anything.

## Migrating an existing box off certbot

Historically the prod container bound 80/443 itself and terminated TLS with certbot certs
bind-mounted from `/etc/letsencrypt`. To cut over (expect ~2 minutes of downtime; do it
off-peak):

```bash
docker network create web
cd /mnt/volume-wkf/wunderkind/prod && docker compose -f docker-compose.prod.yml down
mkdir -p /mnt/volume-wkf/wunderkind/proxy   # then scp deploy/proxy/*
cd /mnt/volume-wkf/wunderkind/proxy && docker compose -f docker-compose.proxy.yml up -d
```

Then push to `master` so prod comes back up on the `web` network with the HTTP-only image.

Leave the old certs in `/etc/letsencrypt` until the new setup is proven — they are the
rollback path.

**Rollback:** `docker compose -f docker-compose.proxy.yml down`, revert the commit, redeploy.
The reverted image restores the TLS nginx config and the certbot sidecar, and the certs are
still on disk.

## Verifying a deploy

```bash
curl -sI https://api.buildmyclub.co.uk/api/app-links | head -1   # 200
curl -sI https://buildmyclub.co.uk/ | head -1                    # 200 (apex)
curl -sI http://api.buildmyclub.co.uk/ | head -1                 # 308 -> https
curl -s  https://api.dev.buildmyclub.co.uk/api/archetypes | head -c 200
docker ps --format '{{.Names}}\t{{.Status}}\t{{.Ports}}'
docker logs --tail 50 caddy
```

Logging in at `/admin` is the specific check that `trusted_proxies` is working — without
it the post-login redirect drops to `http://`.

## Cron

The image bakes a crontab (busybox `crond` under supervisord) running `pool-warm.sh` and
`worldpack-warm.sh` every 6 hours and `leaderboards-generate.sh` every 5 minutes. It runs
in **every** environment, so the dev stack does the same periodic work as prod.
