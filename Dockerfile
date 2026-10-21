# PHP is pinned to an exact patch release, not the floating `8.5` tag, so a rebuild
# can never silently land on a different (or older, from a cached base) PHP than the
# one this image was verified against. 8.5.12 carries the 2026-10-21 security fixes
# for GHSA-w8hw-w9fx-j79w / GHSA-6wmf-4rv5-p3rp (Core: state corruption via bailout).
# Bump this deliberately when a new patch ships; `.lando.yml` tracks the same branch.
FROM php:8.5.12-fpm-alpine

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
        intl

# opcache is NOT in the list above, and must not be added back. PHP 8.5 compiles Zend
# OPcache in statically — `php -m` already lists it on the bare base image — so
# `docker-php-ext-install opcache` builds zero modules and the whole RUN step dies with
# `cp: can't stat 'modules/*': No such file or directory`. That is what broke the first
# 8.5 build. It stays enabled regardless: `opcache.enable` defaults to On for FPM
# (`opcache.enable_cli` stays Off, as before).

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/jwt-entrypoint.sh /usr/local/bin/jwt-entrypoint.sh
COPY docker/leaderboards-generate.sh /usr/local/bin/leaderboards-generate.sh
COPY docker/post-community-stat.sh /usr/local/bin/post-community-stat.sh
COPY docker/post-community-stat-tick.sh /usr/local/bin/post-community-stat-tick.sh
COPY docker/telemetry-generate.sh /usr/local/bin/telemetry-generate.sh
COPY docker/spotlight-generate.sh /usr/local/bin/spotlight-generate.sh
COPY docker/reputation-decay.sh /usr/local/bin/reputation-decay.sh
COPY docker/competition-provision-instances.sh /usr/local/bin/competition-provision-instances.sh
COPY docker/competition-draw-rounds.sh /usr/local/bin/competition-draw-rounds.sh
COPY docker/competition-resolve-rounds.sh /usr/local/bin/competition-resolve-rounds.sh
COPY docker/competition-send-round-reminders.sh /usr/local/bin/competition-send-round-reminders.sh
COPY docker/competition-auto-fill-spoof-entrants.sh /usr/local/bin/competition-auto-fill-spoof-entrants.sh
COPY docker/messenger-consume.sh /usr/local/bin/messenger-consume.sh
COPY docker/worldpack-consume.sh /usr/local/bin/worldpack-consume.sh

