#!/bin/sh
set -e

mkdir -p config/jwt

echo "$JWT_SECRET_KEY" | base64 -d > config/jwt/private.pem
echo "$JWT_PUBLIC_KEY" | base64 -d > config/jwt/public.pem

chmod 600 config/jwt/private.pem
chmod 644 config/jwt/public.pem

# Warm the cache as www-data (the php-fpm runtime user), not root — otherwise
# root-owned cache files are created here and php-fpm workers can't write new
# entries into the same directories afterward (Doctrine "Permission denied" on
# any query hash not covered by this warmup). Also defensively re-chown var/ in
# case a persistent volume already has root-owned files from before this fix.
chown -R www-data:www-data var/
su-exec www-data php bin/console cache:warmup --env=prod
chown -R www-data:www-data var/

# Use HTTP-only nginx config if TLS certs are not yet provisioned
if [ ! -f /etc/letsencrypt/live/api.buildmyclub.co.uk/fullchain.pem ]; then
    echo "TLS certs not found — serving HTTP only until certs are provisioned"
    cp /etc/nginx/nginx-http-only.conf /etc/nginx/nginx.conf
fi

exec "$@"
