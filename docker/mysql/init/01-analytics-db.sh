#!/bin/sh
# Runs once, on a FRESH mysql data volume (docker-entrypoint-initdb.d), as root.
# Creates the dedicated analytics schema (for ai_token_usages cost history that
# must survive migrate:fresh) and grants the app user access to it.
#
# Existing volumes do NOT run this — use `make analytics-init` there instead.
set -e

ANALYTICS_DB="${DB_ANALYTICS_DATABASE:-teman_lari_analytics}"
APP_USER="${MYSQL_USER:-sail}"

mysql -u root -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${ANALYTICS_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${ANALYTICS_DB}\`.* TO '${APP_USER}'@'%';
FLUSH PRIVILEGES;
SQL

echo "analytics schema '${ANALYTICS_DB}' ready, granted to '${APP_USER}'"
