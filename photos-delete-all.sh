#!/usr/bin/env bash
# Deletes EVERY photo: the database rows and all image files. No undo.
#   bash scripts/photos-delete-all.sh          # asks you to type DELETE first
#   bash scripts/photos-delete-all.sh --yes    # no question
set -eu
cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ "${1:-}" != "--yes" ]; then
  echo "This permanently deletes ALL photos (database rows and files)."
  printf 'Type DELETE to continue: '
  read -r answer
  if [ "$answer" != "DELETE" ]; then
    echo "Cancelled. Nothing was deleted."
    exit 1
  fi
fi

echo "Deleting database rows..."
docker compose exec -T db sh -c \
  'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "DELETE FROM photos;"'

echo "Deleting image files..."
docker compose exec -T app sh -c \
  'rm -rf /var/www/html/storage/app/private/photos/* /var/www/html/storage/app/public/photos/*'

echo "Done. Space used now:"
bash "$(dirname "${BASH_SOURCE[0]}")/photos-storage.sh"
