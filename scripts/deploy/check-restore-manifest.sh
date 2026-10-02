#!/bin/sh
set -eu

usage() {
  echo "Usage: $0 <schema> <manifest-file> <restored-counts-file>" >&2
  exit 2
}

[ "$#" -eq 3 ] || usage

SCHEMA="$1"
MANIFEST="$2"
RESTORED="$3"

if ! grep -q . "$MANIFEST"; then
  echo "::error::$SCHEMA — manifest $MANIFEST is empty"
  exit 1
fi

fail=0
while IFS="$(printf '\t')" read -r table expected; do
  [ -n "$table" ] || continue
  restored=$(awk -F '\t' -v t="$table" '$1 == t { print $2; exit }' "$RESTORED")
  if [ -z "$restored" ]; then
    echo "::error::$SCHEMA — $table is in the manifest but missing from the restore"
    fail=1
    continue
  fi
  printf '  %-28s dumped=%-10s restored=%-10s' "$table" "$expected" "$restored"
  if [ "$restored" -lt "$expected" ]; then
    echo "  FAIL"
    fail=1
  else
    echo "  OK"
  fi
done < "$MANIFEST"

exit "$fail"
