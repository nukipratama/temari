#!/bin/sh
set -eu

usage() {
  echo "Usage: $0 <schema> <table> <live-status> <live-output> <throwaway-status> <throwaway-output> <live-error-file> <throwaway-error-file>" >&2
  exit 2
}

[ "$#" -eq 8 ] || usage

SCHEMA="$1"
TABLE="$2"
LIVE_STATUS="$3"
LIVE_COUNT="$4"
THROWAWAY_STATUS="$5"
THROWAWAY_COUNT="$6"
LIVE_ERROR_FILE="$7"
THROWAWAY_ERROR_FILE="$8"

if [ "$LIVE_STATUS" != "0" ]; then
  echo "::error::$SCHEMA — live count query failed for $TABLE"
  if [ -s "$LIVE_ERROR_FILE" ]; then
    sed 's/^/  /' "$LIVE_ERROR_FILE"
  fi
  exit 1
fi

if [ "$THROWAWAY_STATUS" != "0" ]; then
  echo "::error::$SCHEMA — throwaway count query failed for $TABLE"
  if [ -s "$THROWAWAY_ERROR_FILE" ]; then
    sed 's/^/  /' "$THROWAWAY_ERROR_FILE"
  fi
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

if [ "$THROWAWAY_COUNT" -gt "$LIVE_COUNT" ] || { [ "$LIVE_COUNT" -gt 0 ] && [ "$THROWAWAY_COUNT" -eq 0 ]; }; then
  echo "  $TABLE live=$LIVE_COUNT throwaway=$THROWAWAY_COUNT FAIL"
  exit 1
fi

echo "  $TABLE OK"
