#!/bin/sh
# Checks the browser-review tooling the dev image bakes in. Installs nothing.
#   ./vendor/bin/sail exec app sh .agents/skills/browser-review/scripts/setup.sh
missing=0

if [ -x /usr/bin/chromium ]; then
  echo "ok  $(/usr/bin/chromium --version 2>/dev/null)"
else
  echo "missing  /usr/bin/chromium"
  missing=1
fi

pw=/usr/local/lib/node_modules/playwright/package.json
if [ -f "$pw" ]; then
  echo "ok  playwright $(node -p "require('$pw').version")"
else
  echo "missing  global playwright (/usr/local/lib/node_modules/playwright)"
  missing=1
fi

if ffmpeg -hide_banner -encoders 2>/dev/null | grep -q libx264; then
  echo "ok  ffmpeg with libx264"
else
  echo "missing  ffmpeg with libx264"
  missing=1
fi

if [ "$missing" = 1 ]; then
  echo "This container runs a dev image built without the capture tools. Rebuild temari/dev, then recreate this container:" >&2
  echo "  docker compose build app     (in the main checkout)" >&2
  echo "  ./vendor/bin/sail up -d      (in this checkout)" >&2
  exit 1
fi
echo "browser-review tooling ready"
