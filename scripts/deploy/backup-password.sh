#!/usr/bin/env bash
set -uo pipefail

# Prints DB_BACKUP_PASSWORD from /opt/temari/.env as it is now, read through a one-off app container because the long-lived mysql container keeps the env it was created with.
password=$(docker compose -f compose.prod.yaml run --rm --no-deps -T --entrypoint printenv app DB_BACKUP_PASSWORD)

if [ -z "$password" ]; then
  echo "::error::DB_BACKUP_PASSWORD is missing or empty in /opt/temari/.env. Add it (the key is listed in .env.example); the temari_backup MySQL user takes its password from it." >&2
  exit 1
fi

printf '%s' "$password"
