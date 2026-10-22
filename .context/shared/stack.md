# Stack (canonical)

> Filled in during stage `01_overview`, after the human confirms the
> Checkpoint in `stages/01_overview/CONTEXT.md`. This is the only home for
> these facts — every other stage references this file, none re-derives it.

- **Primary language:** PHP
- **Framework:** Symfony 8.1 · PHP 8.5 · API Platform v4
- **App directory:** `src/` (PSR-4 `App\` per `composer.json`)
- **Dev environment:** Lando (recipe: symfony, php 8.5 via an image override — the recipe's own `php:` key still reads `'8.4'` because Lando 3.25.6's allowlist rejects 8.5; nginx, webroot `public/`) for local dev; Docker Compose (`docker-compose.dev.yml`, `docker-compose.prod.yml`) + `Dockerfile` for deployed dev/prod behind a shared Caddy proxy (prod:
  `buildmyclub.co.uk`/`www.`/`api.`; dev: `dev.`/`api.dev.` — clients use the `api.`
  host, and the `/api` path prefix is still required there)
- **Database(s):** PostgreSQL 16
- **Dependency security floor (2026-10-21):** `lando composer audit` is clean — 0 advisories.
  Reaching that forced **Symfony 8.0.* ‒> 8.1.*** (`extra.symfony.require` plus 17 explicit
  constraints), and is why the framework is on 8.1 rather than the 8.0 it shipped on. The
  trigger was not a feature need: Composer's advisory-blocking policy (`policy.advisories.block`,
  on by default) refuses to *load* any advisory-affected version, and the whole 8.0 branch of
  `symfony/yaml` (CVE-2026-109413, fixed only in 8.1.6) and `symfony/http-foundation`
  (CVE-2026-108082, fixed only in 8.1.7) is affected — so `composer update` aborted as a unit
  and **none** of the 43 then-outstanding advisories could be fixed while pinned to 8.0.
  **A `MAJOR.MINOR.*` pin is therefore a liability, not just a stability choice**: a branch can
  become uninstallable overnight with the fix only on the next minor. Same pass took
  `easycorp/easyadmin-bundle` 5.0.1 ‒> 5.7.0, `twig/twig` 3.23 ‒> 3.30 (16 advisories, one
  critical), `api-platform/core` 4.2.19 ‒> 4.4.5, PHPUnit 13.1.1 ‒> 13.4.3.
- **Excluded / vendored directories:** `vendor/` (Composer deps), `var/` (cache/logs), `scripts/global-context-generator` (git submodule — this tool itself, not app code), `.superpowers/`, `.worktrees/`, `context.zip` (stale archive)

## Reasoning

`composer.json`'s `require` block names real, non-trivial dependencies
(`symfony/framework-bundle` 8.1.*, `api-platform/core` ^4.2,
`doctrine/orm` ^3.6, `easycorp/easyadmin-bundle` ^5.0,
`lexik/jwt-authentication-bundle` ^3.2) — this is not a thin/near-empty
manifest, so no further stack-signal investigation was needed. No
`package.json` exists at the root, so there is no frontend stack in this
repo; `README.md`'s own "Tech Stack" table confirms the frontend (React
Native) lives in a separate repository and this repo is backend-only.
`.lando.yml` names `recipe: symfony`, `database: postgres:16` directly. Its
`php: '8.4'` key is **not** the version that runs: an `appserver` image
override pins `devwithlando/php:8.5-fpm-7` (PHP 8.5.7), because Lando
3.25.6's `symfony` recipe refuses `php: '8.5'` outright. The `Dockerfile`
pins `php:8.5.12-fpm-alpine`, so deployed dev/prod sit five patches ahead
of local — deliberate, and documented in `.lando.yml`'s comments. `docker-compose.dev.yml` confirms Postgres 16 again
(`postgres:16-alpine`) and shows this app is deployed as a container behind
a shared Caddy proxy, separate from the Lando-based local dev setup — both
are real and recorded rather than picking one. `scripts/global-context-generator`
is declared as a git submodule in `.gitmodules`, not part of the app.
