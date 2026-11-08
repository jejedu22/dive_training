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

# En dev, vendor/ est un volume qui survit aux reconstructions : on resynchronise les dépendances
if [ "${APP_ENV}" = "dev" ] && [ -f composer.lock ]; then
  composer install --no-interaction --no-scripts --no-progress
fi

mkdir -p var && chown -R www-data:www-data var
# La base peut mettre quelques secondes à accepter les connexions
for i in $(seq 1 30); do
  php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1 && break
  echo "En attente de la base de données ($i/30)…"; sleep 2
done
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console app:user:ensure-admin
php bin/console cache:warmup
chown -R www-data:www-data var
exec "$@"
