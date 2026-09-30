#!/bin/sh
# Idempotent browser-review setup. Run inside the Sail `app` container as ROOT
# (apk needs root):  docker compose exec -u root app sh .claude/skills/browser-review/scripts/setup.sh
#
# The container is Alpine ARM64 (musl), so Playwright's bundled glibc Chromium
# can't run — install Alpine's native chromium and let shoot.mjs/audit.mjs point
# Playwright at /usr/bin/chromium. Both installs are ephemeral (lost on recreate).
set -e
APP_USER=${APP_USER:-www-data}

# npm must NOT run as root: a root-owned node_modules makes the unprivileged app
# user's later installs/teardown fail on permissions. Drop to APP_USER when root.
run_as_app() {
  if [ "$(id -u)" = "0" ]; then
    su "$APP_USER" -s /bin/sh -c "$1"
  else
    sh -c "$1"
  fi
}

if [ ! -x /usr/bin/chromium ]; then
  echo "→ installing native chromium (apk)…"
  apk add --no-cache chromium nss freetype harfbuzz ttf-freefont font-noto-emoji
else
  echo "→ chromium already present at /usr/bin/chromium"
fi

if [ ! -d node_modules/playwright ]; then
  echo "→ installing playwright js driver as ${APP_USER} (--no-save)…"
  run_as_app 'npm i playwright --no-save --no-audit --no-fund'
else
  echo "→ playwright already installed"
fi

echo "✓ browser-review setup ready"
