# Dive Training
Gestion de la formation de plongeurs en piscine : sections, planning de réservation des lignes d'eau (plusieurs bassins), suivi « qui plonge avec bouteille ».

Stack : Symfony 7 (API JWT) · Vue 3 + Vite + Pinia · PostgreSQL · Docker · Traefik. Proposition détaillée : [docs/PROPOSITION.md](docs/PROPOSITION.md).
PWA installable (service worker, manifeste, icônes) : le shell de l'app fonctionne hors ligne, l'API n'est jamais mise en cache. Nécessite HTTPS (ou localhost).

## Comptes et rôles
Seuls les encadrants ont un compte (les élèves sont de simples fiches).
- **Encadrant** : sections, élèves, demandes de séances/réservations, case « bouteille ».
- **Référent** (`ROLE_REFERENT`) : + valide/refuse les réservations, gère bassins et lignes d'eau.
- **Administrateur** (`ROLE_ADMIN`) : + crée/désactive les comptes, change les rôles, réinitialise les mots de passe (menu « Comptes »).

**Premier compte** : renseignez `ADMIN_EMAIL` / `ADMIN_PASSWORD` dans `.env` ; il est créé automatiquement au démarrage du backend si la base n'a aucun compte. Ensuite tout se fait dans l'application.
L'e-mail de connexion ignore majuscules et espaces. Chacun peut changer son mot de passe (clic sur son nom).

En secours, en ligne de commande :
```
docker compose exec backend php bin/console app:user:create jean@club.fr "Jean" 'motdepasse' ROLE_REFERENT
docker compose exec backend php bin/console app:user:password jean@club.fr 'nouveaumotdepasse'
```

**Diagnostic** : `https://<DOMAIN>/api/health` doit renvoyer du JSON (`api`, `database`, `jwtKeys`, `hasUsers`). Une page HTML ou une erreur du front veut dire que Traefik n'envoie pas `/api` au backend.

## Lancer (Docker + Traefik)
```
cp .env.example .env
docker compose up -d --build          # se branche sur VOTRE Traefik (voir .env)
```
Traefik n'est pas fourni : renseignez dans `.env` le réseau (`TRAEFIK_NETWORK`), l'entrypoint (`TRAEFIK_ENTRYPOINT`) et le resolver (`CERT_RESOLVER`) de votre Traefik, ainsi que `DOMAIN`. En production : `docker compose -f docker-compose.yml up -d --build`.

## Développement sans Docker
```
cd backend && composer install && php bin/console doctrine:migrations:migrate && symfony server:start   # ou php -S localhost:8000 -t public
cd frontend && npm install && npm run dev
```
Tests backend : `cd backend && vendor/bin/phpunit` (SQLite, règles de conflit et rôles).

## API principale
`POST /api/login_check` · `GET /api/me` · `GET|POST /api/pools` (+`/lanes`) · `GET|POST /api/sections` (+`/divers`) · `GET|POST /api/divers` ·
`POST /api/sessions` (séance + demandes de réservation) · `PUT /api/sessions/{id}/participants/{diverId}` (`usesTank`) ·
`GET /api/calendar?from&to` · `POST /api/reservations/{id}/confirm|reject` (référent).

Règle : une ligne d'eau ne peut avoir deux réservations **confirmées** qui se chevauchent (contrôle à la demande et re-contrôle verrouillé à la validation).
