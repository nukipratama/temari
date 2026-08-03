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

# shellcheck disable=SC2086
ls -1t $PRUNE_GLOB | tail -n +11 | xargs -r rm
