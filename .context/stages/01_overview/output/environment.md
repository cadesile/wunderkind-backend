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
