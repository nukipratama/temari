---
title: Local gate vs. CI
description: What composer gate / check:full run locally, what pre-commit runs, and what CI runs — and why they differ
tags: [architecture, toolchain]
status: living
reviewed: 2026-09-21
code_refs:
  - scripts/gate.sh
  - .githooks/pre-commit
  - .github/workflows/ci.yml
  - .github/workflows/backend-ci.yml
  - .github/workflows/frontend-ci.yml
  - composer.json
---

# Local gate vs. CI

Three layers, each catching a different class of problem at the point it's cheapest to fix.

## Pre-commit: formatting + cheap static analysis

[.githooks/pre-commit](../../.githooks/pre-commit) runs on every commit (not on `main`, which it
blocks outright): a gitleaks secret scan on all staged files, then — only when PHP or TS files are
staged — Pint, full-project PHPStan, and ESLint/Prettier on the staged TS. These are fast enough to
pay for every commit and give the tightest feedback loop. Rector does **not** run here; a full-tree
Rector pass was the slowest single step the hook ever carried, so it moved to the gate (scoped) and
`check:full` (full), per [#832](https://github.com/nukipratama/temari/pull/832).

## `composer gate`: the fast pre-push gate

[scripts/gate.sh](../../scripts/gate.sh) (wired as `composer gate` / `composer check:full` in
[composer.json](../../composer.json#L70)) is a shared script with two modes:

- **fast** (`composer gate`, `sh scripts/gate.sh`): config clear, TS-enum drift check, the doc-citation
  and `{@see}` guards, the design-token palette guard, the structural Pest + Vitest suites, `tsc`,
  Rector `--dry-run` scoped to files changed since the merge-base, Vitest `--changed`, then only the
  Pest tests paired with changed files ([scripts/changed-tests.sh](../../scripts/changed-tests.sh):
  `{Name}Test.php` for each changed class, plus changed tests). Seconds, not minutes, so any number of
  worktrees can gate at once; the full suite is CI's.
- **full** (`composer check:full`, `sh scripts/gate.sh --full`): everything fast mode runs, plus
  Pint/PHPStan/full-tree Rector (all in `--test`/dry-run form), ESLint/Prettier `--check`, the full
  Pest suite in parallel, Vitest coverage, the asset
  build, and the bundle-chunk budget check. This reproduces what CI runs, opt-in and slow — for when
  the fast gate isn't enough confidence before a push.

## CI: the full gate, plus deploy

[.github/workflows/ci.yml](../../.github/workflows/ci.yml) orchestrates the unconditional guards,
build and deployment while [backend-ci.yml](../../.github/workflows/backend-ci.yml) and
[frontend-ci.yml](../../.github/workflows/frontend-ci.yml) run each suite's tests, coverage and
static analysis directly. They do not shell out to `gate.sh`, so every check gets its own cache and
log. **CI is the authoritative full gate** — a green `composer gate` locally is a fast pre-push
signal, not a substitute for CI passing. On `push` to `main`, `deploy` additionally builds and
rolls the image once `ci-gate` and `build` both pass; see [[deployment]] for that half.

Both test suites run three parallel shards on PRs and main pushes. PR shards collect coverage;
their `coverage` jobs merge the whole-suite totals and apply the configured thresholds exactly
once. Main-push shards skip instrumentation and the merge jobs because the change was already
coverage-gated before merge. Each reusable workflow's `gate` requires all of its shards and static
analysis on both events, plus coverage on PRs, and the top-level `ci-gate` requires each changed
suite as a unit. A missing, cancelled or failed shard therefore reds the gate — see
[docs/decisions/sharded-pr-coverage.md](../decisions/sharded-pr-coverage.md).

See also: [[deployment]].
