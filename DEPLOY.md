# Déploiement — baby-krebs-api

Prérequis sur la VM : Docker + Docker Compose et le projet **`infra`** en
place (`/srv/infra`) : **Traefik** (réseau `traefik_network`, entrypoint
`websecure`, resolver `letsencrypt`) et **Postgres partagé** (`infra-postgres`,
réseau `db_network`).

API et client partagent le même domaine : ce service prend `/api` et
`/sanctum`, le client sert tout le reste. **Les deux stacks doivent tourner.**

## 0. DNS

Enregistrement **A** `<domaine>` → IP publique de la VM.

## 1. Accès GitHub

Une deploy key par dépôt (voir le DEPLOY.md de roploy-api pour la procédure
`~/.ssh/config` avec alias `github-baby-krebs-api` / `github-baby-krebs-client`).

## 2. Base de données et configuration

```bash
cd /srv/infra && ./scripts/create-database.sh babykrebs
cd /srv/apps
git clone git@github-baby-krebs-api:RomainFon/baby-krebs-api.git
cd baby-krebs-api
cp .env.prod.example .env.prod
```

Dans `.env.prod` : remplacer `baby.example.fr` par le vrai domaine (partout),
reporter les `DB_*` affichées par le script.

## 3. Clé d'application

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod build api
docker compose -f docker-compose.prod.yml --env-file .env.prod run --rm --no-deps --entrypoint php api artisan key:generate --show
```

Copier la valeur (`base64:...`) dans `APP_KEY=`.

## 4. Lancer

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
docker compose -f docker-compose.prod.yml --env-file .env.prod logs api
```

## 5. Compte admin

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod exec api php artisan baby:create-admin --email=<ton-email>
```

Le mot de passe est demandé en interactif. **Ne jamais lancer `db:seed` en
prod** (il crée un admin de dev au mot de passe connu).

## Mise à jour

```bash
./deploy.sh
```

## Sauvegardes

`cd /srv/infra && ./scripts/backup.sh` (un dump par base, dont `babykrebs`).
