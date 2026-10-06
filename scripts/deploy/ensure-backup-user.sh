#!/bin/sh
# Creates or converges the read-only backup account, as root, inside the mysql
# container. Safe to run on every deploy:
#
#   DB_BACKUP_PASSWORD=... docker compose -f compose.prod.yaml exec -T -e DB_BACKUP_PASSWORD mysql sh -s < scripts/deploy/ensure-backup-user.sh
set -eu

if [ -z "${DB_BACKUP_PASSWORD:-}" ]; then
  echo "DB_BACKUP_PASSWORD is empty; set it in /opt/temari/.env (key listed in .env.example)." >&2
  exit 1
fi

main_db="${DB_DATABASE:?DB_DATABASE is not set in the mysql container}"
analytics_db="${DB_ANALYTICS_DATABASE:-temari_analytics}"
password=$(printf '%s' "$DB_BACKUP_PASSWORD" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g")
account="'temari_backup'@'127.0.0.1'"

export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -h 127.0.0.1 -uroot <<SQL
CREATE USER IF NOT EXISTS $account IDENTIFIED BY '$password';
ALTER USER $account IDENTIFIED BY '$password';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM $account;
GRANT RELOAD, REPLICATION CLIENT ON *.* TO $account;
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON \`$main_db\`.* TO $account;
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON \`$analytics_db\`.* TO $account;
SQL

echo "temari_backup ready on '$main_db' and '$analytics_db'"
