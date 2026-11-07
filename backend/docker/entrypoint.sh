#!/bin/bash
set -e
cd /var/www/html

# Clés JWT : générées une fois, persistées dans le volume monté sur config/jwt
if [ ! -f config/jwt/private.pem ]; then
  mkdir -p config/jwt
  openssl genpkey -algorithm RSA -out config/jwt/private.pem -aes256 -pass "pass:${JWT_PASSPHRASE}" -pkeyopt rsa_keygen_bits:4096
  openssl pkey -in config/jwt/private.pem -passin "pass:${JWT_PASSPHRASE}" -pubout -out config/jwt/public.pem
  chmod 640 config/jwt/*.pem
  chgrp www-data config/jwt/*.pem
fi

mkdir -p var && chown -R www-data:www-data var
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console cache:warmup
chown -R www-data:www-data var
exec "$@"
