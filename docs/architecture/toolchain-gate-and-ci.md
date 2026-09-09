---
title: Local gate vs. CI
description: What composer gate / check:full run locally, what pre-commit runs, and what CI runs — and why they differ
tags: [architecture, toolchain]
status: living
reviewed: 2026-09-09
code_refs:
  - scripts/gate.sh
  - .githooks/pre-commit
  - .github/workflows/ci.yml
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
[composer.json:70](../../composer.json)) is a shared script with two modes:

- **fast** (`composer gate`, `sh scripts/gate.sh`): config clear, TS-enum drift check, the doc-citation
  and `{@see}` guards, the design-token palette guard, the structural Pest + Vitest suites, `tsc`,
  Rector `--dry-run` scoped to files changed since the merge-base, Vitest `--changed`, then the full
  Pest suite in parallel. Meant to run in a couple of minutes before every push.
- **full** (`composer check:full`, `sh scripts/gate.sh --full`): everything fast mode runs, plus
  Pint/PHPStan/full-tree Rector (all in `--test`/dry-run form), ESLint/Prettier `--check`, Pest
  `--no-tia` (forces real execution instead of replaying TIA's cache), Vitest coverage, the asset
  build, and the bundle-chunk budget check. This reproduces what CI runs, opt-in and slow — for when
  the fast gate isn't enough confidence before a push.

## CI: the full gate, plus deploy

[.github/workflows/ci.yml](../../.github/workflows/ci.yml) does not shell out to `gate.sh`; its jobs
run the same checks directly so each can be its own job with its own cache and log — structural
Pest ([:149](../../.github/workflows/ci.yml)), the full Pest suite with coverage
([:173-178](../../.github/workflows/ci.yml)), the Vitest structure test, Pint and PHPStan
(`--test`/no dry-run, [:267](../../.github/workflows/ci.yml) and
[:270](../../.github/workflows/ci.yml)), and more. **CI is the authoritative full gate** — a green
`composer gate` locally is a fast pre-push signal, not a substitute for CI passing. On `push` to
`main`, `deploy` additionally builds and rolls the image once `ci-gate` and `build` both pass; see
[[deployment]] for that half.

See also: [[deployment]].
