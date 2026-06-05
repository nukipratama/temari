#!/bin/sh
# Ensure the app's schemas exist and grant the app user, as ROOT. Idempotent.
# Run by the one-shot `db-init` compose service once mysql is healthy, so it covers BOTH
# fresh and existing volumes — unlike /docker-entrypoint-initdb.d, which only fires on an
# empty data dir and so can never heal an existing volume. The app USER itself is created
# by the image's MYSQL_USER/MYSQL_PASSWORD on first init; this owns schemas + grants only.
set -e

MYSQL_HOST="${DB_HOST:-mysql}"
MAIN_DB="${DB_DATABASE:-teman_lari}"
ANALYTICS_DB="${DB_ANALYTICS_DATABASE:-teman_lari_analytics}"
# Prod sets DB_USERNAME (Laravel name); dev/sail sets MYSQL_USER.
APP_USER="${MYSQL_USER:-${DB_USERNAME:-sail}}"

for db in "$MAIN_DB" "$ANALYTICS_DB"; do
  mysql -h "$MYSQL_HOST" -uroot -p"${MYSQL_ROOT_PASSWORD}" \
    -e "CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -h "$MYSQL_HOST" -uroot -p"${MYSQL_ROOT_PASSWORD}" \
    -e "GRANT ALL PRIVILEGES ON \`$db\`.* TO '${APP_USER}'@'%';"
  echo "schema '$db' ready for '${APP_USER}'"
done
mysql -h "$MYSQL_HOST" -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "FLUSH PRIVILEGES;"
