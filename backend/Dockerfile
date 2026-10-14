# FrankenPHP: a production PHP web server that answers many requests at once (php artisan serve
# handles one at a time, so a slow menu import would hold up every shop's WhatsApp messages).
# The image also has the normal `php` command, used by the queue, the scheduler and migrations.
FROM dunglas/frankenphp:1-php8.4-alpine

RUN apk add --no-cache mariadb-client mariadb-connector-c git unzip \
    && install-php-extensions pdo_mysql mbstring zip intl bcmath pcntl opcache

# Nightly backups call `mysqldump`; Alpine ships it as mariadb-dump on newer releases.
RUN [ -x /usr/bin/mysqldump ] || ln -s /usr/bin/mariadb-dump /usr/bin/mysqldump

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

RUN chmod +x docker/entrypoint.sh docker/queue.sh

ENV PORT=8000
EXPOSE 8000

CMD ["docker/entrypoint.sh"]
