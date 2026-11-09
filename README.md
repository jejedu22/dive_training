# Dive Training
Gestion de la formation de plongeurs en piscine : sections, planning de réservation des lignes d'eau (plusieurs bassins), suivi « qui plonge avec bouteille ».

Stack : Symfony 7 (API JWT) · Vue 3 + Vite + Pinia · PostgreSQL · Docker · Traefik. Proposition détaillée : [docs/PROPOSITION.md](docs/PROPOSITION.md).

## Comptes et rôles
Seuls les encadrants ont un compte (les élèves sont de simples fiches).
- **Encadrant** : sections, élèves, demandes de séances/réservations, case « bouteille ».
- **Référent** (`ROLE_REFERENT`) : + valide/refuse les réservations, gère bassins et lignes d'eau.
- **Administrateur** (`ROLE_ADMIN`) : + crée/désactive les comptes, change les rôles, réinitialise les mots de passe (menu « Comptes »).

**Premier compte** : renseignez `ADMIN_EMAIL` / `ADMIN_PASSWORD` dans `.env` ; au démarrage du backend, s'il n'existe aucun administrateur actif, ce compte est créé (ou, s'il existe déjà, promu administrateur sans changer son mot de passe). Ensuite tout se fait dans l'application.
L'e-mail de connexion ignore majuscules et espaces. Chacun peut changer son mot de passe (clic sur son nom).

En secours, en ligne de commande :
```
docker compose exec backend php bin/console app:user:create jean@club.fr "Jean" 'motdepasse' ROLE_REFERENT
docker compose exec backend php bin/console app:user:password jean@club.fr 'nouveaumotdepasse'
```

**Mon compte** (clic sur son nom) : nom, e-mail (avec le mot de passe actuel), téléphone, couleur de l'avatar ; préférences enregistrées sur le compte (page d'accueil, vue du planning Jour/Mois, « seulement mes accès ») ; mes sections ; mot de passe ; installation et version de l'application.

**Comptes** (administrateurs) : recherche et filtres, sections encadrées et dernière connexion de chacun, modification (nom, e-mail, téléphone, rôle), mot de passe généré automatiquement avec identifiants à copier, désactivation.

**L'administrateur n'arrive pas à se connecter ?**
```
docker compose logs backend | grep -i admin        # diagnostic affiché à chaque démarrage
docker compose exec backend php bin/console app:user:list
docker compose exec backend php bin/console app:admin:reset vous@club.fr 'nouveaumotdepasse'
```
`app:admin:reset` crée le compte s'il n'existe pas, sinon le réactive, lui donne le rôle administrateur et remplace son mot de passe (sans arguments : valeurs de `ADMIN_EMAIL` / `ADMIN_PASSWORD`). `ADMIN_PASSWORD` ne sert qu'à la création du tout premier compte ; un `$` dans `.env` doit être protégé par des apostrophes.

**Diagnostic** : `https://<DOMAIN>/api/health` doit renvoyer du JSON (`api`, `database`, `jwtKeys`, `hasUsers`). Une page HTML ou une erreur du front veut dire que Traefik n'envoie pas `/api` au backend.

## Créneaux du club et lignes d'eau
Menu **Créneaux** (référents) : les plages où le club dispose de la piscine, ex. **lundi et mercredi 20:00–22:00**, avec une période de validité facultative (la saison).
- **Lignes ouvertes** : pour chaque créneau, toutes les lignes ou une sélection (ex. fosse lignes 1 à 4 + grand bassin lignes 1 à 3).
- **Plan d'occupation** : pour chaque ligne ouverte, la section qui l'occupe (ex. Ligne 1 → Niveau 1), ou « libre ». Il s'applique à chaque occurrence du créneau.
- **Création d'une séance** : on choisit la section, puis **un créneau du club parmi les prochains où elle a accès à une ligne** (pas d'horaires libres), puis les lignes ; les lignes attribuées à la section sont présélectionnées. Seul un référent peut créer une séance exceptionnelle hors créneau (et s'il n'existe aucun créneau, les horaires sont libres).
- **Séances** : une ligne attribuée à la section est **confirmée directement** ; une ligne libre attend la **validation d'un référent** ; une ligne fermée ou attribuée à une autre section est refusée. Un référent peut passer outre.
- **Exceptions** (bouton « Ajouter une exception » sur chaque créneau) : pour certaines dates, le plan habituel change.
  - **Quand** : chaque mois (1er, 2e, 3e, 4e ou dernier lundi…, avec période facultative) ou une date précise. Une date précise l'emporte sur une règle mensuelle.
  - **Quoi** : annuler le créneau ce jour-là (piscine fermée…), ou, ligne par ligne, la réattribuer à une section ou la libérer (« Tout le bassin → » pour aller vite). Les lignes non modifiées gardent le plan habituel.
  - Exemple : « le 1er lundi de chaque mois, Fosse → Apnée ».
  - Les séances déjà prévues par une section qui perd sa ligne ce jour-là sont **annulées automatiquement** (statut « Annulée » avec le motif). Supprimer l'exception rétablit le plan habituel, sans rétablir ces séances.
  - Repère ⚑ sur la page Lignes d'eau et le planning ; créneau annulé barré.
