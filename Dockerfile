FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && apt-get purge -y --auto-remove libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Serve public/ as the web root; src/, sql/, scripts/ stay outside it and are
# never reachable over HTTP, matching the app's existing security design.
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' \
    /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf

COPY . /var/www/html/

EXPOSE 80
