# Dive Training
Gestion de la formation de plongeurs en piscine : sections, planning de réservation des lignes d'eau (plusieurs bassins), suivi « qui plonge avec bouteille ».

Stack : Symfony 7 (API JWT) · Vue 3 + Vite + Pinia · PostgreSQL · Docker · Traefik. Proposition détaillée : [docs/PROPOSITION.md](docs/PROPOSITION.md).

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

## Créneaux du club et lignes d'eau
Menu **Créneaux** (référents) : les plages où le club dispose de la piscine, ex. **lundi et mercredi 20:00–22:00**, avec une période de validité facultative (la saison).
- **Lignes ouvertes** : pour chaque créneau, toutes les lignes ou une sélection (ex. fosse lignes 1 à 4 + grand bassin lignes 1 à 3).
- **Plan d'occupation** : pour chaque ligne ouverte, la section qui l'occupe (ex. Ligne 1 → Niveau 1), ou « libre ». Il s'applique à chaque occurrence du créneau.
- **Création d'une séance** : on choisit la section, puis **un créneau du club parmi les prochains où elle a accès à une ligne** (pas d'horaires libres), puis les lignes ; les lignes attribuées à la section sont présélectionnées. Seul un référent peut créer une séance exceptionnelle hors créneau (et s'il n'existe aucun créneau, les horaires sont libres).
- **Séances** : une ligne attribuée à la section est **confirmée directement** ; une ligne libre attend la **validation d'un référent** ; une ligne fermée ou attribuée à une autre section est refusée. Un référent peut passer outre.
- Les heures sont celles du club (`APP_TIMEZONE`, Europe/Paris par défaut), heure d'été comprise.

Page **Lignes d'eau** (accueil) : semaine par semaine, chaque créneau avec ses lignes ouvertes, la section attribuée, les séances réservées, et un résumé **« Mes accès »** pour les sections que l'on encadre (on s'ajoute comme encadrant dans **Sections**). Bouton « Réserver » qui préremplit la séance.

## Application mobile (PWA)
L'application est une PWA : installable sur téléphone et ordinateur (bouton **Installer** dans l'en-tête ou dans « Mon compte » ; sur iPhone : Safari → Partager → « Sur l'écran d'accueil »), interface adaptée au mobile avec barre d'onglets, fonctionne hors ligne pour l'affichage (les données nécessitent le réseau). Installation possible uniquement en HTTPS et avec le front de production (`docker compose -f docker-compose.yml …`).

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
`GET|POST|PUT|DELETE /api/slots` · `PUT /api/slots/{id}/allocations` · `GET /api/occupancy?from&to` · `GET /api/instructors` · `GET /api/slots/occurrences?from&to` · `GET /api/calendar?from&to` · `POST /api/reservations/{id}/confirm|reject` (référent).

Règle : une ligne d'eau ne peut avoir deux réservations **confirmées** qui se chevauchent (contrôle à la demande et re-contrôle verrouillé à la validation).
