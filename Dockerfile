FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    postgresql-dev \
    libpq \
    icu-dev \
    oniguruma-dev \
    supervisor \
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
COPY docker/nginx-http-only.conf /etc/nginx/nginx-http-only.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/jwt-entrypoint.sh /usr/local/bin/jwt-entrypoint.sh
COPY docker/worldpack-warm.sh /usr/local/bin/worldpack-warm.sh

# Register the worldpack cron: rebuild all country caches every 6 hours.
# Alpine's busybox crond reads per-user crontabs from /var/spool/cron/crontabs/.
RUN mkdir -p /var/spool/cron/crontabs \
 && echo '0 */6 * * * /usr/local/bin/worldpack-warm.sh >> /var/log/worldpack-cron.log 2>&1' \
    > /var/spool/cron/crontabs/root \
 && chmod 0600 /var/spool/cron/crontabs/root

RUN chmod +x /usr/local/bin/jwt-entrypoint.sh /usr/local/bin/worldpack-warm.sh
RUN mkdir -p var/cache var/log && chown -R www-data:www-data var/

EXPOSE 80 443

ENTRYPOINT ["/usr/local/bin/jwt-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