- Les heures sont celles du club (`APP_TIMEZONE`, Europe/Paris par défaut), heure d'été comprise.

**Bassins** (référents) : bouton « Modifier » sur chaque bassin pour le renommer, renommer les lignes, indiquer leur profondeur, changer leur ordre, en ajouter ou en supprimer (refusé si la ligne a des réservations à venir), ou supprimer le bassin.

**Couleurs** : chaque section a une couleur (attribuée automatiquement, modifiable dans **Sections → Gérer**), reprise sur le planning, la page Lignes d'eau et le plan d'occupation. Séance pleine = confirmée, rayée = en attente de validation.

Page **Planning**, vue **Mois** : calendrier du mois avec les créneaux et les séances de chaque jour (couleur de la section, ⚠ hors créneau) ; un clic sur un jour ouvre la vue **Jour**. Vue **Jour** : uniquement les créneaux du club du jour (une frise par créneau avec ses lignes ouvertes et la section attribuée) ; les séances exceptionnelles hors créneau sont listées à part. Les flèches passent d'un jour de créneau au suivant.

Page **Lignes d'eau** (accueil) : semaine par semaine, chaque créneau avec ses lignes ouvertes, la section attribuée, les séances réservées, et un résumé **« Mes accès »** pour les sections que l'on encadre (on s'ajoute comme encadrant dans **Sections**). Bouton « Réserver » qui préremplit la séance.

## Application mobile (PWA)
L'application est une PWA : installable sur téléphone et ordinateur. Le bouton **Installer** (en-tête, page de connexion, menu « Plus » sur mobile, « Mon compte ») ouvre l'invite du navigateur quand elle existe (Chrome, Edge, Android), sinon une fenêtre explique la marche à suivre (iPhone : Safari → Partager → « Sur l'écran d'accueil »), interface adaptée au mobile avec barre d'onglets, fonctionne hors ligne pour l'affichage (les données nécessitent le réseau). Installation possible uniquement en HTTPS (mode production ou développement).

## Lancer (Docker + Traefik)
```
cp .env.example .env
docker compose up -d --build          # PRODUCTION (par défaut), branchée sur VOTRE Traefik
```
Traefik n'est pas fourni : renseignez dans `.env` le réseau (`TRAEFIK_NETWORK`), l'entrypoint (`TRAEFIK_ENTRYPOINT`) et le resolver (`CERT_RESOLVER`) de votre Traefik, ainsi que `DOMAIN`.

Mise à jour du serveur :
```
git pull
docker compose up -d --build
```

Mode développement (rechargement à chaud, erreurs détaillées) — à ne pas utiliser sur le serveur :
```
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

## Développement sans Docker
```
cd backend && composer install && php bin/console doctrine:migrations:migrate && symfony server:start   # ou php -S localhost:8000 -t public
cd frontend && npm install && npm run dev
```
Tests backend : `cd backend && vendor/bin/phpunit` (SQLite, règles de conflit et rôles).

## API principale
`POST /api/login_check` · `GET /api/me` · `GET|POST /api/pools` (+`/lanes`) · `GET|POST /api/sections` (+`/divers`) · `GET|POST /api/divers` ·
`POST /api/sessions` (séance + demandes de réservation) · `PUT /api/sessions/{id}/participants/{diverId}` (`usesTank`) ·
`GET|POST|PUT|DELETE /api/slots` · `PUT /api/slots/{id}/allocations` · `POST /api/slots/{id}/overrides` · `PUT|DELETE /api/overrides/{id}` · `GET /api/occupancy?from&to` · `GET /api/instructors` · `GET /api/slots/occurrences?from&to` · `GET /api/calendar?from&to` · `POST /api/reservations/{id}/confirm|reject` (référent).

Règle : une ligne d'eau ne peut avoir deux réservations **confirmées** qui se chevauchent (contrôle à la demande et re-contrôle verrouillé à la validation).
