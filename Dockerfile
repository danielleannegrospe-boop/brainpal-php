@'
FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/brainpal.conf \
    && a2enconf brainpal

RUN printf '%s\n' \
    '#!/bin/bash' \
    'set -e' \
    'PORT="${PORT:-10000}"' \
    'sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf' \
    'sed -ri "s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf' \
    'exec apache2-foreground' \
    > /usr/local/bin/start-brainpal.sh \
    && chmod +x /usr/local/bin/start-brainpal.sh

EXPOSE 10000

CMD ["/usr/local/bin/start-brainpal.sh"]
'@ | Set-Content -Path .\Dockerfile -Encoding UTF8