#!/usr/bin/env sh
# Drive the running app in a browser inside the Sail container and capture
# evidence. Usage: sh .claude/skills/verifier-web/run.sh <scenario.cjs>
# Output: storage/app/verify/shots/   (gitignored)
set -e

SCENARIO="${1:?usage: run.sh <scenario.cjs>}"
C="${VERIFY_CONTAINER:-teman-lari-app-1}"

# 1) System chromium (Alpine) — install once if missing.
docker exec -u root "$C" sh -lc 'command -v chromium-browser >/dev/null 2>&1 || apk add --no-cache chromium nss freetype harfbuzz ttf-freefont font-noto-emoji >/dev/null 2>&1'

# 2) playwright-core in container /tmp (no browser download) — install once.
docker exec "$C" sh -lc '[ -d /tmp/node_modules/playwright-core ] || (cd /tmp && { [ -f package.json ] || npm init -y >/dev/null 2>&1; }; npm i playwright-core@1.48 >/dev/null 2>&1)'

# 3) Drive.
docker exec -e NODE_PATH=/tmp/node_modules -e HOME=/tmp -e BASE="${BASE:-http://127.0.0.1}" "$C" \
    sh -lc "cd /app && node .claude/skills/verifier-web/driver.cjs '$SCENARIO'"

echo "---"
echo "screenshots in: storage/app/verify/shots/"
