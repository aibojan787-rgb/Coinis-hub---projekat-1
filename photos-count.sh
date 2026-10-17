#!/usr/bin/env bash
# Shows how many photos are in the database, per status.
#   bash scripts/photos-count.sh
set -eu
cd "$(dirname "${BASH_SOURCE[0]}")/.."

docker compose exec -T db sh -c \
  'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT status, COUNT(*) AS photos FROM photos GROUP BY status; SELECT COUNT(*) AS total FROM photos;"'
