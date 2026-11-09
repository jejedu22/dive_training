# Dive Training
Planning d'occupation des lignes d'eau du club de plongée : créneaux, plan habituel et exceptions par date, sections, suivi « qui fait bouteille ».

Stack : Symfony 7 (API JWT) · Vue 3 + Vite + Pinia · PostgreSQL · Docker · Traefik. Proposition détaillée : [docs/PROPOSITION.md](docs/PROPOSITION.md).

## Comptes et rôles
Seuls les encadrants ont un compte (les élèves sont de simples fiches).
- **Encadrant** : consulte le planning, gère ses sections (élèves), note les bouteilles.
- **Référent** (`ROLE_REFERENT`) : + planifie (créneaux, plan habituel, exceptions, modifications ponctuelles), gère bassins et lignes d'eau.
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

## Le planning des lignes d'eau
L'application sert à **planifier l'occupation des lignes d'eau** : pour chaque créneau du club et chaque date, quelle section occupe quelle ligne. Les encadrants voient ainsi à quoi leurs sections ont accès.

- **Créneaux** (référents) : jours et horaires où le club a la piscine (ex. lundi et mercredi 20:00–22:00), lignes ouvertes au club, et **plan habituel** (ligne → section) valable à chaque occurrence.
- **Ligne partagée** : une ligne peut être attribuée à **deux sections** (« + partager… » dans le plan habituel et les exceptions, ou deux sections cochées dans le planning). Dans le planning, la case montre les deux sections l'une au-dessus de l'autre.
- **Exceptions** (référents, dans Créneaux) : pour certaines dates le plan change.
  - Règle **chaque mois** (1er, 2e, 3e, 4e ou dernier lundi…, période facultative) ou **date précise** (l'emporte sur une règle mensuelle).
  - Créneau **annulé** ce jour-là, ou lignes **réattribuées** / **libérées** (« Tout le bassin → » pour aller vite).
  - Exemple : « le 1er lundi de chaque mois, Fosse → Apnée ».
- **Planning** (page d'accueil) : un tableau, une ligne par date de créneau, une colonne par ligne d'eau, la case à la couleur de la section ; vue **Semaine** ou **Mois** ; « Mes lignes » résume les prochaines dates de vos sections.
  - **Référent** : clic sur une case pour la changer **ce jour-là seulement** (une section, libre, ou « comme d'habitude » ; option « tout le bassin ») ; clic sur la date pour **annuler / rétablir** le créneau ce jour-là.
  - **Bouteilles** : clic sur une case de section (ou la colonne Bouteilles) : élèves de la section avec la case « bouteille » pour cette date. Modifiable par les encadrants de la section et les référents ; consultable par tous.
- **Sections** : nom (renommable avec le crayon ✎, ainsi que le niveau visé et la saison), couleur, encadrants, élèves, et **« utilise des bouteilles »** (oui par défaut). Une section sans bouteilles (apnée, nage…) n'apparaît pas dans la colonne Bouteilles du planning.
- Les heures sont celles du club (`APP_TIMEZONE`, Europe/Paris par défaut), heure d'été comprise.

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
`POST /api/login_check` · `GET|PUT /api/me` · `GET|POST /api/pools`, `PUT|DELETE /api/pools/{id}`, `PUT|DELETE /api/lanes/{id}` ·
`GET|POST /api/sections` (+ `/divers`, `/instructors`) · `GET|POST /api/divers` ·
`GET|POST|PUT|DELETE /api/slots` · `PUT /api/slots/{id}/allocations` (plan habituel) · `POST /api/slots/{id}/overrides`, `PUT|DELETE /api/overrides/{id}` (exceptions) ·
`PUT /api/slots/{id}/dates/{date}` (modification ponctuelle) · `GET /api/planning?from&to` · `GET|PUT /api/presences` (bouteilles).
