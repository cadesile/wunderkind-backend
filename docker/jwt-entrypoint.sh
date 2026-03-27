#!/bin/sh
set -e

mkdir -p config/jwt

echo "$JWT_SECRET_KEY" | base64 -d > config/jwt/private.pem
echo "$JWT_PUBLIC_KEY" | base64 -d > config/jwt/public.pem

chmod 600 config/jwt/private.pem
chmod 644 config/jwt/public.pem

php bin/console cache:warmup --env=prod

# Use HTTP-only nginx config if TLS certs are not yet provisioned
if [ ! -f /etc/letsencrypt/live/api.buildmyclub.co.uk/fullchain.pem ]; then
    echo "TLS certs not found — serving HTTP only until certs are provisioned"
    cp /etc/nginx/nginx-http-only.conf /etc/nginx/nginx.conf
fi

exec "$@"
