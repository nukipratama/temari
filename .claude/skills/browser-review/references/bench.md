## Timing interactions against the native-feel budgets

`scripts/bench.mjs` times scripted taps, `scripts/pressed.mjs` lists controls with no pressed
state, and `record.mjs --throttle=fast4g` films the same scenario at the same profile, so a number
and the clip that shows it come from one file.

### Budgets

| Column | Measures, from the tap's pointerdown | Budget |
| --- | --- | --- |
| `response` | first frame after the DOM changed | 100 ms |
| `swap` | first frame after `inertia:navigate`, where the new page lands | none |
| `done` | first frame with Inertia idle and no `.skeleton` in the viewport, or the scenario's `until` visible | 400 ms |
| `shift` | layout shift after the swap | 0.05 |
| `inp` | longest event duration in the step | 200 ms |

A launch scenario reports `ttfb`, `fcp`, `lcp` and `done` from navigation start instead, cold (HTTP
cache cleared) and warm. Every run uses the iPhone 13 context, DevTools' Fast 4G with a 4x CPU
slowdown (`--throttle=none` turns it off), and blocked service workers so the throttle covers
every request.

### Run it

```bash
S=.claude/skills/browser-review

./vendor/bin/sail exec app node $S/scripts/bench.mjs $S/scenarios/bench/tabs.mjs --runs=5 --label=tabs-before
./vendor/bin/sail exec app node $S/scripts/pressed.mjs            # or a route list: /plan,/inbox
./vendor/bin/sail exec -e BASE=<prod origin> app node $S/scripts/bench.mjs $S/scenarios/bench/launch.mjs --label=prod-launch
./vendor/bin/sail exec app node $S/scripts/record.mjs tabs $S/scenarios/bench/tabs.mjs --side=before --throttle=fast4g
```

`pressed.mjs --signed-out /login` skips the demo login. `--click=<selector>` (Playwright syntax,
repeatable) clicks in order after each route loads and scans again in each state it opens, so a
modal or the next wizard step is covered: `pressed.mjs /activities/<id> --click='button:has-text("Share")'`.
The onboarding wizard needs a non-onboarded user; null the demo user's `onboarded_at` through
`artisan tinker --execute`, scan `/onboarding` with `--click='button:has-text("continue")'` and the
step buttons, then restore it. `DateField`'s calendar button only renders for a fine pointer, so scan
it with `--viewport=laptop`.

Results print as medians with the maximum in brackets, `!` marking a median over budget, and every
run lands in `storage/app/bench/<label>.json` (gitignored).

### Scenarios

`scenarios/bench/`: `tabs.mjs` (Today → Plan → Trends → History → Today), `push-back.mjs` (History
row → run → back chevron), `trends-chart.mjs` (tap Trends, scroll to the fitness chart, timed to
its canvas), `plan-skip.mjs` (skip and restore a session, timed to the toast) and `launch.mjs`. A scenario exports `route` and `default async ({ page, measure })`;
`measure(label, selector, { until })` taps the first match. Leave `until` off for a navigation.

`plan-skip.mjs` writes. It reverses its own write inside each run, so the demo plan ends where it
started; it is the only scenario that changes data.

### Limits

`pressed.mjs` sees `:active` the way Chromium applies it. iOS Safari applies `:active` on touch
only under a touch listener, so a control that passes here can still show nothing on an iPhone;
confirm on the device. The probe stamps frames from `requestAnimationFrame`, so a time is the frame
that will paint, up to one frame before the pixels.
