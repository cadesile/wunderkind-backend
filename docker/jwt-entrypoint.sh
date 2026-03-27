#!/bin/sh
set -e

mkdir -p config/jwt

printf '%s' "$JWT_SECRET_KEY" > config/jwt/private.pem
printf '%s' "$JWT_PUBLIC_KEY" > config/jwt/public.pem

chmod 600 config/jwt/private.pem
chmod 644 config/jwt/public.pem

php bin/console cache:warmup --env=prod

exec "$@"
