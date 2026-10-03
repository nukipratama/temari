## What the scripts handle for you

- **Page discovery:** `lib.mjs` runs `php artisan route:list --json --except-vendor` and keeps the
  GET `web` pages — dropping apis, oauth handshakes, webhooks, assets, and legacy 301 redirects.
  Add a page and it's covered automatically; nothing to maintain by hand.
- **Auth:** clicks the demo button on `/login` (no Strava needed) — fresh per viewport context.
- **`{param}` pages:** resolved at runtime by scraping the first matching link off the list page
  (e.g. `/activities/{activity}` → `/activities/126`). If a detail page can't be sampled, the data is
  thin — **re-run `./vendor/bin/sail artisan demo:seed`** and try again.
- **Redirect dedupe:** pages reached via a 301 alias are screenshotted once (keyed by the landed URL).

## Notes

- Defaults to the **local** app. Driving production (`temari.caffeinecommit.my.id`) needs real
  Strava auth — out of scope here.
- This sweeps **pages**. Interactive states (e.g. the avatar logout menu) aren't auto-driven — spot-check those with a short one-off Playwright script that
  clicks the element, screenshots, and asserts its `boundingBox()` is within the viewport.
- Scripts: `lib.mjs` (shared: viewports, login, route discovery), `shoot.mjs` (screenshots),
  `audit.mjs` (overflow), `contrast.mjs` (rendered contrast, per ground), `mounts.mjs` (what a panel
  is actually mounted on), `light-islands.mjs` (surfaces wearing the wrong ground), `edges.mjs`
  (borders and rings that are not there), `states.mjs` (those scans, in states a page load never
  reaches), `scans.mjs` (the shared colour maths), `probe.mjs` (one live DOM question, answered; `--viewport=<key>` picks the viewport),
  `record.mjs` + `clips.sh` (before/after clips, see [clips.md](clips.md)), `setup.sh` / `teardown.sh`.
