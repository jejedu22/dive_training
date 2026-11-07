# Dive Training
Gestion de la formation de plongeurs en piscine : sections, calendrier de réservation de la fosse et du matériel.

Stack : Symfony (API) · Vue.js 3 · PostgreSQL · Docker · Traefik.
Voir [docs/PROPOSITION.md](docs/PROPOSITION.md).

```
cp .env.example .env
docker compose up -d --build   # dev : http://dive.localhost
```
> Le code Symfony (`backend/`) et Vue (`frontend/`) reste à générer : étape suivante de la feuille de route.
