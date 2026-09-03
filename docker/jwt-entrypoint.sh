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

# NOTE: this container serves plain HTTP only. TLS is terminated by the host-level
# Caddy reverse proxy (deploy/proxy/Caddyfile). There is deliberately no cert check
# here — the previous one was hardcoded to api.buildmyclub.co.uk and would silently
# mis-configure any other environment.

exec "$@"
