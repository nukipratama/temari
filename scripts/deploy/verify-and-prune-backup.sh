#!/bin/sh
set -eu

usage() {
  echo "Usage: $0 <label> <backup-file> <table-count> <prune-glob> <retention-days> [group-prefix]" >&2
  exit 1
}

[ $# -ge 5 ] || usage

LABEL="$1"
OUT="$2"
TABLES="$3"
PRUNE_GLOB="$4"
RETENTION_DAYS="$5"
GROUP_PREFIX="${6:-}"

SIZE=$(stat -c%s "$OUT")

if [ "$SIZE" -lt 1024 ] && [ "$TABLES" -gt 0 ]; then
  echo "::error::$LABEL at $OUT is only $SIZE bytes (schema has $TABLES tables)"
  exit 1
fi

echo "$LABEL OK: $OUT ($SIZE bytes, $TABLES tables)"

# Keep every dump from the last $RETENTION_DAYS days, plus at least the 10
# newest overall (so a burst of same-day deploys never prunes down to nothing
# during a quiet week, and a quiet stretch still keeps a window of history).
CUTOFF=$(($(date +%s) - RETENTION_DAYS * 24 * 3600))

# shellcheck disable=SC2086
FILES=$(ls -1t $PRUNE_GLOB 2>/dev/null || true)

[ -n "$FILES" ] || exit 0

# When GROUP_PREFIX is given, a file's group is the token between that prefix
# and the next "-" (the sha in a deploy backup's name, e.g.
# "pre-deploy-<sha>-<timestamp>-<run>-<attempt>.sql.gz"). The newest file in a
# group is never removed, however old or far past the top-10 floor it is — a
# re-run must add a new backup beside the old one, never leave a sha with zero.
group_of() {
  base=$(basename "$1")
  case "$base" in
    "$GROUP_PREFIX"*)
      rest=${base#"$GROUP_PREFIX"}
      printf '%s\n' "${rest%%-*}"
      ;;
    *)
      printf '%s\n' "$1"
      ;;
  esac
}

# One "group<TAB>mtime" line per file, built once so a candidate can be
# compared against the newest mtime seen for its own group. NL holds a literal
# newline: appending it via command substitution would get it stripped.
NL='
'
GROUP_LINES=""
if [ -n "$GROUP_PREFIX" ]; then
  for f in $FILES; do
    m=$(stat -c%Y "$f")
    key=$(group_of "$f")
    GROUP_LINES="$GROUP_LINES$key	$m$NL"
  done
fi

is_newest_in_group() {
  key="$1"
  mtime="$2"
  max=$(printf '%s' "$GROUP_LINES" | awk -F '\t' -v k="$key" '$1 == k { print $2 }' | sort -rn | head -n1)
  [ -n "$max" ] && [ "$mtime" -ge "$max" ]
}

# Candidates are everything past the top-10-newest floor.
i=0
for f in $FILES; do
  i=$((i + 1))
  [ "$i" -gt 10 ] || continue

  MTIME=$(stat -c%Y "$f")
  [ "$MTIME" -lt "$CUTOFF" ] || continue

  if [ -n "$GROUP_PREFIX" ]; then
    key=$(group_of "$f")
    is_newest_in_group "$key" "$MTIME" && continue
  fi

  rm -f "$f"
done
