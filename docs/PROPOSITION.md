# Dive Training — gestion de la formation de plongeurs en piscine

## 0. Décisions validées (version implémentée)
- **Plusieurs bassins**, chacun avec **plusieurs lignes d'eau** ; la ligne d'eau est l'unité réservable.
- **Matériel simplifié** : pas de parc ni de stock. Pour chaque séance, on note seulement **quel élève plonge avec bouteille** (et combien).
- **Un référent valide** les réservations (`ROLE_REFERENT`) ; les encadrants les demandent.
- **Accès réservé aux encadrants** : les élèves n'ont pas de compte (simple fiche `Diver`).
- Les paragraphes 2 à 4 ci-dessous décrivent la proposition initiale ; le code fait foi (voir `backend/src/Entity`).

## 1. Besoins couverts
- **Sections** (groupes de formation) : création, niveau visé (N1, N2, Bapteme…), encadrants, élèves, saison.
- **Réservation de la fosse** : calendrier par créneaux, par ligne d'eau / profondeur, sans chevauchement.
- **Réservation du matériel** : blocs, détendeurs, stabs, combinaisons… avec stock, tailles, état, révisions (TIV, requalification).
- **Séances** : une séance = une section + un créneau fosse + un lot de matériel + des participants.

## 2. Rôles
| Rôle | Droits |
|---|---|
| `ROLE_ADMIN` | Tout (utilisateurs, ressources, paramètres) |
| `ROLE_MANAGER` (directeur de plongée / resp. matériel) | Valide les réservations, gère le parc matériel |
| `ROLE_INSTRUCTOR` | Gère ses sections, crée des séances et demandes de réservation |
| `ROLE_DIVER` | Consulte ses séances et son calendrier |

## 3. Modèle de données (Doctrine)
- `User` (email, nom, rôles, niveau, certificat médical – date de validité)
- `Section` (nom, niveau visé, saison, couleur) — `ManyToMany` encadrants, `ManyToMany` élèves
- `Resource` (abstrait, `type` = `POOL` | `EQUIPMENT`) : nom, actif
  - `PoolArea` : fosse / ligne d'eau, profondeur max, capacité
  - `EquipmentItem` : catégorie, taille, n° de série, état, prochaine révision, quantité (si lot)
- `Session` (section, titre, début, fin, notes)
- `Reservation` (resource, session, début, fin, quantité, statut `PENDING|CONFIRMED|CANCELLED`, demandeur)
- `Participation` (session, user, présence)

**Règle clé** : refus d'une réservation si, pour la même ressource, une autre réservation `CONFIRMED` chevauche `[début, fin)`
(`début < autre.fin AND fin > autre.début`) et, pour le matériel en quantité, si la somme dépasse le stock.
Contrôlé en service métier + transaction (verrou pessimiste) pour éviter les doubles réservations concurrentes.
Le matériel hors service ou en révision est indisponible.

## 4. API REST (API Platform ou contrôleurs Symfony + JWT)
```
POST   /api/login_check
GET/POST         /api/sections            GET/PUT/DELETE /api/sections/{id}
POST             /api/sections/{id}/members
GET/POST         /api/resources           (?type=POOL|EQUIPMENT)
GET              /api/availability?resource=&from=&to=
GET/POST         /api/sessions
GET/POST         /api/reservations        PATCH /api/reservations/{id}/confirm|cancel
GET              /api/calendar?from=&to=&resource=&section=   (flux d'événements)
```

## 5. Front Vue.js 3 (Vite + TypeScript + Pinia + Vue Router)
- **Calendrier** : FullCalendar (`@fullcalendar/vue3`) avec vue *Resource Timeline* → colonnes fosse / matériel, glisser-déposer pour réserver, couleurs par section, détection de conflit en direct.
- Pages : Tableau de bord, Sections (+ fiche avec élèves), Calendrier, Matériel (parc + états), Séances, Administration.
- Formulaire de séance : choisir section → créneau → fosse → matériel (liste filtrée par disponibilité).

## 6. Architecture Docker / Traefik
```
Internet → Traefik (80/443, Let's Encrypt)
              ├─ app.exemple.fr        → frontend (nginx servant le build Vue)
              └─ app.exemple.fr/api    → backend  (nginx + php-fpm Symfony)
                                          └─ db (PostgreSQL, réseau interne uniquement)
```
- Un seul domaine : `/api` routé vers le backend (pas de CORS en prod).
- Réseaux : `web` (Traefik) et `internal` (backend ↔ db).
- Fichiers fournis : `docker-compose.yml` (prod), `docker-compose.dev.yml` (dev), `.env.example`.

## 7. Qualité & sécurité
JWT (lexik) + refresh, validation Symfony, tests PHPUnit sur la règle de chevauchement, Vitest/Playwright côté front, PHPStan, CI GitHub Actions, sauvegarde `pg_dump` planifiée, RGPD (données médicales : stocker seulement la date de validité du certificat).

## 8. Feuille de route
1. **MVP** : auth, sections, fosse, calendrier + réservation sans conflit.
2. Matériel (stock, tailles, état) et lien avec les séances.
3. Présences, alertes (certificat médical, révisions TIV), notifications e-mail.
4. Export iCal/PDF, suivi de progression des compétences par élève.
