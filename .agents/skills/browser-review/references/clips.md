## Recording before/after clips

Record a clip when a PR changes motion or interaction: transitions, popovers, gestures, loading
states. A screenshot cannot show timing. Record real speed first, on both grounds (dark and light),
beside the screenshots; add a slowed clip only when a 150-300ms effect is hard to read at speed.

Two scripts, split by where they run:

- `scripts/record.mjs` runs in the Sail `app` container with the same Playwright, Chromium and demo login as the other scripts. It captures a CDP screencast and writes JPEG frames with real frame timing.
- `scripts/clips.sh` encodes with `ffmpeg`. Until it ships in the dev image (#1704), add it to the running container once with `docker compose exec -u root app apk add ffmpeg`, then run `clips.sh` through `./vendor/bin/sail exec app sh ...`. A container recreate drops it, and `clips.sh` says so when it is missing.

### Run it

```bash
S=.agents/skills/browser-review
rec() { ./vendor/bin/sail exec app node $S/scripts/record.mjs "$@"; }

# 1. before: main's stack (BASE=http://host.docker.internal:<main port>), or --no-view-transitions
#    when the change adds a view transition. after: this branch's stack.
rec morph $S/scenarios/history-morph.json --side=before --no-view-transitions
rec morph $S/scenarios/history-morph.json --side=after

# 2. encode on the host: real speed, plus a 4x slowed copy
sh $S/scripts/clips.sh render morph-dark-mobile-before 4
sh $S/scripts/clips.sh render morph-dark-mobile-after 4

# 3. before left, after right
sh $S/scripts/clips.sh sbs morph-dark-mobile          # and `sbs morph-dark-mobile slow`

# 4. the reduced-motion run, then the other ground (--ground=light) and a desktop viewport
rec morph $S/scenarios/history-morph.json --side=after --reduced-motion
sh $S/scripts/clips.sh render morph-dark-mobile-reduced-after

# 5. attach
gh pr edit <n> --attach storage/app/clips/morph-dark-mobile-sbs.mp4
```

`record.mjs <feature> <scenario> [--side=before|after] [--ground=dark|light] [--viewport=<key>] [--reduced-motion] [--no-view-transitions]`
takes the `--viewport` keys of `probe.mjs` (default `mobile`, ground default `dark`). The recording
starts after login and page load, so the clip opens on the settled page.

To record another build, point `BASE` at its stack with `-e BASE=http://host.docker.internal:<port>`
(each worktree slot serves its own port). That route was not verified on this tooling: the login
timed out against the main stack, so check its demo seed and asset URLs first. When the change adds
a view transition, `--no-view-transitions` on the same stack is the verified "before".

### Scenarios

A scenario is `scenarios/<name>.json` with a route and a step list, or a `.mjs` module for anything
a step cannot express (`export const route = '/'; export default async (page) => { ... }`).

| Step | Does |
| --- | --- |
| `["goto", "/path"]` | navigates |
| `["click", selector]`, `["hover", selector]` | acts on the first match |
| `["waitFor", selector]` | waits for it to be visible |
| `["wait", ms]` | holds, so the end state is on screen |
| `["press", "Escape"]`, `["scroll", dy]`, `["back"]` | keyboard, wheel, history |

Shipped: `history-morph.json` (tap a History row, the run header morph) and `metric-explainer.json`
(open and dismiss a Trends popover). End every scenario with a `wait` long enough for the effect
to finish; the script adds a 0.3s lead-in and a 0.6s tail.

### Naming and size

Files land in `storage/app/clips/` (gitignored), named
`<feature>-<ground>-<viewport>[-reduced]-<before|after|sbs>[-slow].mp4`, for example
`morph-dark-mobile-sbs.mp4`, `morph-light-laptop-after.mp4`, `explainer-dark-mobile-reduced-after.mp4`.
`render` deletes the raw frames after encoding.

Keep every attachment under 10 MB, which GitHub accepts for images, gifs and videos on any plan. The
script keeps each clip within 1280x900 (a side-by-side within 960x900 per side) at CRF 28 and warns
above 10 MB. Typical clips: 80-200 KB for a
1-2s mobile interaction, under 1 MB side by side. Raise `CRF` or shorten the scenario if it warns.
`clips.sh gif <clip.mp4>` makes a 480px gif for places that do not render video.

Name the order in the PR text: before (or main) on the left, after on the right.
