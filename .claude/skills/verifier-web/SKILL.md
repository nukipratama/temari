---
name: verifier-web
description: >-
  Drive the running teman-lari app in a real browser INSIDE the Sail container
  to capture evidence for a UI change — full-page screenshots and full-resolution
  share-card <canvas> exports — without asking the human to screenshot manually.
  Use when verifying any visual/frontend change (pages, components, the share
  modal, the card reveal). Everything runs in the container; nothing is installed
  on the host and nothing is added to package.json / composer.json.
---

# verifier-web — in-container browser evidence capture

The repo has **no Playwright / Pest-browser** wired up, and the app on
`localhost:7001` serves a **built bundle** (no live vite dev server). This skill
drives a real Chromium inside the Sail `app` container and writes screenshots to
a gitignored folder the human can open.

## Invariants (this repo)

- Sail service is `app` → container **`teman-lari-app-1`** (Alpine + Node). All
  work runs there via `docker exec`.
- Repo is mounted at **`/app`** in the container; host `:7001` → container `:80`,
  so inside the container the app is at **`http://127.0.0.1`**.
- Chromium on Alpine = the **system** package (`apk add chromium`), driven with
  **`playwright-core`** pointed at `/usr/bin/chromium-browser` (no browser
  download). Launch with `--no-sandbox --disable-dev-shm-usage`.
- Auth: demo login is `POST /auth/demo` (Inertia). Log in by POSTing it with the
  session's `XSRF-TOKEN` cookie as the `X-XSRF-TOKEN` header (see `driver.cjs`).
  Requires `DEMO_LOGIN_ENABLED=true` locally.
- Output goes to **`storage/app/verify/shots/`** (gitignored). Full-res share
  cards are grabbed via `canvas.toDataURL()` (NOT an element screenshot — the
  modal preview is scaled down to ~140px and is not representative).

## Run it

1. **Rebuild first if you changed frontend code** — the app serves the built
   bundle, so unbuilt changes won't show:
   `./vendor/bin/sail npm run build`
   (PHP-only changes: `docker restart teman-lari-app-1` instead.)
2. Write a scenario (a function that drives the page) — see `scenarios/kartu.cjs`.
3. `sh .claude/skills/verifier-web/run.sh .claude/skills/verifier-web/scenarios/kartu.cjs`
   (installs chromium + playwright-core in the container if missing, then runs).
4. Read the PNGs in `storage/app/verify/shots/` with the Read tool and judge.
   **Judge full-res canvas dumps, not modal thumbnails.**

## Scenario API

`module.exports = async (page, h) => { ... }` where `h` has:
- `h.go(path)` — navigate (relative to BASE) + settle.
- `h.shot(name, opts?)` — screenshot to `shots/<name>.png` (`{ fullPage: true }` ok).
- `h.dumpCanvas(name)` — save the page's `<canvas>` at full internal resolution.
- `page` — the raw Playwright page (use `getByRole`, `getByTestId`, `selectOption`, …).
- `h.BASE`, `h.DIR`.

Console errors are collected and printed as `CONSOLE_ERRORS:` at the end.

## Triggering app state

Some surfaces need state (e.g. the card reveal needs `pending_reveal_card_id`).
Set it with tinker before the scenario, then reset after:
`./vendor/bin/sail artisan tinker --execute '...'`

## Cleanup (after verifying)

The browser tooling is transient — remove it so the container stays clean:
```
docker exec -u root teman-lari-app-1 sh -lc 'apk del chromium nss freetype harfbuzz ttf-freefont font-noto-emoji 2>/dev/null; rm -rf /tmp/node_modules /tmp/package*.json'
```
Screenshots under `storage/app/verify/` are gitignored — keep or delete as you like.
Reset any state you changed (e.g. clear `pending_reveal_card_id`).
