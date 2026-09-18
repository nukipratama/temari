#!/bin/sh
set -eu

# Selects which sha-tagged images in one image repository to remove, keeping
# the most recent KEEP. Pure selection logic, reading candidates from stdin
# rather than calling `docker` itself, so it can run against a fixture instead
# of a live daemon (see tests/Unit/Architecture/NightlyBackupWorkflowTest.php).
#
# Usage: select-images-to-prune.sh <repo> <keep-count>
#   stdin:        "CREATED<TAB>ID<TAB>TAG" per image, any order. CREATED must
#                 sort correctly as text (e.g. `docker inspect -f '{{.Created}}'`,
#                 which is RFC3339).
#   IN_USE (env): newline-separated image ids/refs currently running a
#                 container, e.g. `docker ps --format '{{.ID}}'` plus
#                 `docker ps --format '{{.Image}}'`. Optional.
#
# Prints "<id><TAB><repo>:<tag>" per image selected for removal — the caller
# runs the actual `docker rmi`. `latest`, `previous`, and any tag not shaped
# like a full 40-hex-char git sha are never candidates, whatever KEEP is, and
# neither is anything IN_USE names.

usage() {
  echo "Usage: $0 <repo> <keep-count>" >&2
  exit 1
}

[ $# -eq 2 ] || usage

REPO="$1"
KEEP="$2"
IN_USE="${IN_USE:-}"
TAB=$(printf '\t')

is_full_sha() {
  len=$(expr "$1" : '[0-9a-f]\{40\}$' 2>/dev/null || echo 0)
  [ "$len" = "40" ]
}

is_protected() {
  id="$1"
  tag="$2"
  if [ "$tag" = "latest" ] || [ "$tag" = "previous" ]; then
    return 0
  fi
  if ! is_full_sha "$tag"; then
    return 0
  fi
  if [ -n "$IN_USE" ]; then
    printf '%s\n' "$IN_USE" | grep -qxF "$id" && return 0
    printf '%s\n' "$IN_USE" | grep -qxF "$REPO:$tag" && return 0
  fi
  return 1
}

kept=0
sort -r -t "$TAB" -k1,1 | while IFS="$TAB" read -r _created id tag; do
  [ -n "$id" ] || continue
  if is_protected "$id" "$tag"; then
    continue
  fi
  kept=$((kept + 1))
  if [ "$kept" -le "$KEEP" ]; then
    continue
  fi
  printf '%s\t%s:%s\n' "$id" "$REPO" "$tag"
done
