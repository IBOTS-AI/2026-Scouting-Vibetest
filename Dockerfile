FROM php:8.3-apache
RUN docker-php-ext-install pdo_pgsql
COPY public/ /var/www/html/
COPY sql/ /var/www/sql/
RUN chown -R www-data:www-data /var/www/html
