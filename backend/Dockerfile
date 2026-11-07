FROM php:8.3-fpm-alpine
RUN apk add --no-cache nginx supervisor postgresql-dev icu-dev libzip-dev git unzip openssl bash \
 && docker-php-ext-install pdo_pgsql intl zip opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html
ARG COMPOSER_FLAGS="--no-dev"
COPY composer.json composer.lock ./
RUN composer install $COMPOSER_FLAGS --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize $( [ "$COMPOSER_FLAGS" = "--no-dev" ] && echo --classmap-authoritative )
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
