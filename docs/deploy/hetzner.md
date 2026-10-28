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
`TWITTER_REDIRECT_URI`, `FIREBASE_SERVICE_ACCOUNT_JSON`.

Shared: `HETZNER_IP`, `HETZNER_SSH_KEY`.

Four traps:

- **A secret being correctly written into `/mnt/volume-wkf/wunderkind/{env}/.env` on the
  server does NOT mean the container receives it.** That `.env` is only used for
  docker-compose's own `${VAR}` interpolation *while parsing* `docker-compose.{dev,prod}.yml`
  — a variable is only actually injected into the `app` container if it's listed under that
  service's own `environment:` block. `FIREBASE_SERVICE_ACCOUNT_JSON` and
  `MESSENGER_TRANSPORT_DSN` were both missing from that block for months; `MESSENGER_TRANSPORT_DSN`
  never visibly broke because the image's own committed `.env` default
  (`doctrine://default?auto_setup=1`) happens to already be the correct production value, but
  `FIREBASE_SERVICE_ACCOUNT_JSON`'s baked default (`'{}'`) is a placeholder — so the container
  silently ran on `{}` (which decodes to an empty array, producing
  `Kreait\Firebase\Exception\InvalidArgumentException: Could not map type ServiceAccount: ...
  Value *missing*` for every field) regardless of how correct the GitHub secret was. Fixed in
  both compose files by adding the variable to `environment:`. **The lesson generalizes**: any
  new env-driven secret/config value must be added in *three* places, not two — the GitHub
  Actions secret, the deploy workflow's `.env` heredoc, *and* the compose file's `environment:`
  block — missing the third one fails exactly like this, silently, with the app falling back to
  whatever default is baked into the image's own `.env`.
