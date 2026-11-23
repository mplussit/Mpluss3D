#!/bin/bash
# Oppdaterer og starter M+ 3D på serveren.
#
#   docker/deploy.sh test   → mplus3d-test, port 8086, https://3dtest.mpluss.no
#   docker/deploy.sh prod   → mplus3d,      port 8085, https://3d.mpluss.no
#
# Kjøres fra git-klonen for miljøet (for eksempel ~/mplus3d-test eller ~/mplus3d).
# Skriptet henter siste versjon av greinen klonen står på, installerer avhengigheter,
# bygger Docker-bildet hvis det mangler, og ruller ut stakken.
set -euo pipefail

cd "$(dirname "$0")/.."

case "${1:-}" in
  prod) STACK=mplus3d;      PORT=8085; HOSTS=app.mpluss.no;                        DRAFTS=0 ;;
  test) STACK=mplus3d-test; PORT=8086; HOSTS=apptest.mpluss.no,test.mpluss.no; DRAFTS=1 ;;
  *) echo "Bruk: $0 prod|test"; exit 1 ;;
esac

IMAGE=mplus3d/php-fpm:1

echo "== Henter siste versjon ($(git branch --show-current))"
git pull --ff-only

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  echo "== Bygger $IMAGE (første gang tar det noen minutter)"
  docker build -t "$IMAGE" docker
fi

echo "== Installerer PHP-avhengigheter"
docker run --rm -v "$PWD":/var/www/mplus3d "$IMAGE" \
  composer install --no-dev --no-interaction --optimize-autoloader --no-progress

echo "== Gir PHP skrivetilgang til storage/"
mkdir -p storage
docker run --rm -v "$PWD/storage":/s "$IMAGE" chown -R www-data:www-data /s

echo "== Ruller ut stakken $STACK på port $PORT"
MPLUS3D_PORT=$PORT MPLUS_RETURN_HOSTS=$HOSTS M3D_SHOW_DRAFTS=$DRAFTS docker stack deploy -c docker-compose.yml "$STACK"

echo "== Ferdig. Status:"
docker stack services "$STACK"
