# Stack (canonical)

> Filled in during stage `01_overview`, after the human confirms the
> Checkpoint in `stages/01_overview/CONTEXT.md`. This is the only home for
> these facts — every other stage references this file, none re-derives it.

- **Primary language:** PHP
- **Framework:** Symfony 8.0 · PHP 8.4 · API Platform v4
- **App directory:** `src/` (PSR-4 `App\` per `composer.json`)
- **Dev environment:** Lando (recipe: symfony, php 8.4, nginx, webroot `public/`) for local dev; Docker Compose (`docker-compose.dev.yml`, `docker-compose.prod.yml`) + `Dockerfile` for deployed dev/prod behind a shared Caddy proxy
- **Database(s):** PostgreSQL 16
- **Excluded / vendored directories:** `vendor/` (Composer deps), `var/` (cache/logs), `scripts/global-context-generator` (git submodule — this tool itself, not app code), `.superpowers/`, `.worktrees/`, `context.zip` (stale archive)

## Reasoning

`composer.json`'s `require` block names real, non-trivial dependencies
(`symfony/framework-bundle` 8.0.*, `api-platform/core` ^4.2,
`doctrine/orm` ^3.6, `easycorp/easyadmin-bundle` ^5.0,
`lexik/jwt-authentication-bundle` ^3.2) — this is not a thin/near-empty
manifest, so no further stack-signal investigation was needed. No
`package.json` exists at the root, so there is no frontend stack in this
repo; `README.md`'s own "Tech Stack" table confirms the frontend (React
Native) lives in a separate repository and this repo is backend-only.
`.lando.yml` names `recipe: symfony`, `php: '8.4'`, `database: postgres:16`
directly. `docker-compose.dev.yml` confirms Postgres 16 again
(`postgres:16-alpine`) and shows this app is deployed as a container behind
a shared Caddy proxy, separate from the Lando-based local dev setup — both
are real and recorded rather than picking one. `scripts/global-context-generator`
is declared as a git submodule in `.gitmodules`, not part of the app.
