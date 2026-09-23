---
title: Backend and frontend tests are sharded on PRs and main pushes
description: Both suites use one sharded path on PRs and main pushes; only PR shards collect coverage, and their merged totals retain the existing thresholds.
tags: [decision, ci]
status: accepted
reviewed: 2026-09-21
code_refs:
  - .github/workflows/ci.yml
  - .github/workflows/backend-ci.yml
  - .github/workflows/frontend-ci.yml
  - .github/workflows/refresh-shards.yml
  - tests/.pest/shards.json
---

# Backend and frontend tests are sharded on PRs and main pushes

**Status:** Accepted (decided 2026-09-18)

> **Fact update, 2026-09-24.** `tests/.pest/shards.json` is no longer hand-regenerated. A nightly
> [refresh-shards.yml](../../.github/workflows/refresh-shards.yml) reruns `--update-shards
> --exclude-group=structure`, then `scripts/compare-shards.php` decides whether the drift is worth
> a PR (a class added or removed, or the old map's split re-measured on the new timings sitting
> more than 10% over the mean while a fresh split would actually do better). It opens or updates
> one `chore/refresh-shards` PR; its CI runs once the owner approves the workflow run on that PR.
> The decision and the regeneration command below stand
> unchanged; only "regenerate it when the suite's shape changes enough" is now automatic rather
> than manual.

Coverage roughly doubled backend tests on every pull request (186–340s vs ~145s on a push),
making them the PR's critical path by two to three minutes. Frontend tests were also near the
critical path because per-file jsdom setup dominated their runtime. Both suites now use the same
sharded jobs on PRs and main pushes. Coverage is the only event-specific difference: PR shards
collect it and merge the whole-suite totals, while push shards run without instrumentation or a
merge step.

Two alternatives were rejected:

- **Gating the deploy on main's coverage instead.** This just moves the ~150s from the PR to
  every deploy. Worse, a coverage failure then surfaces *after* merge, blocking that deploy and
  every later one until someone fixes forward on an already-merged tree.
- **Reporting coverage only on main, not gating it.** This stops enforcing 95% anywhere — the
  gate-weakening [#1003](https://github.com/nukipratama/temari/issues/1003) already rules out.

## What changed

Backend tests are split across `SHARD_TOTAL` (3) parallel GitHub-hosted jobs
(`tests`, [backend-ci.yml](../../.github/workflows/backend-ci.yml)), using Pest's
`--shard=i/N` ([Shard plugin](https://github.com/pestphp/pest)). Plain round-robin (by test-class
index, no timing data) put one file — `DemoSeedCommandTest`, the single heaviest file in the suite
even after [#1019](https://github.com/nukipratama/temari/issues/1019) — alongside enough other
classes to make its shard the long pole (measured: 105s vs ~45s for the other two shards' own
"Tests" step). `tests/.pest/shards.json` is committed instead: Pest's `--update-shards` records a
per-class wall-time timing, and every `--shard` run then uses LPT bin-packing to keep each shard's
*total* time close to equal rather than just its file count. Regenerate it (`--update-shards
--exclude-group=structure`, same flags as the real run) when the suite's shape changes enough that
balance drifts — Pest warns (doesn't fail) when it sees test classes the file doesn't know about.
On PRs, each shard writes its own raw coverage object with a plain PHPUnit
`--coverage-php` (not Pest's own `--coverage`/`--min`, which hardcodes an internal temp path and
deletes it after printing a single-run report); ParaTest — Pest's `--parallel` implementation —
already merges each of its own worker processes' coverage into that one file, so a shard ends up
with one combined object regardless of how many parallel workers ran inside it.

`coverage` ([backend-ci.yml](../../.github/workflows/backend-ci.yml)) downloads all PR shards'
`.cov` artifacts, merges them with `phpunit/phpcov merge` into a Clover report, and applies
`--min=95` exactly once against the merged project-level `<metrics statements coveredstatements>`
totals — the same executable-lines basis Pest's own `--min` uses. **The threshold is never
checked per shard**: a shard covering only its own slice of the suite is expected to sit well
under 95%, and that must not fail it on its own.

Frontend tests use the same three-way shape in [frontend-ci.yml](../../.github/workflows/frontend-ci.yml). PR shards write Vitest blob
reports with their individual thresholds disabled; its `coverage` job combines them and
applies the configured whole-suite thresholds once. Main-push shards run without coverage, and
shard 1 retains the asset build and entry-chunk budget checks that previously lived in the
unsharded push job.

Each reusable workflow has an internal `gate` that requires every shard and static-analysis job
on both events, plus its coverage merge on PRs. The top-level `ci-gate`
([ci.yml](../../.github/workflows/ci.yml)) then requires each changed suite as a unit. A missing,
cancelled or failed shard reds the gate, never a partial pass. Backend Pint, PHPStan and Rector
remain full-repository checks, but run in parallel because Rector—not PHPStan—was the measured
static-analysis long pole.

`phpunit/phpcov` was added as a pinned dev dependency (`composer.json`) purely to merge the
shards' coverage; it isn't used anywhere else in the toolchain.

## Sharding and `DemoSeedCommandTest`

`DemoSeedCommandTest` seeds the demo dataset once per PHP process
([#1019](https://github.com/nukipratama/temari/issues/1019)), not once globally. Sharding adds no
new assumption here: `--parallel --processes=N` already forked multiple worker processes inside a
single job before sharding existed, so "once per process" already had to hold under multiple
concurrent processes. A shard is just another such process.
