FROM php:8.3-fpm-alpine
RUN apk add --no-cache nginx supervisor postgresql-dev icu-dev libzip-dev git unzip \
 && docker-php-ext-install pdo_pgsql intl zip opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN if [ -f composer.json ]; then composer install --no-dev --optimize-autoloader --no-scripts; fi
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
EXPOSE 80
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
