#!/usr/bin/env bash
# Shows how much disk space the stored photos use.
#   bash scripts/photos-storage.sh
set -eu
cd "$(dirname "${BASH_SOURCE[0]}")/.."

echo "Disk space used by photos (inside the app container):"
docker compose exec -T app sh -c '
  cd /var/www/html/storage/app
  echo "  private (download copies):  $(du -sh private | cut -f1)"
  echo "  public  (gallery copies):   $(du -sh public | cut -f1)"
  echo "  total:                      $(du -sch private public | tail -1 | cut -f1)"
'
