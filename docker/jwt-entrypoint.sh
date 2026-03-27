#!/bin/sh
set -e

mkdir -p config/jwt

echo "$JWT_SECRET_KEY" | base64 -d > config/jwt/private.pem
echo "$JWT_PUBLIC_KEY" | base64 -d > config/jwt/public.pem

chmod 600 config/jwt/private.pem
chmod 644 config/jwt/public.pem

php bin/console cache:warmup --env=prod

exec "$@"
