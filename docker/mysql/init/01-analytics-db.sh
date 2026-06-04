#!/bin/sh
# Create the analytics schema (ai_token_usages cost history that must survive
# migrate:fresh) and grant the app user, as root. Idempotent. Runs on a fresh
# volume (mounted into initdb) and on every deploy (piped via `sh < this`).
set -e

ANALYTICS_DB="${DB_ANALYTICS_DATABASE:-teman_lari_analytics}"
# Prod sets DB_USERNAME (Laravel name); dev/sail sets MYSQL_USER.
APP_USER="${MYSQL_USER:-${DB_USERNAME:-sail}}"

# A running container accepts root only over TCP (root@'%' — the healthcheck
# proves it); the initdb phase runs with --skip-networking, so only the socket
# (root@localhost) is up. Probe TCP, fall back to the socket.
if mysql -h 127.0.0.1 -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "SELECT 1" >/dev/null 2>&1; then
  set -- -h 127.0.0.1 -uroot -p"${MYSQL_ROOT_PASSWORD}"
else
  set -- -uroot -p"${MYSQL_ROOT_PASSWORD}"
fi

mysql "$@" -e "CREATE DATABASE IF NOT EXISTS ${ANALYTICS_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Best-effort: when the app connects as root the grant is redundant and the
# 'root'@'%' identity may not be grantable — don't fail the bootstrap over it.
mysql "$@" -e "GRANT ALL PRIVILEGES ON ${ANALYTICS_DB}.* TO '${APP_USER}'@'%'; FLUSH PRIVILEGES;" \
  || echo "analytics: grant to '${APP_USER}'@'%' skipped (app user may be root or use a different host)"

echo "analytics schema '${ANALYTICS_DB}' ready"
