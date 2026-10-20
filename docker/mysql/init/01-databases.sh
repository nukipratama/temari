#!/bin/sh
# Idempotent DB bootstrap: ensures the app's schemas, user and grants exist, as root.
#   - dev: runs at initdb on a fresh volume (mounted into /docker-entrypoint-initdb.d by compose.yaml).
#   - prod: piped over stdin after (re)initializing the mysql data:
#       docker compose -f compose.prod.yaml exec -T mysql sh < docker/mysql/init/01-databases.sh
set -e

# Prefer the app's (Laravel) DB_* names; fall back to the image's MYSQL_* (dev initdb,
# where only those are present in the container env).
MAIN_DB="${DB_DATABASE:-${MYSQL_DATABASE:-temari}}"
ANALYTICS_DB="${DB_ANALYTICS_DATABASE:-temari_analytics}"
APP_USER="${DB_USERNAME:-${MYSQL_USER:-sail}}"
APP_PW="${DB_PASSWORD:-${MYSQL_PASSWORD}}"

# Connect as root over TCP once the server is listening (prod, via exec); fall back to
# the socket during initdb, when the temporary server runs with --skip-networking.
if mysql -h 127.0.0.1 -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "SELECT 1" >/dev/null 2>&1; then
  set -- -h 127.0.0.1 -uroot -p"${MYSQL_ROOT_PASSWORD}"
else
  set -- -uroot -p"${MYSQL_ROOT_PASSWORD}"
fi

# Early exit if already bootstrapped (analytics schema present).
if mysql "$@" -e "USE ${ANALYTICS_DB}" >/dev/null 2>&1; then
  echo "db-init: '${ANALYTICS_DB}' already present — skipping"
  exit 0
fi

mysql "$@" <<SQL
CREATE DATABASE IF NOT EXISTS ${MAIN_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS ${ANALYTICS_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${APP_USER}'@'%' IDENTIFIED BY '${APP_PW}';
GRANT ALL PRIVILEGES ON ${MAIN_DB}.* TO '${APP_USER}'@'%';
GRANT ALL PRIVILEGES ON ${ANALYTICS_DB}.* TO '${APP_USER}'@'%';
FLUSH PRIVILEGES;
SQL

echo "db-init: '${MAIN_DB}' + '${ANALYTICS_DB}' ready for '${APP_USER}'"
