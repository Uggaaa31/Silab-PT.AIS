#!/bin/bash
set -e

echo "[Docker Entrypoint] Memulai auto-migrasi database..."
php /var/www/html/scripts/auto_migrate.php || true

exec "$@"
