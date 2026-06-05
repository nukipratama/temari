#!/bin/sh
# Dev/local initdb only (fresh volume): create the app's schemas and grant the app user,
# as root via the socket. Runs inside the mysql container during initdb on local Docker
# Desktop, where bind mounts work. Prod does NOT use this — the homelab runner is
# docker-out-of-docker (bind mounts are unreliable) and bootstraps via the CI
# "Ensure databases" step instead. The app USER is created by the image's MYSQL_USER.
set -e

MAIN_DB="${MYSQL_DATABASE:-${DB_DATABASE:-teman_lari}}"
ANALYTICS_DB="${DB_ANALYTICS_DATABASE:-teman_lari_analytics}"
APP_USER="${MYSQL_USER:-${DB_USERNAME:-sail}}"

for db in "$MAIN_DB" "$ANALYTICS_DB"; do
  mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" \
    -e "CREATE DATABASE IF NOT EXISTS $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON $db.* TO '${APP_USER}'@'%';"
  echo "schema '$db' ready for '${APP_USER}'"
done
mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "FLUSH PRIVILEGES;"
