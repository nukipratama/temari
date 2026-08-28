# Verification rubric — devops

**How to use**: dispatch a subagent with this file and the slice's diff plus its slice doc. Every
finding is resolved before the PR opens.

The governing fact: `main` **auto-deploys to prod**. The epic does not (decision 15), but everything
that lands in the epic eventually reaches `main` in one enormous merge, and problems deferred here
surface there — at the worst possible moment, on the deploy critical path.

---

## 1. Gate compliance

Read [../../.github/workflows/ci.yml](../../.github/workflows/ci.yml) against the diff.

- **The `changes` path filter.** Does this slice touch files that *should* trigger `backend` or
  `frontend` but do not match the filter regexes? A new top-level directory, or a script outside
  `scripts/check-entry-chunks.mjs` / `check-raw-palette.mjs`, silently skips its suite.
- **`docs-citation-guard` and `secret-scan` are unconditional.** They run on every PR regardless of
  paths. A deletion anywhere can orphan a `docs/` citation and redden PRs that never touched it.
- **`ci-gate`** is the single required check. A job that is *skipped* counts as a pass — so a slice
  that accidentally dodges the path filter goes green having tested nothing. Check for this.
- Coverage: with decision 17 landed, a **push** to the epic runs `npm run test:coverage`
  (threshold-gated) while a **PR** runs plain `npm run test`. So the coverage bill is paid on merge,
  not in review. The slice must have run `test:coverage` locally and recorded the delta.
- `concurrency.cancel-in-progress` is true for any ref that is not `main`. Rapid consecutive merges
  into the epic cancel each other's coverage runs. If several slices merge back-to-back, confirm the
  last run actually completed.

## 2. Never, under any circumstances

- **No `artisan config:cache` in a CI test step.** `phpunit.xml` `<env>` overrides only apply after
  PHPUnit boots; a pre-cached config freezes `.env.example` values and silently breaks
  dispatch-assertion tests.
- **No `artisan config:cache` at Docker build time.** No `.env` is loaded, `env()` falls back to PHP
  defaults (e.g. `DB_CONNECTION` → sqlite), and that gets frozen into the image.
- No `--no-verify`, no `--force`, no gate weakened to get a slice green. If a guard is genuinely
  wrong, re-author it **in its own commit** with a test proving it still rejects what it should
  (this is exactly R10's shape).

## 3. Migrations

- Reversible, or explicitly documented as not. `down()` exists and is correct.
- Safe against a live table: no blocking `ALTER` on a large table without a stated plan, no
  `NOT NULL` column added without a default or a backfill step ordered before it.
- The deploy runs `migrate --force` **before** the app rolls, on a one-shot container of the new
  image. So a migration must be compatible with the **old** code still serving traffic for the
  duration of the roll.
- **The analytics connection is separate.** Migrations for it live in
  `database/migrations/analytics/` and run via `--database=analytics --path=...` as its own deploy
  step. A metering table placed in the default path silently lands in the wrong schema.
- The deploy's auto-rollback is **disabled** for any deploy where migrations ran. A destructive
  migration therefore has no automatic recovery — flag it.

## 4. Bundle budget

Run, and read the output:

```bash
./vendor/bin/sail npm run build && npm run check:chunks
```

- [../../scripts/check-entry-chunks.mjs](../../scripts/check-entry-chunks.mjs) enforces
  `ENTRY_ALLOWED = ['rolldown-runtime', 'app', 'react-vendor']` and per-route gzip budgets against
  four hardcoded source paths. Login is capped at **160 kB gz**.
- `bareLayout` is enforced **framer-motion-free**. A motion import reaching it fails the guard.
  Rebuild motion as plain CSS instead.
- Base UI drags `@floating-ui`. If this slice adds a Base-UI-backed primitive to a route in
  `ROUTE_BUDGETS_KB`, the `base-ui` chunk group must exist in `vite.config.ts` and the route must
  still be under budget (R6).
- A new heavyweight dependency on a first-paint route (chart engine, map, animation lib) is a
  finding even if it happens to fit today.

## 5. Runtime + secrets

- No `.env` read, no `config:show` / `config:get` output pasted anywhere. Secret **names** come from
  `.env.example`; values are the user's to supply.
- A new env var is added to `.env.example` **and** to `compose.prod.yaml`'s `env_file:` story —
  prod loads from the host `.env`, and CI does not inject these values.
- No `Model::factory()` reachable from production code paths. Faker is `require-dev`; it crashes in
  prod.
- Anything touching Strava rate limits: the limit is **per client, not per athlete** (200/15min,
  2000/day read). A guard key must be global, never keyed by `user_id`.

## 6. Homelab reality

- The self-hosted runner is **4 cores, shared with the running prod stack**, and there are two runner
  agents on **one** host sharing a single docker daemon. Do not raise CI parallelism.
- It is docker-out-of-docker: a repo bind mount into a prod container silently becomes an empty
  directory. Bake into the image or pipe via stdin.
- Nothing in this slice may `ssh homelab` or touch prod. That is a human-approved action, every
  time, even read-only.
