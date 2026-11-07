# Dive Training
Gestion de la formation de plongeurs en piscine : sections, planning de réservation des lignes d'eau (plusieurs bassins), suivi « qui plonge avec bouteille ».

Stack : Symfony 7 (API JWT) · Vue 3 + Vite + Pinia · PostgreSQL · Docker · Traefik. Proposition détaillée : [docs/PROPOSITION.md](docs/PROPOSITION.md).

## Rôles
- **Encadrant** (`ROLE_INSTRUCTOR`, seuls utilisateurs de l'app) : sections, élèves, demandes de séances/réservations, case « bouteille ».
- **Référent** (`ROLE_REFERENT`) : valide/refuse les réservations, crée bassins et lignes d'eau.

## Lancer (Docker + Traefik)
```
cp .env.example .env
docker compose up -d --build          # dev : http://dive.localhost
docker compose exec backend php bin/console app:user:create ref@club.fr "Référent" 'motdepasse' ROLE_REFERENT
```
Production : `docker compose -f docker-compose.yml up -d --build` avec `DOMAIN` et `ACME_EMAIL` réels (HTTPS Let's Encrypt).

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
