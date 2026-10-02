#!/usr/bin/env bash
set -euo pipefail

# Restore a prod DB backup (/var/lib/temari-backups/*.sql.gz) produced by the
# deploy pipeline. Run on the homelab host, from the repo root.
#
#   ./scripts/restore-db.sh <backup.sql.gz>
#
# The "Rollback prod" workflow rolls CODE back only; after a destructive migration
# the DATA needs restoring with this first. Recommended order for that incident:
#   1. docker compose -f compose.prod.yaml stop scheduler horizon
#   2. ./scripts/restore-db.sh /var/lib/temari-backups/pre-deploy-<sha>-<utc-timestamp>-<run_id>-<run_attempt>.sql.gz
#      (and the matching analytics-pre-deploy-<...>.sql.gz if analytics moved too —
#      it shares the exact same suffix after "pre-deploy-", just under the
#      "analytics-pre-deploy-" prefix; `ls -1t` picks the newest for a sha)
#   3. run the "Rollback prod" GitHub workflow to roll code to :previous

# COMPOSE_FILE / MYSQL_SERVICE / RESTORE_DB_ASSUME_YES / COMPOSE_PROJECT let CI
# point this at a throwaway stack instead of prod; all default to the prod
# values, so COMPOSE_PROJECT empty (unset) leaves prod behaviour unchanged.
COMPOSE_FILE="${COMPOSE_FILE:-compose.prod.yaml}"
MYSQL_SERVICE="${MYSQL_SERVICE:-mysql}"
COMPOSE_PROJECT="${COMPOSE_PROJECT:-}"
COMPOSE="docker compose -f $COMPOSE_FILE"
if [ -n "$COMPOSE_PROJECT" ]; then
  COMPOSE="docker compose -p $COMPOSE_PROJECT -f $COMPOSE_FILE"
fi
backup="${1:-}"

if [ -z "$backup" ] || [ ! -f "$backup" ]; then
  echo "Usage: $0 <path-to-backup.sql.gz>" >&2
  echo "Available backups:" >&2
  ls -1t /var/lib/temari-backups/*.sql.gz 2>/dev/null >&2 || echo "  (none found)" >&2
  exit 1
fi

# analytics-*.sql.gz was dumped with --databases (carries its own CREATE DATABASE
# + USE), so it self-targets the schema its USE line names; the main dump restores
# into $DB_DATABASE from the container env. MYSQL_PWD keeps the password out of
# the process table.
used_schema=$(gunzip -c "$backup" | awk -F'`' '/^USE `[^`]+`;$/ && !found { print $2; found = 1 }')
case "${backup##*/}" in
  analytics-*)
    schema="$used_schema"
    ;;
  *)
    if [ -n "$used_schema" ]; then
      echo "$backup switches to schema '$used_schema' itself; only an analytics-* dump may do that." >&2
      exit 1
    fi
    # shellcheck disable=SC2016 # DB_DATABASE must expand inside the container shell.
    schema=$($COMPOSE exec -T "$MYSQL_SERVICE" sh -c 'printf %s "$DB_DATABASE"' </dev/null)
    ;;
esac

if ! [[ "$schema" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Could not determine the target schema for $backup (got '$schema')." >&2
  exit 1
fi

if [ "${RESTORE_DB_ASSUME_YES:-}" != "1" ]; then
  echo "Restoring $backup into schema '$schema' — this DROPS every table in it, then OVERWRITES it from the backup."
  read -rp "Proceed? [y/N] " reply
  case "$reply" in
    y | Y) ;;
    *) echo "Aborted."; exit 1 ;;
  esac
fi

# Every table in the target schema is dropped in the same session that loads the
# dump, so a table the backup does not contain cannot survive the restore.
# shellcheck disable=SC2016 # The SQL variables must expand inside the container shell.
drops=$($COMPOSE exec -T -e RESTORE_SCHEMA="$schema" "$MYSQL_SERVICE" sh -c \
  'export MYSQL_PWD="$DB_PASSWORD"; mysql -h 127.0.0.1 -N -u"$DB_USERNAME" -e "SET @q = CHAR(96 USING utf8mb4); SELECT CONCAT(\"DROP TABLE \", @q, table_schema, @q, \".\", @q, table_name, @q, \";\") FROM information_schema.tables WHERE table_schema = \"$RESTORE_SCHEMA\" AND table_type = \"BASE TABLE\""' </dev/null)

case "${backup##*/}" in
  analytics-*) target='' ;;
  *) target="$schema" ;;
esac

# shellcheck disable=SC2016 # RESTORE_TARGET must expand inside the container shell.
{
  echo "SET foreign_key_checks = 0;"
  if [ -n "$drops" ]; then
    printf '%s\n' "$drops"
  fi
  gunzip -c "$backup"
} | $COMPOSE exec -T -e RESTORE_TARGET="$target" "$MYSQL_SERVICE" sh -c \
  'export MYSQL_PWD="$DB_PASSWORD"; mysql -h 127.0.0.1 -u"$DB_USERNAME" ${RESTORE_TARGET:+"$RESTORE_TARGET"}'

echo "Restore complete from $backup into schema '$schema'."
