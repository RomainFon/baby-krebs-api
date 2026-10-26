#!/bin/sh
set -e

# Désactivable (RUN_MIGRATIONS=false) si un jour un second conteneur PHP
# (worker, scheduler) partage cet entrypoint : les migrations ne doivent
# tourner que dans un seul.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force
fi

# Pas de cache en local : avec le bind mount du code, il atterrirait sur
# l'hôte (bootstrap/cache/config.php) et imposerait DB_HOST=postgres aux
# commandes lancées hors conteneur, tests compris.
if [ "${APP_ENV:-production}" = "local" ]; then
  php artisan optimize:clear
else
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
fi

exec "$@"
