#!/bin/sh
# Reads changed repo-relative paths on stdin and prints the Pest test files that
# cover them: a changed test file itself, and every tests/**/{Name}Test.php for a
# changed app/**/{Name}.php, the same basename pairing EveryClassHasATestTest uses.
#
# POSIX sh, not bash: the dev image is Alpine and ships no bash.
set -eu

cd "$(dirname "$0")/.."

test_files=$(find tests -type f -name '*Test.php')

while IFS= read -r path; do
  case "$path" in
    tests/*Test.php)
      if [ -f "$path" ]; then echo "$path"; fi
      ;;
    app/*.php)
      name=$(basename "$path" .php)
      printf '%s\n' "$test_files" | grep "/${name}Test\.php$" || true
      ;;
  esac
done | sort -u
