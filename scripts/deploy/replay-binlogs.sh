#!/usr/bin/env bash
set -euo pipefail

# Point-in-time recovery, second half: after scripts/restore-db.sh has restored a
# dump, replay that schema's binlog events written after the dump, up to a stop
# point. Run on the homelab host, from the repo root:
#
#   ./scripts/deploy/replay-binlogs.sh <backup.sql.gz> <stop>
#
# <stop> is either a GTID set, replayed up to and including its last transaction
# (e.g. 3e11fa47-71ca-11e1-9e33-c80aa9429562:1-4711), or a UTC datetime
# ('2026-10-06 08:15:00'), where the first event at or after it is not replayed.
#
# COMPOSE_FILE / MYSQL_SERVICE / COMPOSE_PROJECT point it at a throwaway stack,
# exactly as in scripts/restore-db.sh.
COMPOSE_FILE="${COMPOSE_FILE:-compose.prod.yaml}"
MYSQL_SERVICE="${MYSQL_SERVICE:-mysql}"
COMPOSE_PROJECT="${COMPOSE_PROJECT:-}"
COMPOSE="docker compose -f $COMPOSE_FILE"
if [ -n "$COMPOSE_PROJECT" ]; then
  COMPOSE="docker compose -p $COMPOSE_PROJECT -f $COMPOSE_FILE"
fi
backup="${1:-}"
stop="${2:-}"

if [ -z "$backup" ] || [ ! -f "$backup" ] || [ -z "$stop" ]; then
  echo "Usage: $0 <path-to-backup.sql.gz> <stop GTID set | 'YYYY-MM-DD HH:MM:SS' UTC>" >&2
  exit 1
fi

start=$(gunzip -c "$backup" | awk '
  /^\/\* SET @@GLOBAL.GTID_PURGED=/ { capture = 1 }
  capture { gtids = gtids $0 }
  capture && /\*\/$/ { capture = 0 }
  END {
    sub(/^.*GTID_PURGED=\x27\+?/, "", gtids)
    sub(/\x27;\*\/$/, "", gtids)
    gsub(/[[:space:]]/, "", gtids)
    print gtids
  }')
if [ -z "$start" ]; then
  echo "$backup records no GTID position (only dumps taken with --set-gtid-purged=COMMENTED do), so it cannot anchor a replay." >&2
  exit 1
fi

used_schema=$(gunzip -c "$backup" | awk -F'`' '/^USE `[^`]+`;$/ && !found { print $2; found = 1 }')
schema="$used_schema"
if [[ "${backup##*/}" != analytics-* ]]; then
  # shellcheck disable=SC2016 # DB_DATABASE must expand inside the container shell.
  schema=$($COMPOSE exec -T "$MYSQL_SERVICE" sh -c 'printf %s "$DB_DATABASE"' </dev/null)
fi
if ! [[ "$schema" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Could not determine the target schema for $backup (got '$schema')." >&2
  exit 1
fi

if [[ "$stop" =~ ^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}: ]]; then
  stop_option="--include-gtids=$stop"
elif [[ "$stop" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}\ [0-9]{2}:[0-9]{2}:[0-9]{2}$ ]]; then
  stop_option="--stop-datetime=$stop"
else
  echo "Stop point '$stop' is neither a GTID set nor a 'YYYY-MM-DD HH:MM:SS' datetime." >&2
  exit 1
fi

echo "Replaying binlogs into '$schema' after $start, stopping at $stop."

$COMPOSE exec -T -e REPLAY_START="$start" -e REPLAY_STOP_OPTION="$stop_option" -e REPLAY_SCHEMA="$schema" "$MYSQL_SERVICE" bash -s <<'SH'
set -euo pipefail
command -v mysqlbinlog >/dev/null || { echo "mysqlbinlog is missing: rebuild the mysql image from docker/mysql/Dockerfile." >&2; exit 1; }

export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
datadir=$(mysql -h 127.0.0.1 -uroot -N -e 'SELECT @@datadir')
mapfile -t binlogs < <(mysql -h 127.0.0.1 -uroot -N -e 'SHOW BINARY LOGS' | awk -v dir="$datadir" '{ print dir $1 }')
TZ=UTC mysqlbinlog --skip-gtids --exclude-gtids="$REPLAY_START" "$REPLAY_STOP_OPTION" --database="$REPLAY_SCHEMA" "${binlogs[@]}" \
  | mysql -h 127.0.0.1 -uroot
SH

echo "Replay complete into schema '$schema'."
