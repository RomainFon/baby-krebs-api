# Déploiement — baby-krebs-api

Domaine : **https://baby-krebs.fontaine-romain.fr**, partagé avec
baby-krebs-client. Ce service prend `/api` et `/sanctum`, le client sert tout
le reste. **Les deux stacks doivent tourner.**

Prérequis sur la VM : Docker + Docker Compose et le projet **`infra`** en
place (`/srv/infra`). Il fournit :

- **Traefik** : réseau `traefik_network`, entrypoint `websecure`, resolver
  `letsencrypt`.
- **Postgres partagé** : conteneur `infra-postgres`, réseau `db_network`.

## 0. DNS (avant tout)

Enregistrement **A** `baby-krebs.fontaine-romain.fr` → IP publique de la VM.
Vérifier la propagation avant de lancer les stacks, sinon Let's Encrypt
échoue :

```bash
dig +short baby-krebs.fontaine-romain.fr
```

## 1. Accès GitHub depuis la VM

Une *deploy key* par dépôt (GitHub interdit la même clé sur deux dépôts),
lecture seule :

```bash
ssh-keygen -t ed25519 -f ~/.ssh/baby-krebs-api-deploy -N "" -C "vm-baby-krebs-api"
ssh-keygen -t ed25519 -f ~/.ssh/baby-krebs-client-deploy -N "" -C "vm-baby-krebs-client"
cat ~/.ssh/baby-krebs-api-deploy.pub     # → GitHub baby-krebs-api    : Settings → Deploy keys
cat ~/.ssh/baby-krebs-client-deploy.pub  # → GitHub baby-krebs-client : Settings → Deploy keys
```

Ajouter dans `~/.ssh/config` :

```
Host github-baby-krebs-api
  HostName github.com
  User git
  IdentityFile ~/.ssh/baby-krebs-api-deploy
  IdentitiesOnly yes

Host github-baby-krebs-client
  HostName github.com
  User git
  IdentityFile ~/.ssh/baby-krebs-client-deploy
  IdentitiesOnly yes
```

## 2. Base de données

```bash
cd /srv/infra && ./scripts/create-database.sh babykrebs
```

Le script affiche **une seule fois** les valeurs `DB_*`, mot de passe compris.
Garde-les sous la main pour l'étape suivante.

## 3. Cloner et configurer

Les projets vivent dans `/srv/apps`, avec l'utilisateur `ubuntu`. Ne jamais
utiliser `sudo git` : il écrirait des fichiers en root et casserait
`deploy.sh`.

```bash
cd /srv/apps
git clone git@github-baby-krebs-api:RomainFon/baby-krebs-api.git
cd baby-krebs-api
cp .env.prod.example .env.prod
```

Dans `.env.prod`, renseigner `DB_PASSWORD` (étape 2). Le domaine est déjà
prérempli. Laisser `APP_KEY` vide pour l'instant.

## 4. Clé d'application

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod build api
docker compose -f docker-compose.prod.yml --env-file .env.prod run --rm --no-deps --entrypoint php api artisan key:generate --show
```

Copier la valeur affichée (`base64:...`) dans `APP_KEY=` de `.env.prod`, et
la sauvegarder hors de la VM (gestionnaire de mots de passe).

## 5. Lancer

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f api
```

Attendre `INFO  Running migrations.` puis `ready to handle connections`
(Ctrl+C pour quitter les logs). Si Postgres n'est pas encore joignable, `api`
redémarre tout seul jusqu'à ce qu'il le soit.

## 6. Compte admin

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod exec api php artisan baby:create-admin --email=romain.fon974@gmail.com --name=Romain
```

Le mot de passe (8 caractères minimum) est demandé en interactif, il ne
passe donc pas par l'historique du shell. **Ne jamais lancer `db:seed` en
prod** : le seeder crée un admin de dev au mot de passe connu, plus des
tournois de démo.

## 7. Lancer le client

Voir le `DEPLOY.md` de baby-krebs-client.

## Vérification finale

```bash
curl -s https://baby-krebs.fontaine-romain.fr/api/session   # → {"data":null}
curl -s https://baby-krebs.fontaine-romain.fr/api/tournaments
```

Puis sur le site : « Se connecter » avec le compte de l'étape 6, et créer les
joueurs et le premier tournoi depuis l'admin.

## Mise à jour

```bash
cd /srv/apps/baby-krebs-api && ./deploy.sh
```

Le script fait un `git pull`, puis rebuild et redémarre les conteneurs. Les
migrations tournent au démarrage. Il refuse de tourner s'il y a des
modifications locales non commitées.

## Sauvegardes

```bash
cd /srv/infra && ./scripts/backup.sh
```

Le script produit un dump par base, dont `babykrebs`, dans
`/srv/infra/dumps/`. À rapatrier hors de la VM. Ne jamais lancer `down -v` :
cela effacerait les volumes.
