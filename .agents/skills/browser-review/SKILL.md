---
name: browser-review
description: Drive a real browser to screenshot every user-facing page across a mobile/tablet/laptop/desktop viewport matrix, capture console errors, and audit for horizontal overflow — an end-to-end visual UI review. Use when asked to "browser review", "screenshot every page", "mobile UI review", "check the UI on mobile/tablet", "full browser check", or "review the app end to end" in this repo.
---

# browser-review

This is the canonical full skill shared by agents. For an end-to-end visual review,
log in as the demo user, **discover every page from the route table**,
screenshot each across the viewport matrix, collect JS/console errors, and flag any horizontal overflow.
Then read the PNGs back to spot layout bugs. Everything runs **inside the Sail `app` container**
(no host browser needed), so the page list is never hardcoded — it comes from
`php artisan route:list` each run and auto-includes new pages.

## Reference index

Read only the file the step needs; each holds its section verbatim.

- [Viewport matrix (default)](references/viewports.md): before narrowing or widening `VIEWPORTS`.
- [States the demo does not produce, and the operator console](references/edge-states-and-devtools.md): before auditing loading, empty or failed states, or sweeping `/devtools`, `/pulse` or a production host.
- [Before merging to the epic — the probes are not in CI](references/pre-merge-probes.md): before merging UI work to the epic.
- [The Alpine/Playwright gotcha](references/alpine-playwright.md): when `setup.sh` reports something missing or Chromium fails to launch.
- [Scanners](references/scanners.md) (`contrast.mjs`, `mounts.mjs`, `light-islands.mjs`, `edges.mjs`, `states.mjs`, `scans.mjs`): before running step 4 or 5, or judging their baselines.
- [Reading the output and inspecting](references/inspect.md) ("Reading screenshots", "Inspect (audit-gated)", "Verify before reporting", the probe-evidence contract): after steps 2–3, before reading any screenshot.
- [Recording before/after clips](references/clips.md): when a PR changes motion or interaction (transitions, popovers, gestures, loading states), before opening it.
- [What the scripts handle for you, and notes](references/scripts.md): when a page is missing from the sweep, or before editing a script.

## Prerequisites

```bash
./vendor/bin/sail up -d
./vendor/bin/sail npm run build               # fresh built assets — stale/missing build = Vite manifest errors or old UI
./vendor/bin/sail artisan demo:seed          # demo user + ~126 runs, deterministic
./vendor/bin/sail artisan demo:seed --with-edge-states   # + pending/processing/failed AI blocks
# the scripts log in via the /login demo button: DEMO_LOGIN_ENABLED defaults to true
# (config/demo.php); only a local override to false breaks login
```

The app is reachable **inside the container at `http://localhost`** (host-forwarded port is
`APP_PORT=7001`, but the scripts run in the container, so use `localhost`).

## Run it

```bash
# 1. check the dev image carries Chromium, Playwright and ffmpeg (installs nothing; prints the
#    rebuild steps when the image predates them)
./vendor/bin/sail exec app sh .agents/skills/browser-review/scripts/setup.sh

# 2. screenshots across the viewport matrix (default mobile,se,laptop,desktop — see references/viewports.md)
./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/shoot.mjs
#    e.g. just phone:    ./vendor/bin/sail exec -e VIEWPORTS=mobile app node .../shoot.mjs
#    e.g. full 5-way:    ./vendor/bin/sail exec -e VIEWPORTS=mobile,se,tablet,laptop,desktop app node .../shoot.mjs

# 3. horizontal-overflow audit across the matrix (run BEFORE Inspect — its output gates which
#    pages get the expensive vision read, see references/inspect.md)
./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/audit.mjs

# 4. rendered-contrast audit, once per ground
./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/contrast.mjs dark
./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/contrast.mjs light

# 5. on demand: is a design-page shortfall real, and is any surface wearing the wrong ground?
./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/mounts.mjs dark 'bg-leaf/15,...'
./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/light-islands.mjs dark

./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/edges.mjs dark
./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/states.mjs dark
./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/probe.mjs / dark 'document.title'
```

Screenshots stay in place as history; the next sweep replaces them.
