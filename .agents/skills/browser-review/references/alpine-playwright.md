## The Alpine/Playwright gotcha (do not rediscover this)

The `app` container is **Alpine Linux (musl), ARM64**. Playwright's bundled Chromium is a glibc
build and fails to launch with a misleading `spawn ... ENOENT`. Fix: use Alpine's **native** musl
Chromium and point Playwright at it. `setup.sh` does this:

- `apk add --no-cache chromium nss freetype harfbuzz ttf-freefont` (needs **root**) → `/usr/bin/chromium`
- `npm i playwright --no-save` for the JS driver only, run as the **app user** (not root, or the
  unprivileged teardown can't remove it); `teardown.sh` deletes the playwright dirs to restore the
  lockfile state
- launch with `executablePath: '/usr/bin/chromium'` + `--no-sandbox --disable-dev-shm-usage`

Both are **ephemeral** (gone when the container is recreated) — this skill never commits browser
binaries or edits `package.json`.
