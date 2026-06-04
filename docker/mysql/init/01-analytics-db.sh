#!/bin/sh
# Create the dedicated analytics schema (ai_token_usages cost history that must
# survive migrate:fresh) and grant the app user access. Idempotent.
#
# Runs as root inside the mysql container two ways, both reusing this one
# script: automatically on a FRESH volume (mounted into initdb), and on every
# deploy for existing volumes (piped via `sh < this`). MYSQL_ROOT_PASSWORD is
# available in the container in both cases.
set -e

ANALYTICS_DB="${DB_ANALYTICS_DATABASE:-teman_lari_analytics}"
# Prod sets DB_USERNAME (Laravel name); dev/sail sets MYSQL_USER. Either is the
# app user that needs access to the new schema.
APP_USER="${MYSQL_USER:-${DB_USERNAME:-sail}}"

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e \
  "CREATE DATABASE IF NOT EXISTS ${ANALYTICS_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Best-effort: when the app connects as root the grant is redundant and the
# 'root'@'%' identity may not be grantable — don't fail the bootstrap over it.
mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e \
  "GRANT ALL PRIVILEGES ON ${ANALYTICS_DB}.* TO '${APP_USER}'@'%'; FLUSH PRIVILEGES;" \
  || echo "analytics: grant to '${APP_USER}'@'%' skipped (app user may be root or use a different host)"

echo "analytics schema '${ANALYTICS_DB}' ready"