# Cron schedule (Alpine busybox crond, /var/spool/cron/crontabs/root):
#   every 5 min — leaderboards-generate: recompute scores/ranks + invalidate leaderboard cache
#   every 15 min — post-community-stat-tick: checks GameConfig's admin-editable
#     auto-post schedule (enabled + interval-hours) per period tier and runs
#     app:post-community-stat for whichever periods are due. Cadence itself is
#     configured at /admin?routeName=admin_social_connections, not here — this
#     entry's own schedule is just the tick granularity, not the posting cadence.
#   every 15 min — telemetry-generate: recompute the landing page's 24h pyramid
#     activity aggregate (fixtures simulated, capital deployed) from recent
#     SyncRecord payloads, into LiveTelemetrySnapshot.
#   every 12h — spotlight-generate: pick a new landing page "Club Spotlight"
#     from the 5 most active clubs in the trailing 12h window and notify its
#     owner (in-game inbox + push), into ClubSpotlight.
#   daily 03:20 UTC — reputation-decay: charges every non-spoof club 5 reputation
#     per whole day since it last synced (app:reputation:decay). Idempotent and
#     catch-up-safe via a per-club anchor, so a missed night is settled on the next
#     run rather than lost. Scheduled off the hour, away from the 00:00/12:00
#     spotlight run, so the two don't contend. NOTE: the first run on an
#     environment that has never decayed settles every dormant club at once and
#     touches far more rows than a nightly run — dry-run it first.
#   every 10 min — competition-provision-instances: ensures every active
#     CompetitionTemplate has an open (REGISTERING) instance. Not time-precision
#     sensitive — capacity-fill itself locks an instance synchronously, this just
#     replenishes the slot that leaves behind.
#   every 1 min — competition-draw-rounds: draws any CompetitionRound whose
#     scheduledAt (draw due-time) is due. Matches must fire close to their
#     scheduled timestamp, unlike most other entries here, hence the tighter
#     cadence.
#   every 1 min — competition-resolve-rounds: publishes results for any
#     CompetitionRound whose matchesResolveAt (resolve due-time) is due — the
#     decoupled second half of what competition-draw-rounds used to do in one
#     fused pass. Same tight cadence, same reasoning.
#   every 5 min — competition-send-round-reminders: pushes a "results incoming"
#     notice for any DRAWN CompetitionRound due within its 15-minute reminder
#     lead time (see CompetitionRoundReminderService). Slack in the lead time
#     means this doesn't need the draw/resolve entries' 1-minute cadence.
#   every 1 min — competition-auto-fill-spoof-entrants: dev/testing convenience —
#     fills any REGISTERING instance whose template opted in
#     (CompetitionTemplate::$autoFillSpoofEntrants) with spoof entrants once its
#     configured delay (minimum 5 min) has passed since the first real entrant
#     registered, so a solo tester isn't stuck waiting on a full bracket.
#   every 1 min — messenger-consume: drains the async Messenger transport (push
#     notifications + admin-broadcast push-audience resolution). Same tight
#     cadence as competition-draw-rounds/-resolve-rounds since a delayed push is
#     a stale one.
#   every 1 min — worldpack-consume: drains the separate `worldpack` Messenger
#     transport (World Pack Cache tier/club generation). Split from
#     messenger-consume.sh's `async` transport deliberately — a country
#     regenerate can queue 100+ DB-heavy club-generation messages, and sharing
#     one queue with push notifications meant each delayed the other. Own
#     lockfile/log, so a slow generation batch never blocks push delivery.
RUN mkdir -p /var/spool/cron/crontabs \
 && printf '%s\n' \
    '*/5 * * * *  /usr/local/bin/leaderboards-generate.sh  >> /var/log/leaderboards-cron.log  2>&1' \
    '*/15 * * * * /usr/local/bin/post-community-stat-tick.sh >> /var/log/post-stat-cron.log 2>&1' \
    '*/15 * * * * /usr/local/bin/telemetry-generate.sh     >> /var/log/telemetry-cron.log     2>&1' \
    '0 */12 * * * /usr/local/bin/spotlight-generate.sh     >> /var/log/spotlight-cron.log     2>&1' \
    '20 3 * * *   /usr/local/bin/reputation-decay.sh       >> /var/log/reputation-decay-cron.log 2>&1' \
    '*/10 * * * * /usr/local/bin/competition-provision-instances.sh >> /var/log/competition-provision-cron.log 2>&1' \
    '* * * * *    /usr/local/bin/competition-draw-rounds.sh         >> /var/log/competition-draw-cron.log      2>&1' \
    '* * * * *    /usr/local/bin/competition-resolve-rounds.sh      >> /var/log/competition-resolve-cron.log   2>&1' \
    '*/5 * * * *  /usr/local/bin/competition-send-round-reminders.sh >> /var/log/competition-reminders-cron.log 2>&1' \
    '* * * * *    /usr/local/bin/competition-auto-fill-spoof-entrants.sh >> /var/log/competition-auto-fill-cron.log 2>&1' \
    '* * * * *    /usr/local/bin/messenger-consume.sh               >> /var/log/messenger-consume-cron.log     2>&1' \
    '* * * * *    /usr/local/bin/worldpack-consume.sh                >> /var/log/worldpack-consume-cron.log    2>&1' \
    > /var/spool/cron/crontabs/root \
 && chmod 0600 /var/spool/cron/crontabs/root

RUN chmod +x /usr/local/bin/jwt-entrypoint.sh /usr/local/bin/leaderboards-generate.sh /usr/local/bin/post-community-stat.sh /usr/local/bin/post-community-stat-tick.sh /usr/local/bin/telemetry-generate.sh /usr/local/bin/spotlight-generate.sh /usr/local/bin/reputation-decay.sh /usr/local/bin/competition-provision-instances.sh /usr/local/bin/competition-draw-rounds.sh /usr/local/bin/competition-resolve-rounds.sh /usr/local/bin/competition-send-round-reminders.sh /usr/local/bin/competition-auto-fill-spoof-entrants.sh /usr/local/bin/messenger-consume.sh /usr/local/bin/worldpack-consume.sh
RUN mkdir -p var/cache var/log && chown -R www-data:www-data var/
RUN mkdir -p public/uploads/facilities && chown -R www-data:www-data public/uploads

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/jwt-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
