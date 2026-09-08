#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $0 <label> <backup-file> <table-count> <prune-glob>" >&2
  exit 1
}

[ $# -eq 4 ] || usage

LABEL="$1"
OUT="$2"
TABLES="$3"
PRUNE_GLOB="$4"

SIZE=$(stat -c%s "$OUT")

if [ "$SIZE" -lt 1024 ] && [ "$TABLES" -gt 0 ]; then
  echo "::error::$LABEL at $OUT is only $SIZE bytes (schema has $TABLES tables)"
  exit 1
fi

echo "$LABEL OK: $OUT ($SIZE bytes, $TABLES tables)"

# Keep every dump from the last 7 days, plus at least the 10 newest overall
# (so a burst of same-day deploys never prunes down to nothing during a quiet
# week, and a quiet stretch still keeps a week of history).
CUTOFF=$(($(date +%s) - 7 * 24 * 3600))

i=0
# shellcheck disable=SC2086
for f in $(ls -1t $PRUNE_GLOB 2>/dev/null); do
  i=$((i + 1))
  if [ "$i" -le 10 ]; then
    continue
  fi
  MTIME=$(stat -c%Y "$f")
  if [ "$MTIME" -lt "$CUTOFF" ]; then
    rm -f "$f"
  fi
done
