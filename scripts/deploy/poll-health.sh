#!/usr/bin/env bash
set -uo pipefail

usage() {
  echo "Usage: $0 <url> [attempts] [interval-seconds]" >&2
  exit 1
}

[ $# -ge 1 ] || usage

URL="$1"
ATTEMPTS="${2:-20}"
INTERVAL="${3:-3}"

for ((i = 1; i <= ATTEMPTS; i++)); do
  if curl -fsS --max-time 10 "$URL"; then
    exit 0
  fi
  sleep "$INTERVAL"
done

exit 1
