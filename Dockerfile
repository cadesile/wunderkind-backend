FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    postgresql-dev \
    libpq \
    icu-dev \
    oniguruma-dev \
    supervisor \
    su-exec \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        intl \
        opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/jwt-entrypoint.sh /usr/local/bin/jwt-entrypoint.sh
COPY docker/pool-warm.sh /usr/local/bin/pool-warm.sh
COPY docker/worldpack-warm.sh /usr/local/bin/worldpack-warm.sh
COPY docker/leaderboards-generate.sh /usr/local/bin/leaderboards-generate.sh
COPY docker/post-community-stat.sh /usr/local/bin/post-community-stat.sh
COPY docker/post-community-stat-tick.sh /usr/local/bin/post-community-stat-tick.sh

# Cron schedule (Alpine busybox crond, /var/spool/cron/crontabs/root):
#   :00 — pool-warm:              top up player/staff/scout pool for all 19 countries
#   :30 — worldpack-warm:         rebuild NPC league caches (consumes from the freshly stocked pool)
#   every 5 min — leaderboards-generate: recompute scores/ranks + invalidate leaderboard cache
#   every 15 min — post-community-stat-tick: checks GameConfig's admin-editable
#     auto-post schedule (enabled + interval-hours) per period tier and runs
#     app:post-community-stat for whichever periods are due. Cadence itself is
#     configured at /admin?routeName=admin_social_connections, not here — this
#     entry's own schedule is just the tick granularity, not the posting cadence.
# pool-warm/worldpack-warm run every 6 hours with 256 MB PHP memory limit (set inside each script).
RUN mkdir -p /var/spool/cron/crontabs \
 && printf '%s\n' \
    '0  */6 * * * /usr/local/bin/pool-warm.sh              >> /var/log/pool-cron.log         2>&1' \
    '30 */6 * * * /usr/local/bin/worldpack-warm.sh         >> /var/log/worldpack-cron.log     2>&1' \
    '*/5 * * * *  /usr/local/bin/leaderboards-generate.sh  >> /var/log/leaderboards-cron.log  2>&1' \
    '*/15 * * * * /usr/local/bin/post-community-stat-tick.sh >> /var/log/post-stat-cron.log 2>&1' \
    > /var/spool/cron/crontabs/root \
 && chmod 0600 /var/spool/cron/crontabs/root

RUN chmod +x /usr/local/bin/jwt-entrypoint.sh /usr/local/bin/pool-warm.sh /usr/local/bin/worldpack-warm.sh /usr/local/bin/leaderboards-generate.sh /usr/local/bin/post-community-stat.sh /usr/local/bin/post-community-stat-tick.sh
RUN mkdir -p var/cache var/log && chown -R www-data:www-data var/
RUN mkdir -p public/uploads/facilities && chown -R www-data:www-data public/uploads

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/jwt-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
