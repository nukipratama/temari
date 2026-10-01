## Before merging to the epic — the probes are not in CI

CI is pest + vitest. There is no browser, and there will not be one: the runner is 4 cores shared
with prod. So of the five ways a value can wear the wrong ground, **only two are enforced
automatically**:

| what | covered by | in CI |
|---|---|---|
| `bg-<token>` and its alpha panels | `grounds.json` + `DesignTokenContrastTest` | yes |
| gradient stops | `npm run check:palette` | yes |
| `text-*` on a painted surface | `contrast.mjs` | **no** |
| `border-*` / `ring-*` | `edges.mjs` | **no** |
| anything only visible once opened | `states.mjs` | **no** |

The bottom three found every wrong-ground bug of the audit that produced them, including two at
**1.00:1**, and every one was findable only by someone running these by hand. Treat that as the
standing cost of the constraint, not as coverage.

**Run this before merging a branch that touches tokens, `app.css`, or any component's surface,
border or text classes.** Build first — the review server serves `public/build`, so an unbuilt run
silently checks stale output.

```bash
./vendor/bin/sail npm run build
./vendor/bin/sail artisan demo:seed --with-edge-states
for g in dark light; do
  ./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/contrast.mjs $g
  ./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/edges.mjs $g
  ./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/states.mjs $g
done
./vendor/bin/sail exec app node .agents/skills/browser-review/scripts/light-islands.mjs dark
```

Compare against the baselines each section below documents. A number that moved is the finding;
a number that held is the result. Neither is "clean" on its own — `contrast.mjs` read **dark 0**
through three sweeps while a 316x280 near-white block shipped in a state the seed could not produce.
