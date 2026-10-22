---
title: Local gate vs. CI
description: What composer gate / check:full run locally, what pre-commit runs, and what CI runs — and why they differ
tags: [architecture, toolchain]
status: living
reviewed: 2026-10-09
code_refs:
  - scripts/gate.sh
  - .githooks/pre-commit
  - .github/workflows/ci.yml
  - .github/workflows/release-deploy.yml
  - .github/workflows/ci-backend.yml
  - .github/workflows/ci-frontend.yml
  - composer.json
---

# Local gate vs. CI

Three layers, each catching a different class of problem at the point it's cheapest to fix.

## Pre-commit: formatting + cheap static analysis

[.githooks/pre-commit](../../.githooks/pre-commit) runs on every commit (not on `main`, which it
blocks outright): a gitleaks secret scan on all staged files, then — only when PHP or TS files are
staged — Rector (applying its fixes to the staged `app/` and `tests/` PHP), Pint, full-project
PHPStan, and ESLint/Prettier on the staged TS. These are fast enough to pay for every commit and give
the tightest feedback loop. A full-tree Rector pass was the slowest single step the hook ever carried,
so it stays in `check:full`; the gate keeps
its scoped dry run as the check. Each language branch is one `sail exec`, because the container
round-trip (~1.1 s) was the largest line item. PHPStan and ESLint stay full-tree: a scoped PHPStan run
cannot reuse the whole-project result cache (1.5 s warm across the tree against 5.0 s for one file),
and scoping ESLint once let two lint errors reach `main`, while the full-tree run costs 3.4 s.

## `composer gate`: the fast pre-push gate

[scripts/gate.sh](../../scripts/gate.sh) (wired as `composer gate` / `composer check:full` in
[composer.json](../../composer.json)) is a shared script with two modes:

- **fast** (`composer gate`, `sh scripts/gate.sh`): config clear, TS-enum drift check, the doc-citation
  and `{@see}` guards, the design-token palette guard, the structural Pest + Vitest suites, `tsc`,
  Rector `--dry-run` scoped to files changed since the merge-base, Vitest `--changed`, then only the
  Pest tests paired with changed files ([scripts/changed-tests.sh](../../scripts/changed-tests.sh):
  `{Name}Test.php` for each changed class, plus changed tests). Seconds, not minutes, so any number of
  worktrees can gate at once; the full suite is CI's. Each step's output goes to
  `storage/logs/gate.log`; a failing step prints its last 40 lines before the final `GATE:` line.
- **full** (`composer check:full`, `sh scripts/gate.sh --full`): everything fast mode runs, plus
  Pint/PHPStan/full-tree Rector (all in `--test`/dry-run form), ESLint/Prettier `--check`, the full
  Pest suite in parallel (no backend coverage; that is CI's), the migration-safety check against the
  merge-base, Vitest coverage, the asset
  build, and the bundle-chunk budget check. This reproduces what CI runs, opt-in and slow — for when
  the fast gate isn't enough confidence before a push.

Every changed-file step (Rector, Vitest `--changed`, Pest, and the full gate's migration-safety check)
diffs against one base from [scripts/vitest-changed-base.sh](../../scripts/vitest-changed-base.sh),
so the steps always agree. It takes the first of: `GATE_BASE` (a ref, passed into the container with
`./vendor/bin/sail exec -e GATE_BASE=origin/epic/x app sh scripts/gate.sh`), the PR base `gh` recorded
for the branch in git config (`branch.<name>.gh-merge-base`, read with no network call, used when its
`origin/` ref is fetched), then `origin/main`. A slice that targets an `epic/*` branch therefore gates
only its own changes instead of the whole epic. A `GATE_BASE` that does not resolve fails the gate
rather than falling back to `main`.

## CI: the full gate, plus deploy

[.github/workflows/ci.yml](../../.github/workflows/ci.yml) orchestrates the unconditional guards
and the image build while [ci-backend.yml](../../.github/workflows/ci-backend.yml) and
[ci-frontend.yml](../../.github/workflows/ci-frontend.yml) run each suite's tests, coverage and
static analysis directly. They do not shell out to `gate.sh`, so every check gets its own cache and
log. **CI is the authoritative full gate** — a green `composer gate` locally is a fast pre-push
signal, not a substitute for CI passing. On `push` to `main`, a successful CI run triggers
[release-deploy.yml](../../.github/workflows/release-deploy.yml), which rolls the built image; see [[deployment]]
for that half.

The backend suite and the frontend suite each run three parallel shards, on PRs and main pushes. The test jobs and the image build need only `changes`, so they start without waiting for `repo-guards`, which also runs the gitleaks secret scan; `ci-gate` still requires it. Backend shards start MySQL with a backgrounded `docker run` right after checkout and wait for it just before the tests, so its pull and init overlap PHP setup. PR shards collect coverage;
the frontend's `gate` job and, for the backend, `ci-gate` merge the whole-suite totals and apply the
configured thresholds exactly once. The two sides differ on purpose: the backend merge sits on the
critical path behind the slowest shard, so it runs inside `ci-gate` to save a job, while the
frontend `gate` (merge, build and chunk check) finishes while the backend shards still run, and
moving it into `ci-gate` would put that work on the critical path. Main-push shards skip instrumentation and the merge is skipped,
because the change was already coverage-gated before merge. Each side's single static-analysis job runs its tools in
sequence (Pint, PHPStan and Rector; TypeScript, ESLint and Prettier) and then that side's 1:1
structure check and source guard (`{@see}` references; the raw-palette guard), so the structure
checks run once per run instead of once per shard. The frontend workflow's `gate` requires all of
its shards and its static analysis on both events, and the top-level `ci-gate` requires each
changed suite as a unit, the backend's `tests` and `static-analysis` jobs included. A missing, cancelled or failed shard therefore reds the gate — see
[[sharded-pr-coverage]]. A newer push to
the same ref, `main` included, cancels the older run whole instead, and its `ci-gate` skips; see
[[deployment]] under "Superseded main runs".

PR frontend shards run Vitest in a shuffled order seeded with the workflow run id, and echo the seed
first in the step log (`Vitest shuffle seed: <id>`), so an order-dependent test goes red on the PR instead of
on a later unlucky run. Reproduce a red shard locally with
`./vendor/bin/sail npx vitest run --sequence.shuffle --sequence.seed=<id>` (add `--shard=<n>/3` to
match one shard); a red PR shard writes that exact command to its job summary. A re-run keeps the run id and
so the order, so only a new push reshuffles. The merge-reports coverage job and main-push shards stay in file order.

PR backend shards do the same with Pest's `--order-by=random --random-order-seed=<run id>`, echo
`Pest random order seed: <id>`, and a red shard writes its reproduce command to the job summary.
Sharding stays stable under it: Pest lists and partitions the test files before PHPUnit sorts them, and
every shard gets the same seed. Main-push shards stay in file order.

See also: [[deployment]].