- **`FIREBASE_SERVICE_ACCOUNT_JSON` must be MINIFIED (single-line) JSON**, not the
  pretty-printed multi-line file Firebase's console hands you on download. The workflow's
  heredoc runs `sed -i 's/^[[:space:]]*//' .env` afterward to strip the YAML block's own
  indentation from every line — a multi-line secret would have each of its own lines mangled
  by that same strip, corrupting the JSON (and, worse, the private key's embedded `\n`
  sequences, which are supposed to stay as the two literal characters `\` `n` inside the JSON
  string, not real newlines). Minify before setting the secret:
  `jq -c . service-account.json | gh secret set DEV_FIREBASE_SERVICE_ACCOUNT_JSON`. See
  `src/Service/Notification/FirebaseMessagingFactory.php` for how the backend consumes it —
  a factory that `json_decode()`s the raw string directly, deliberately bypassing
  kreait/firebase-bundle's own YAML-config credential binding (which has been unreliable for
  this same reason).
- **`JWT_SECRET_KEY`/`JWT_PUBLIC_KEY` hold the RAW PEM, not base64.**
  `config/packages/lexik_jwt_authentication.yaml` sets `secret_key: '%env(JWT_SECRET_KEY)%'`, so
  Lexik uses the environment variable *itself* as the key material — it never reads
  `config/jwt/*.pem`. Set the secret with `gh secret set DEV_JWT_SECRET_KEY < private.pem` so the
  multi-line PEM is preserved; a base64 blob fails at sign time with
  `InvalidKeyProvided: error:1E08010C:DECODER routines::unsupported`, *after* login has already
  succeeded. Each environment gets its own keypair.
  
  `docker/jwt-entrypoint.sh` does run `base64 -d` into `config/jwt/*.pem`, but **those files are
  vestigial** — nothing reads them, they end up root-owned mode 600 (unreadable by the `www-data`
  php-fpm workers), and on a raw-PEM secret `base64 -d` silently produces a junk DER file. Do not
  take that script as evidence the secret should be base64.
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

## Troubleshooting: deploy fails while pulling the image

Symptom — the GitHub Actions deploy fails inside the SSH step with one of:

```
failed commit on ref "config-sha256:…": commit failed: rename
  /var/lib/containerd/…/ingest/…/data → …/blobs/sha256/…: no such file or directory

failed to extract layer (…) to overlayfs as "extract-…":
  failed call to UtimesNanoAt for /var/lib/containerd/…/snapshots/<n>/fs/root/.composer:
  no such file or directory
```

This is the **containerd image store on the box, not your code** — the same commit
deploys fine to dev. Docker 29 uses the containerd image store here
(`docker info` → `driver=overlayfs`, `io.containerd.snapshotter.v1`), and letting
untagged layers accumulate has corrupted it twice.

**It is not a disk problem.** Check anyway (`df -h /`, `df -i /`), but both times the
box was at 27% used with 7% inodes.

Nothing is lost when this happens: the pull fails before anything is replaced, so
production keeps serving the previous image. Confirm with
`docker inspect wunderkind-prod --format '{{.State.StartedAt}}'`.

Fix:

```bash
ssh deploy@204.168.207.171
docker system prune -f        # reclaimed 3.23 GB the last time
gh run rerun <failed-run-id>  # or push again
```

**Never add `--volumes`.** The Postgres data is a bind mount
(`/mnt/volume-wkf/wunderkind/prod/pgdata`) so it is out of reach either way, but
Caddy's TLS certificates live in the `proxy_caddy_data` named volume, and deleting
those forces re-issuance against Let's Encrypt rate limits.

Both deploy workflows now run `docker system prune -f` before pulling and retry once
with `docker image prune -af` if the pull still fails, so this should self-heal. If
it recurs anyway, the snapshot store is damaged more deeply than a prune clears —
stop Docker and clear `/var/lib/containerd/io.containerd.content.v1.content/ingest/`.

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

## Always-on workers (not cron)

Supervisord runs `php-fpm`, `nginx`, `crond` — and, since competition registration was
queued, **`registration-consume`**: a long-lived `messenger:consume registration` worker.

It is the only Messenger consumer not paced by crond, and the distinction matters
operationally. Every other queue tolerates up to 60s of pickup latency because nothing waits
on it inside a user-visible window. This one is load-bearing in both directions: a club's
squad is not stored until its message is handled, and `CompetitionDrawService` **refuses to
draw a competition with unstored submissions** — so a cron-paced consumer would routinely
stall the first round of a competition that just filled.

**If this worker is not running, registrations silently stop completing.** Clubs still get
their 201 and still hold their slot, but no squad is written and no round is ever drawn.
`app:competition:health` reports it (`submissions.stalePending`, and a warning naming this
program by name). To check and recover:

```bash
docker exec wunderkind-prod supervisorctl status registration-consume
docker exec wunderkind-prod supervisorctl restart registration-consume
docker exec wunderkind-prod php bin/console app:competition:health --strict
```

A submission whose retries are spent lands on the `failed` transport with its payload intact
and the entrant marked `failed` — replay it with `messenger:failed:retry`, or withdraw the
club. The slot is deliberately left held rather than freed automatically; that is an
operator's call mid-competition.

## PHP-FPM sizing

`docker/www.conf` (copied to `/usr/local/etc/php-fpm.d/zz-pool.conf`) overrides the image
default of `pm.max_children = 5` — which was a hard ceiling of five concurrent PHP requests
for the **entire API**, shared with `/api/sync` and everything else, so one burst of
tournament registrations starved every other endpoint.

It is now `pm = static`, `pm.max_children = 12`, sized against this specific box: **2 vCPU /
3.8 GB**, also hosting the dev stack and both Postgres instances. CPU, not memory, is the
binding constraint (~456 MB steady-state in use), and PHP here is CPU-bound. **Raise this when
the box gains cores, not when a queue looks slow.**

### Sizing table — what to set after a resize

Derived from a measured competition registration: ~22 ms of pure JSON work for a 1.4 MB
payload, ~20 MB peak, plus DB time. Registration is the heaviest request the API serves, so
sizing to it sizes everything.

| Box | Sustained registrations/sec | `pm.max_children` | Notes |
|---|---|---|---|
| **2 vCPU / 3.8 GB** (today, dev co-resident) | ~10–20 | **12** | prod splits 2 cores with `wunderkind-dev`, `postgres-dev`, `caddy` |
| 2 vCPU, **dev moved off** | ~20–40 | 12 | **the planned first step** — no purchase, roughly doubles prod's cores |
| 4 vCPU / 8 GB | ~40–80 | 24 | |
| 8 vCPU / 16 GB | ~80–160 | 48 | past here, measure Postgres before PHP |

**The rule, not the row:** `pm.max_children ≈ 6 × vCPU`. PHP here is CPU-bound, so beyond
that more runnable workers buy context switches rather than throughput. Memory is not the
binding constraint at any of these sizes — a realistic worker working set is ~60 MB (the
512 MB `memory_limit` is a ceiling, not an allocation), so even 48 workers is ~2.9 GB, and
Hetzner's tiers scale RAM with vCPU anyway.

**Where to change it:** `docker/www.conf`, committed in this repo and baked into the image at
`/usr/local/etc/php-fpm.d/zz-pool.conf`. It is a literal, not an env var, on purpose — a new
env var has to land in three places (see the `TRUSTED_PROXIES` note above) and a box resize is
already a deploy-level event, so a normal CI build from `master` picks the change up anyway.

**The intended order is: move dev off first, then resize.** Splitting the dev stack
(`wunderkind-dev`, `postgres-dev`) onto its own box is a decided plan, not a suggestion — it
roughly doubles prod's effective CPU for no new spend, and removes the case where a dev deploy
or an admin-triggered country regenerate competes with live registrations. Do that before
paying for cores, and re-measure after: the table's first two rows differ by 2x on the same
hardware.

**Two things a resize does not fix:**

- **Postgres shares the box.** Past ~8 vCPU the write path (a TOASTed ~1.4 MB row per
  registration, plus WAL) is the next thing to measure, not PHP.
- **The payload grows on its own.** `club.cupTrophies` and `club.facilityUpgrades` are
  append-only client history: ~1.4 MB at five in-game years, and on track to hit
  `SnapshotValidator::MAX_SNAPSHOT_BYTES` (4 MB) at around ten. That one is a client-side fix,
  not a knob here.

## Cron

The image bakes a crontab (busybox `crond` under supervisord) running
`leaderboards-generate.sh` every 5 minutes, among other entries — see the comment block
above the crontab `RUN` step in the `Dockerfile` for the full schedule. It runs in
**every** environment, so the dev stack does the same periodic work as prod.

**Pool/world-pack warming is no longer cron-scheduled.** `docker/pool-warm.sh` and
`docker/worldpack-warm.sh` (and their crontab entries) were removed once World Pack Cache
generation stopped drawing Player/Staff/Scout from the shared pool — each country/tier
regenerate now generates everything it needs fresh, so there's nothing left for a
scheduled pool top-up to feed. `app:pool:warm` and `app:worldpack:warm` are still real,
manually-runnable console commands (`lando php bin/console app:pool:warm <country>` /
via `docker compose exec app` in prod) — just no longer scheduled. Note this means the
shared pool (still consumed by `StarterPackService`'s starter-squad draw and
`MarketController`'s live "sign from market" feature) has **no automatic replenishment**
going forward and will drain over time; run `app:pool:warm` by hand if it does. This
isn't a bug to "fix" by re-adding the cron — it's a deliberate tradeoff, so don't assume
a future "market pool ran dry" report points at a regression here.

**The one exception, added deliberately: starter-pack supply.** `docker/starter-pool-audit.sh`
runs `app:starter-pool:audit` hourly at :35. It is *not* the old blanket pool warm coming
back — it tops nothing up on its own schedule. It runs the same per-country check
`app:starter-pack:check` reports (`StarterPackDiagnosticService`), and only for a country
whose verdict is **not** `ok` does it queue bounded top-up chunks onto the `pool` Messenger
transport, which `docker/pool-consume.sh` drains every minute. A healthy country queues
nothing however far its pool has drifted from the warm floors, so the tradeoff above still
holds for the market pool — what is now covered automatically is the narrower case where a
country can no longer hand a new club its starting squad at all, which surfaces to the
client as a failed club initialisation rather than as a thin market.

Three guards make it safe unattended, all in `StarterPoolAuditService` (read its docblock
before changing the cadence):

* **It never queues work generation cannot finish.** Under the default `PoolConfig` (players
  generated at 12–13, starter draw floored at `playerFirstTeamAgeMin` 19) no newly generated
  player is ever eligible, so a player shortfall is reported as a *blocker* — a config
  decision for an operator — rather than ground at forever. Same for an ability band whose
  every member is too young. Blockers are printed by the command and land in
  `/var/log/starter-pool-audit-cron.log`; **nothing else surfaces them**, so that log is the
  place to look when a country stays degraded with an empty queue.
* **It will not stack.** The sweep is skipped while the `pool` queue still has undrained
  chunks (the counts a second sweep would read have not caught up yet, so it would re-queue
  the same deficit). `--force` overrides this for a hand-run.
* **Chunks re-check their own target.** `TopUpStarterPoolMessageHandler` clamps to
  `target - current` before generating, so a replayed message, a double dispatch, or an admin
  pressing **Warm** on the check screen mid-drain all cost nothing instead of overshooting.

Run it by hand with `--dry-run` to see what a sweep would do without queueing, and
`--country=XX` to scope it to one. Targets default to the same floors `app:pool:warm` uses
(500 players / 50 per staff role / 50 scouts per nationality) and are overridable per run
with `--min-players` / `--min-staff` / `--min-scouts` / `--chunk`.

One thing the audit covers that `app:pool:warm` does not: warm only ever generates
`MANAGER`/`COACH`/`CHAIRMAN`, so a country short on `DIRECTOR_OF_FOOTBALL` or
`FACILITY_MANAGER` stays short no matter how often it is warmed by hand. The audit is driven
by the starter pack's own requirements, so it covers all five roles.

Also every 1 minute: `docker/messenger-consume.sh` drains the `async` Messenger transport
(push notifications — see `src/Service/Notification/PushNotificationService.php`), and
`docker/worldpack-consume.sh` separately drains the `worldpack` transport (World Pack Cache
tier/club generation — see `WorldPackGenerationOrchestrator`), and `docker/pool-consume.sh`
drains the `pool` transport (starter-pool top-up chunks — see above). These are three
independent consumers, not one consumer reading three transports: a country regenerate can queue 100+
DB-heavy club-generation messages, and the two transports used to share one queue, so a
large regenerate (or a backlog of failing/retrying push sends) delayed the other. Splitting
them into separate `queue_name`s (`config/packages/messenger.yaml`) with their own
cron-scheduled consumer means each makes independent progress — `pool` is split off for the
same reason, so a routine overnight top-up never sits in front of an admin waiting on a
country regenerate. All three use the same
underlying Doctrine `messenger_messages` table (`queue_name` differentiates rows, not the
table), created by the migration in this repo, not a separate `messenger:setup-transports`
deploy step — `MESSENGER_TRANSPORT_DSN`'s `auto_setup=1` means it would also self-create on
first dispatch if ever missing.

**Local lando dev does not run this crontab at all** — `.lando.yml` uses Lando's generic
`symfony` recipe, not this repo's `Dockerfile`, so none of its baked-in cron entries
(including both Messenger consumers) ever run locally. Queued messages just sit in
`messenger_messages` until drained by hand: `lando php bin/console messenger:consume async
-vv` / `messenger:consume worldpack -vv` / `messenger:consume pool -vv` (add
`--time-limit`/`--limit` to bound a run). The hourly starter-pool audit does not run locally
either — run `app:starter-pool:audit` by hand, then drain `pool`. This
is why a local "Regenerate Country Cache" can appear to hang at "already active" — the run
is genuinely still queued, not stuck; draining `worldpack` resolves it. The deployed dev/
staging/prod environments below **do** run the real Dockerfile crontab, so this gap is
lando-only.
