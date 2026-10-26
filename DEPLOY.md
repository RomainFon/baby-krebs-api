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

## Connexion Microsoft (SSO)

Le bouton « Se connecter avec Microsoft » ne laisse entrer que les admins
existants. L'email du compte Microsoft doit correspondre à un compte créé
avec `baby:create-admin`. Tout autre compte est refusé, et aucun compte
n'est créé à la volée. La connexion par mot de passe reste disponible en
secours.

### 1. Inscrire l'application dans Azure

Sur https://portal.azure.com, ouvrir **Microsoft Entra ID**, puis
**Inscriptions d'applications** → **Nouvelle inscription** :

- **Nom** : `Baby Krebs`.
- **Types de comptes pris en charge** :
  - *Comptes dans un annuaire organisationnel et comptes Microsoft
    personnels* : accepte les comptes pro **et** perso. Garder
    `MICROSOFT_TENANT_ID=common`.
  - ou *Comptes dans cet annuaire organisationnel uniquement* : uniquement
    les comptes de ton organisation. Mettre alors l'**ID de l'annuaire
    (locataire)** dans `MICROSOFT_TENANT_ID`.
- **URI de redirection** : plateforme **Web**, valeur
  `https://baby-krebs.fontaine-romain.fr/api/auth/microsoft/callback`.

Ensuite, dans l'application créée :

- **Authentification** → ajouter une 2e URI de redirection Web pour le dev
  local : `http://localhost:5196/api/auth/microsoft/callback`.
- **Certificats et secrets** → **Nouveau secret client** → copier la
  **Valeur**, qui n'est affichée qu'une fois. Un secret expire au plus tard
  après 24 mois : noter la date pour le renouveler.
- **Autorisations d'API** : la permission déléguée `User.Read` (Microsoft
  Graph), présente par défaut, suffit.

### 2. Configurer la VM

Dans `/srv/apps/baby-krebs-api/.env.prod` :

```
MICROSOFT_CLIENT_ID=<ID d'application (client)>
MICROSOFT_CLIENT_SECRET=<valeur du secret>
MICROSOFT_TENANT_ID=common   # ou l'ID de l'annuaire si mono-organisation
```

Puis `./deploy.sh` : il récupère le code, applique la migration (colonne
`users.microsoft_id`) et recharge la configuration.

### 3. Vérifier

« Se connecter avec Microsoft » depuis https://baby-krebs.fontaine-romain.fr/login :

- avec un compte admin, on arrive sur l'admin ;
- avec un autre compte, on revient sur la page de connexion avec le message
  « Ce compte Microsoft n'est pas autorisé ».

Le rattachement se fait sur l'email, comparé sans tenir compte des
majuscules. Microsoft fournit l'adresse dans `mail` ou dans
`userPrincipalName`, et les deux sont testés. À la première connexion,
l'identifiant Microsoft est mémorisé sur le compte.

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
