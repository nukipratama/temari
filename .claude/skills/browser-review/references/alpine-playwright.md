## The Alpine/Playwright gotcha (do not rediscover this)

The `app` container is **Alpine Linux (musl), ARM64**. Playwright's bundled Chromium is a glibc
build and fails to launch with a misleading `spawn ... ENOENT`. Fix: use Alpine's **native** musl
Chromium and point Playwright at it. The `dev` stage of the [Dockerfile](../../../../Dockerfile)
bakes all of it into `temari/dev`:

- `chromium nss freetype harfbuzz ttf-freefont font-noto-emoji` from Alpine → `/usr/bin/chromium`
- `ffmpeg` (with libx264) for `clips.sh`
- the Playwright JS driver, `npm install -g playwright@$PLAYWRIGHT_VERSION`, in
  `/usr/local/lib/node_modules`, outside the project's `node_modules`, so `npm ci` cannot remove it.
  The scripts import it through `scripts/playwright.mjs` and launch with
  `executablePath: '/usr/bin/chromium'` + `--no-sandbox --disable-dev-shm-usage`

Nothing is installed at run time, so a container recreate or `npm ci` loses nothing. `setup.sh`
only checks the three and prints the rebuild steps when one is missing: the container runs an
image built before they shipped. Rebuild `temari/dev` with `docker compose build app` in the main
checkout, then `./vendor/bin/sail up -d` in each checkout whose container should pick it up.

Keep `PLAYWRIGHT_VERSION` within a Chromium release or two of Alpine's `chromium` (1.63.0 is built
for Chromium 153; Alpine 3.24 ships 152). When a base-image bump moves Alpine's Chromium, bump the
`ARG` to the Playwright release built for it and re-run the scripts.
