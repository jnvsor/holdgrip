FROM node:latest AS node
WORKDIR /var/www
COPY ./package.json ./package-lock.json /var/www/
COPY ./sass /var/www/sass
RUN npm ci && npm run build

FROM composer:latest AS composer
WORKDIR /var/www
COPY ./composer.json ./composer.lock /var/www/
COPY ./src /var/www/src
RUN composer install --no-interaction --optimize-autoloader --no-dev

FROM php:8.3-apache
WORKDIR /var/www

RUN apt-get update && apt-get install -y cron git libpq-dev
RUN docker-php-ext-install pdo pdo_pgsql
RUN a2enmod rewrite
RUN a2enmod headers
RUN echo "*/30 * * * * /usr/local/bin/php /var/www/cron.php >/proc/1/fd/1 2>/proc/1/fd/2" > /etc/cron.d/holdgrip
RUN chmod 0644 /etc/cron.d/holdgrip
RUN crontab /etc/cron.d/holdgrip

COPY ./ /var/www/
COPY --from=composer /var/www/ ./
COPY --from=node /var/www/html/style.css ./html/
RUN chmod -R 0700 /var/www/var
RUN chown -R www-data:www-data /var/www/var

EXPOSE 80
