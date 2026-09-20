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
COPY docker/telemetry-generate.sh /usr/local/bin/telemetry-generate.sh
COPY docker/competition-provision-instances.sh /usr/local/bin/competition-provision-instances.sh
COPY docker/competition-process-rounds.sh /usr/local/bin/competition-process-rounds.sh
COPY docker/competition-send-round-reminders.sh /usr/local/bin/competition-send-round-reminders.sh
COPY docker/competition-auto-fill-spoof-entrants.sh /usr/local/bin/competition-auto-fill-spoof-entrants.sh
COPY docker/messenger-consume.sh /usr/local/bin/messenger-consume.sh

# Cron schedule (Alpine busybox crond, /var/spool/cron/crontabs/root):
#   :00 — pool-warm:              top up player/staff/scout pool for all 19 countries
#   :30 — worldpack-warm:         rebuild NPC league caches (consumes from the freshly stocked pool)
#   every 5 min — leaderboards-generate: recompute scores/ranks + invalidate leaderboard cache
#   every 15 min — post-community-stat-tick: checks GameConfig's admin-editable
#     auto-post schedule (enabled + interval-hours) per period tier and runs
#     app:post-community-stat for whichever periods are due. Cadence itself is
#     configured at /admin?routeName=admin_social_connections, not here — this
#     entry's own schedule is just the tick granularity, not the posting cadence.
#   every 15 min — telemetry-generate: recompute the landing page's 24h pyramid
#     activity aggregate (fixtures simulated, capital deployed) from recent
#     SyncRecord payloads, into LiveTelemetrySnapshot.
#   every 10 min — competition-provision-instances: ensures every active
#     CompetitionTemplate has an open (REGISTERING) instance. Not time-precision
#     sensitive — capacity-fill itself locks an instance synchronously, this just
#     replenishes the slot that leaves behind.
#   every 1 min — competition-process-rounds: executes any CompetitionRound whose
#     scheduledAt is due. Matches must fire close to their scheduled timestamp,
#     unlike the other entries here, hence the tighter cadence.
#   every 5 min — competition-send-round-reminders: pushes a "starting soon" notice
#     for any CompetitionRound due within its 15-minute reminder lead time (see
#     CompetitionRoundReminderService). Slack in the lead time means this doesn't
#     need process-rounds' 1-minute cadence.
#   every 1 min — competition-auto-fill-spoof-entrants: dev/testing convenience —
#     fills any REGISTERING instance whose template opted in
#     (CompetitionTemplate::$autoFillSpoofEntrants) with spoof entrants once its
#     configured delay (minimum 5 min) has passed since the first real entrant
#     registered, so a solo tester isn't stuck waiting on a full bracket.
#   every 1 min — messenger-consume: drains the async Messenger transport (push
#     notifications + admin-broadcast push-audience resolution). Same tight
#     cadence as competition-process-rounds since a delayed push is a stale one.
# pool-warm/worldpack-warm run every 6 hours with 256 MB PHP memory limit (set inside each script).
RUN mkdir -p /var/spool/cron/crontabs \
 && printf '%s\n' \
    '0  */6 * * * /usr/local/bin/pool-warm.sh              >> /var/log/pool-cron.log         2>&1' \
    '30 */6 * * * /usr/local/bin/worldpack-warm.sh         >> /var/log/worldpack-cron.log     2>&1' \
    '*/5 * * * *  /usr/local/bin/leaderboards-generate.sh  >> /var/log/leaderboards-cron.log  2>&1' \
    '*/15 * * * * /usr/local/bin/post-community-stat-tick.sh >> /var/log/post-stat-cron.log 2>&1' \
    '*/15 * * * * /usr/local/bin/telemetry-generate.sh     >> /var/log/telemetry-cron.log     2>&1' \
    '*/10 * * * * /usr/local/bin/competition-provision-instances.sh >> /var/log/competition-provision-cron.log 2>&1' \
    '* * * * *    /usr/local/bin/competition-process-rounds.sh      >> /var/log/competition-process-cron.log   2>&1' \
    '*/5 * * * *  /usr/local/bin/competition-send-round-reminders.sh >> /var/log/competition-reminders-cron.log 2>&1' \
    '* * * * *    /usr/local/bin/competition-auto-fill-spoof-entrants.sh >> /var/log/competition-auto-fill-cron.log 2>&1' \
    '* * * * *    /usr/local/bin/messenger-consume.sh               >> /var/log/messenger-consume-cron.log     2>&1' \
    > /var/spool/cron/crontabs/root \
 && chmod 0600 /var/spool/cron/crontabs/root

RUN chmod +x /usr/local/bin/jwt-entrypoint.sh /usr/local/bin/pool-warm.sh /usr/local/bin/worldpack-warm.sh /usr/local/bin/leaderboards-generate.sh /usr/local/bin/post-community-stat.sh /usr/local/bin/post-community-stat-tick.sh /usr/local/bin/telemetry-generate.sh /usr/local/bin/competition-provision-instances.sh /usr/local/bin/competition-process-rounds.sh /usr/local/bin/competition-send-round-reminders.sh /usr/local/bin/competition-auto-fill-spoof-entrants.sh /usr/local/bin/messenger-consume.sh
RUN mkdir -p var/cache var/log && chown -R www-data:www-data var/
RUN mkdir -p public/uploads/facilities && chown -R www-data:www-data public/uploads

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/jwt-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
