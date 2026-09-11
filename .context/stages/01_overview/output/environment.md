# Environment

## Environment Variables

```
APP_ENV=***
APP_SECRET=***
APP_SHARE_DIR=***
DEFAULT_URI=***
DATABASE_URL=***
CORS_ALLOW_ORIGIN=***
JWT_SECRET_KEY=***
JWT_PUBLIC_KEY=***
CLUB_STARTING_BALANCE=***
LEADERBOARD_CACHE_TTL=***
MAILER_DSN=***
MAILER_FROM=***
MAILER_FROM_NAME=***
APP_URL=***
FACEBOOK_APP_ID=***
FACEBOOK_APP_SECRET=***
FACEBOOK_REDIRECT_URI=***
TWITTER_CLIENT_ID=***
TWITTER_CLIENT_SECRET=***
TWITTER_REDIRECT_URI=***
SOCIAL_TOKEN_ENCRYPTION_KEY=***
TRUSTED_PROXIES=***
```

Deployed environments additionally set `APP_URL`, `DEFAULT_URI` and
`TRUSTED_PROXIES=private_ranges` from the deploy workflow rather than from a committed
`.env`. See `output/deployment.md`.

## Development Setup

```bash
lando start
lando composer install
lando php bin/console migrate
```
# Repository Branching & Workflow Rules

You are an expert AI assistant working inside this codebase. Strictly adhere to the following branching rules and workflow guidelines for all code changes, shell commands, and suggestions.

## 1. Branch Architecture & Environments
* **`master`**: Linked directly to **Production**.
  * Pushes trigger automated PHP backend deployment.
  * **STRICT RULE:** Never create feature branches directly off `master` or write code intended for `master` unless specifically instructed by the engineer.
* **`development`**: The working/integration branch for all development work.
  * Cut from `master`. All new work happens within `development` unless the engineer explicitly says otherwise.
  * Does not itself trigger any deployment.
* **`dev`**: Linked directly to **Development environment deployment**, used for external testing.
  * Pushes trigger automated PHP backend deployment to the dev environment.
  * Fed by merging `development` into `dev` when work is ready for external testing — `dev` is a testing target, not a branch to build off of.

## 2. Working Branch Protocol
* **Default Target:** Unless explicitly stated otherwise by the engineer, ALL new code, branches, and work must stem from and merge back into the **`development`** branch.
* **Feature Branches:** If separate branches are used, cut them from `development` (e.g., `feature/login-fix`, `fix/api-endpoint`) and merge back into `development`.
* **Promotion flow:** `development` → merged into `dev` for external testing → once confirmed, `development` → merged into `master` for the production deploy. `dev` is never merged into `master`.

## 3. Operational Rules for AI
* When suggesting git commands (e.g., creating branches, pushing, opening PRs), ensure the base/target branch is explicitly set to `development`.
* Before generating code or modifications, assume the current environment is linked to `development` as the integration branch.
* Treat pushes to `master` or `dev` as consequential deployment actions and remind the user if a command will trigger an automated PHP deploy.