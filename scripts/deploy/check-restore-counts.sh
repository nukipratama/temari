#!/bin/sh
set -eu

usage() {
  echo "Usage: $0 <schema> <table> <live-status> <live-output> <throwaway-status> <throwaway-output>" >&2
  exit 2
}

[ "$#" -eq 6 ] || usage

SCHEMA="$1"
TABLE="$2"
LIVE_STATUS="$3"
LIVE_COUNT="$4"
THROWAWAY_STATUS="$5"
THROWAWAY_COUNT="$6"

if [ "$LIVE_STATUS" != "0" ]; then
  echo "::error::$SCHEMA — live count query failed for $TABLE"
  exit 1
fi

if [ "$THROWAWAY_STATUS" != "0" ]; then
  echo "::error::$SCHEMA — throwaway count query failed for $TABLE"
  exit 1
fi

case "$LIVE_COUNT" in
  ''|*[!0-9]*)
    echo "::error::$SCHEMA — live count query returned invalid output for $TABLE: '$LIVE_COUNT'"
    exit 1
    ;;
esac

case "$THROWAWAY_COUNT" in
  ''|*[!0-9]*)
    echo "::error::$SCHEMA — throwaway count query returned invalid output for $TABLE: '$THROWAWAY_COUNT'"
    exit 1
    ;;
esac

printf '  %-20s live=%-10s throwaway=%-10s' "$TABLE" "$LIVE_COUNT" "$THROWAWAY_COUNT"
if [ "$THROWAWAY_COUNT" -gt "$LIVE_COUNT" ] || { [ "$LIVE_COUNT" -gt 0 ] && [ "$THROWAWAY_COUNT" -eq 0 ]; }; then
  echo "  FAIL"
  exit 1
fi

echo "  OK"
