#!/bin/sh
set -eu

query=''
while IFS= read -r table || [ -n "$table" ]; do
  [ -n "$table" ] || continue
  case "$table" in
    *[!A-Za-z0-9_]*)
      echo "count-rows-query: refusing table name '$table'" >&2
      exit 1
      ;;
  esac
  [ -z "$query" ] || query="$query UNION ALL "
  query="${query}SELECT '$table', COUNT(*) FROM $table"
done

if [ -z "$query" ]; then
  echo "count-rows-query: no tables given" >&2
  exit 1
fi

printf '%s\n' "$query"
