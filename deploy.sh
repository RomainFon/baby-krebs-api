#!/usr/bin/env bash
# Déploiement / mise à jour de baby-krebs-api sur la VM.
# Usage : ./deploy.sh (depuis n'importe quel dossier)
#
# Le code est mis à jour en amont (par RoPloy) : ce script ne fait aucune
# commande git, il reconstruit et redémarre seulement les conteneurs.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

if [ ! -f .env.prod ]; then
  echo "Erreur : .env.prod introuvable (voir DEPLOY.md, étape 3)." >&2
  exit 1
fi

COMPOSE="docker compose -f docker-compose.prod.yml --env-file .env.prod"

echo "==> Build + démarrage des conteneurs"
# --pull : re-télécharge les images de base pour récupérer les patchs de sécurité
$COMPOSE build --pull
$COMPOSE up -d

echo "==> État des conteneurs"
$COMPOSE ps

echo "==> Dernières lignes de logs (api)"
$COMPOSE logs --tail=30 api

echo "==> Déploiement terminé."
