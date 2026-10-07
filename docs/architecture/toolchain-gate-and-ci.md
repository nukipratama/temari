---
title: Local gate vs. CI
description: What composer gate / check:full run locally, what pre-commit runs, and what CI runs — and why they differ
tags: [architecture, toolchain]
status: living
reviewed: 2026-10-06
code_refs:
  - scripts/gate.sh
  - .githooks/pre-commit
  - .github/workflows/ci.yml
  - .github/workflows/deploy.yml
  - .github/workflows/backend-ci.yml
  - .github/workflows/frontend-ci.yml
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
so it stays in `check:full`, per [#832](https://github.com/nukipratama/temari/pull/832); the gate keeps
its scoped dry run as the check.

## `composer gate`: the fast pre-push gate

[scripts/gate.sh](../../scripts/gate.sh) (wired as `composer gate` / `composer check:full` in
[composer.json](../../composer.json#L70)) is a shared script with two modes:

- **fast** (`composer gate`, `sh scripts/gate.sh`): config clear, TS-enum drift check, the doc-citation
  and `{@see}` guards, the design-token palette guard, the structural Pest + Vitest suites, `tsc`,
  Rector `--dry-run` scoped to files changed since the merge-base, Vitest `--changed`, then only the
  Pest tests paired with changed files ([scripts/changed-tests.sh](../../scripts/changed-tests.sh):
  `{Name}Test.php` for each changed class, plus changed tests). Seconds, not minutes, so any number of
  worktrees can gate at once; the full suite is CI's. Each step's output goes to
  `storage/logs/gate.log`; a failing step prints its last 40 lines before the final `GATE:` line.
- **full** (`composer check:full`, `sh scripts/gate.sh --full`): everything fast mode runs, plus
  Pint/PHPStan/full-tree Rector (all in `--test`/dry-run form), ESLint/Prettier `--check`, the full
  Pest suite in parallel, Vitest coverage, the asset
  build, and the bundle-chunk budget check. This reproduces what CI runs, opt-in and slow — for when
  the fast gate isn't enough confidence before a push.

## CI: the full gate, plus deploy

[.github/workflows/ci.yml](../../.github/workflows/ci.yml) orchestrates the unconditional guards
and the image build while [backend-ci.yml](../../.github/workflows/backend-ci.yml) and
[frontend-ci.yml](../../.github/workflows/frontend-ci.yml) run each suite's tests, coverage and
static analysis directly. They do not shell out to `gate.sh`, so every check gets its own cache and
log. **CI is the authoritative full gate** — a green `composer gate` locally is a fast pre-push
signal, not a substitute for CI passing. On `push` to `main`, a successful CI run triggers
[deploy.yml](../../.github/workflows/deploy.yml), which rolls the built image; see [[deployment]]
for that half.

The backend suite and the frontend suite each run three parallel shards, on PRs and main pushes. The test jobs and the image build need only `changes`, so they start without waiting for `repo-guards`, which also runs the gitleaks secret scan; `ci-gate` still requires it. Backend shards start MySQL with a backgrounded `docker run` right after checkout and wait for it just before the tests, so its pull and init overlap PHP setup. PR shards collect coverage;
each suite's `gate` job merges the whole-suite totals and applies the configured thresholds exactly
once. Main-push shards skip instrumentation, and their `gate` skips the merge, because the change
was already coverage-gated before merge. Each side's single static-analysis job runs its tools in
sequence (Pint, PHPStan and Rector; TypeScript, ESLint and Prettier) and then that side's 1:1
structure check and source guard (`{@see}` references; the raw-palette guard), so the structure
checks run once per run instead of once per shard. Each reusable workflow's `gate` requires all of
its shards and its static analysis on both events, and the top-level `ci-gate` requires each
changed suite as a unit. A missing, cancelled or failed shard therefore reds the gate — see
[docs/decisions/sharded-pr-coverage.md](../decisions/sharded-pr-coverage.md). A newer push to
the same ref, `main` included, cancels the older run whole instead, and its `ci-gate` skips; see
[[deployment]] under "Superseded main runs".

See also: [[deployment]].
