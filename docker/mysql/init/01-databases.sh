#!/bin/sh
# Idempotent DB bootstrap (early-exit) — ensure the app's schemas, user and grants
# exist, as root. Safe to run any number of times.
#
# Runs in two contexts:
#   - dev: auto-runs at initdb on a FRESH volume (mounted into
#     /docker-entrypoint-initdb.d by compose.yaml; connects over the socket).
#   - prod: run once after (re)initializing the mysql data, piped over stdin so no
#     bind mount is needed on the self-hosted runner:
#
#       docker compose -f compose.prod.yaml exec -T mysql sh < docker/mysql/init/01-databases.sh
#
# To reinitialize prod from scratch (data is disposable): stop + remove the mysql
# container, `docker volume rm teman-lari-prod_mysql_data`, `up -d mysql`, then run
# the one-liner above.
set -e

# Prefer the app's (Laravel) DB_* names; fall back to the image's MYSQL_* (dev initdb,
# where only those are present in the container env).
MAIN_DB="${DB_DATABASE:-${MYSQL_DATABASE:-teman_lari}}"
ANALYTICS_DB="${DB_ANALYTICS_DATABASE:-teman_lari_analytics}"
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
