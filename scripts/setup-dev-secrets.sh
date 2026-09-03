#!/usr/bin/env bash
set -euo pipefail

# One-time: create the DEV_* GitHub secrets for wunderkind-backend.
#
# No new SSH/deploy keys are needed — HETZNER_IP and HETZNER_SSH_KEY already exist
# and are shared, and GHCR auth uses the automatic GITHUB_TOKEN.
#
# Secret VALUES are never printed. Re-running is safe: it overwrites with fresh
# values, but rotating DEV_SOCIAL_TOKEN_ENCRYPTION_KEY makes any already-stored
# dev OAuth tokens undecryptable, and rotating DEV_JWT_* logs out dev clients.

REPO="cadesile/wunderkind-backend"

command -v gh >/dev/null || { echo "gh CLI not found"; exit 1; }
command -v openssl >/dev/null || { echo "openssl not found"; exit 1; }
gh auth status >/dev/null 2>&1 || { echo "Run: gh auth login"; exit 1; }

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

echo "Generating a dev-only JWT keypair (separate from production)..."
openssl genrsa -out "$TMP/private.pem" 4096 2>/dev/null
openssl rsa -in "$TMP/private.pem" -pubout -out "$TMP/public.pem" 2>/dev/null

# jwt-entrypoint.sh does `base64 -d` on these, so they must be base64-encoded PEMs.
b64() { base64 < "$1" | tr -d '\n'; }

set_secret() { gh secret set "$1" -R "$REPO" --body "$2" >/dev/null && echo "  set $1"; }

echo "Setting secrets on $REPO ..."
set_secret DEV_JWT_SECRET_KEY "$(b64 "$TMP/private.pem")"
set_secret DEV_JWT_PUBLIC_KEY "$(b64 "$TMP/public.pem")"

set_secret DEV_DB_PASSWORD                "$(openssl rand -hex 24)"
set_secret DEV_APP_SECRET                 "$(openssl rand -hex 16)"
# base64 of a 32-byte sodium secretbox key — TokenEncryptionService rejects anything else,
# and throws outright if this is empty.
set_secret DEV_SOCIAL_TOKEN_ENCRYPTION_KEY "$(openssl rand -base64 32)"

# origin_regex is true in nelmio_cors.yaml, so this is a REGEX, not a literal.
set_secret DEV_CORS_ALLOW_ORIGIN      '^https://dev\.buildmyclub\.co\.uk$'
set_secret DEV_CLUB_STARTING_BALANCE  '500000'

# null:// so the dev environment can never email real users. Swap for a real DSN
# (e.g. a Mailtrap/Mailpit inbox) when you need to exercise verification emails.
set_secret DEV_MAILER_DSN       'null://null'
set_secret DEV_MAILER_FROM      'noreply@dev.buildmyclub.co.uk'
set_secret DEV_MAILER_FROM_NAME 'Build My Club (dev)'

# Social posting is off in dev until you register dev OAuth apps.
#
# DEV_FACEBOOK_APP_ID / _APP_SECRET / DEV_TWITTER_CLIENT_ID / _CLIENT_SECRET are
# deliberately NOT created. GitHub substitutes an empty string for a secret that does
# not exist, so the workflow heredoc emits FACEBOOK_APP_ID="" either way — which is
# what the committed .env ships as a placeholder anyway.
#
# Do not "fix" this by setting them to an empty value: `gh secret set --body ''` blocks
# waiting for the secret on stdin, which looks exactly like a hang.
set_secret DEV_FACEBOOK_REDIRECT_URI 'https://dev.buildmyclub.co.uk/admin/social/facebook/callback'
set_secret DEV_TWITTER_REDIRECT_URI  'https://dev.buildmyclub.co.uk/admin/social/twitter/callback'

echo
echo "Done. Verifying the DEV_* set exists:"
gh secret list -R "$REPO" | grep '^DEV_' | awk '{print "  " $1}'
echo
echo "APP_URL, DEFAULT_URI and TRUSTED_PROXIES are NOT secrets — they are written as"
echo "literals in deploy-dev.yml, because they must differ per environment."