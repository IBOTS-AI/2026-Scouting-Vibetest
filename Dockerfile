FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
RUN printf 'upload_max_filesize=256M\npost_max_size=260M\nmemory_limit=768M\nmax_execution_time=180\n' > /usr/local/etc/php/conf.d/scouting-uploads.ini
COPY public/ /var/www/html/
COPY sql/ /var/www/sql/
COPY fixtures/ /var/www/fixtures/
RUN chown -R www-data:www-data /var/www/html
