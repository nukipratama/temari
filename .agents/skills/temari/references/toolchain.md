## Toolchain (everything in Docker via Sail)

```bash
./vendor/bin/sail up -d                      # start the stack
./vendor/bin/sail pest --group=structure     # fast 1:1 + aggregate structural gate (run first)
./vendor/bin/sail bin pest --filter=Name     # a single test / file while iterating
./vendor/bin/sail npm run test               # frontend (Vitest); `test:coverage` for the 95% gate
./vendor/bin/sail npm run build              # build assets (`npm run dev` for HMR)
./vendor/bin/sail bin pint                    # format PHP (pre-commit also runs phpstan + eslint)
./vendor/bin/sail composer gate              # fast pre-push gate: tests for changed files only; CI runs the full suite
./vendor/bin/sail composer check:full        # reproduce CI locally, opt-in, slow
```

Use the ladder above: start with structure or the
narrowest targeted test, stop at the first failure, and widen only after it passes.
Both modes are [scripts/gate.sh](../../../../scripts/gate.sh); it stops at the first failure and its
last line is `GATE: PASS (<n>s, mode=fast|full)` or `GATE: FAIL at <step> (<n>s)`. Step output goes to
`storage/logs/gate.log`; a failing step prints its last 40 lines above the `GATE:` line.
Pint/phpstan/eslint run on **pre-commit**; the fast gate runs **scoped rector on changed files**
(`app/`+`tests/` PHP since the merge base, plus uncommitted ones — sub-second warm), and the
full-tree `rector --dry-run` stays in **CI** and `check:full`. CI is the
full gate and is what `main` is protected by; coverage is CI-owned and only in `check:full`.

**Local Pest runs always execute.** The gate's `pest changed` step runs only the `{Name}Test.php`
files paired with the PHP classes changed since the merge base, plus changed test files
([scripts/changed-tests.sh](../../../../scripts/changed-tests.sh), the same basename pairing as
`EveryClassHasATestTest`); the `structure` group still runs in full. The whole suite and coverage are
CI's, on free GitHub-hosted runners, so a change that breaks some *other* class's test is caught
there, not locally. There is no test impact analysis: Pest 5's TIA was removed because it silently
fell back to full runs and its CI baseline never published a usable graph (#1226).

**When CI's coverage gate goes red** you can reproduce it locally — `pcov` ships in the dev image,
so this is a debugging tool, not a routine step:

```bash
./vendor/bin/sail bin pest --coverage --filter=Name   # ~6s: is MY class covered?
./vendor/bin/sail bin pest --parallel --coverage --min=95   # ~64s: will the gate pass?
```

The filtered run reports 0.0% for everything else, which is expected — read only your own class's row.

Worktrees need **git inside the container** for the gate's changed-file steps (rector, `vitest
--changed`, `pest changed`). `scripts/worktree` setup writes a `compose.override.yaml` that bind-mounts the
shared git dir **at the same absolute path it has on the host**. A worktree's `.git` is a *file*
holding that host path, so mounting it anywhere else leaves the pointer dangling. Same path in and out
means git resolves the repo from `/var/www/html` natively, with **no git environment variables at
all** — which matters because Composer strips `GIT_DIR`/`GIT_WORK_TREE` from every script it runs, so
anything built on them died under `composer gate` anyway. A worktree stack brought up without that
override fails the gate at its first changed-file step (`vitest-changed-base: git cannot read this
checkout`).

Setting `GIT_DIR` was the old mechanism and it was actively harmful: with `GIT_DIR` pointing at a
worktree slot under a differently-mounted common dir, container-side git persisted
`core.worktree=/var/www/html` into the **host's** shared `.git/config`, and every host `git` command
then failed with *"this operation must be run in a work tree"*. Don't reintroduce it.

`vitest --changed`'s base is resolved by
[scripts/vitest-changed-base.sh](../../../../scripts/vitest-changed-base.sh), which fails the gate
rather than falling back — a bare `vitest --changed` diffs the working tree against HEAD, which on a
clean checkout selects nothing and exits 0. Cost of getting that wrong: it found no git, reported
`No test files found`, and the step passed having run zero frontend tests.

**Dev commands:**
- After changing a PHP enum exposed to TS: `./vendor/bin/sail artisan typescript:enums` (`--check` mirrors CI).
- Local UI/demo data (deterministic, no LLM tokens, no Strava HTTP): `./vendor/bin/sail artisan demo:seed`. Idempotent, re-run any time to converge. It only upserts the current blueprint set, so to purge rows from retired blueprints do a full reset: `./vendor/bin/sail artisan migrate:fresh` then `demo:seed`.
