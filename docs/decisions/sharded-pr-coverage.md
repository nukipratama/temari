---
title: PR backend coverage is sharded, not moved off the PR
description: The 95% gate stays on pull_request; pcov's ~150s cost is cut by splitting backend tests across parallel shards and merging their coverage, not by relocating the gate to main or push.
tags: [decision, ci]
status: accepted
reviewed: 2026-09-18
code_refs:
  - .github/workflows/ci.yml
  - tests/.pest/shards.json
---

# PR backend coverage is sharded, not moved off the PR

**Status:** Accepted (decided 2026-09-18)

Coverage roughly doubled `backend-tests` on every pull request (186–340s vs ~145s on a push),
making it the PR's critical path by two to three minutes. `pcov` is loaded only in the PR-only
shard job ([ci.yml](../../.github/workflows/ci.yml#L227)); the push job never carries it
([ci.yml](../../.github/workflows/ci.yml#L144)), because a push only ever lands via a PR that
already ran the gate.

Two alternatives were rejected:

- **Gating the deploy on main's coverage instead.** This just moves the ~150s from the PR to
  every deploy. Worse, a coverage failure then surfaces *after* merge, blocking that deploy and
  every later one until someone fixes forward on an already-merged tree.
- **Reporting coverage only on main, not gating it.** This stops enforcing 95% anywhere — the
  gate-weakening [#1003](https://github.com/nukipratama/temari/issues/1003) already rules out.

## What changed

Backend tests with coverage are split across `SHARD_TOTAL` (3) parallel GitHub-hosted jobs on a
PR only (`backend-tests-shard`, [ci.yml](../../.github/workflows/ci.yml#L196)), using Pest's
`--shard=i/N` ([Shard plugin](https://github.com/pestphp/pest)). Plain round-robin (by test-class
index, no timing data) put one file — `DemoSeedCommandTest`, the single heaviest file in the suite
even after [#1019](https://github.com/nukipratama/temari/issues/1019) — alongside enough other
classes to make its shard the long pole (measured: 105s vs ~45s for the other two shards' own
"Tests" step). `tests/.pest/shards.json` is committed instead: Pest's `--update-shards` records a
per-class wall-time timing, and every `--shard` run then uses LPT bin-packing to keep each shard's
*total* time close to equal rather than just its file count. Regenerate it (`--update-shards
--exclude-group=structure`, same flags as the real run) when the suite's shape changes enough that
balance drifts — Pest warns (doesn't fail) when it sees test classes the file doesn't know about.
Each shard writes its own raw coverage object with a plain PHPUnit
`--coverage-php` (not Pest's own `--coverage`/`--min`, which hardcodes an internal temp path and
deletes it after printing a single-run report); ParaTest — Pest's `--parallel` implementation —
already merges each of its own worker processes' coverage into that one file, so a shard ends up
with one combined object regardless of how many parallel workers ran inside it.

`backend-coverage-merge` ([ci.yml](../../.github/workflows/ci.yml#L289)) downloads all shards'
`.cov` artifacts, merges them with `phpunit/phpcov merge` into a Clover report, and applies
`--min=95` exactly once against the merged project-level `<metrics statements coveredstatements>`
totals — the same executable-lines basis Pest's own `--min` uses. **The threshold is never
checked per shard**: a shard covering only its own slice of the suite is expected to sit well
under 95%, and that must not fail it on its own.

`ci-gate` ([ci.yml](../../.github/workflows/ci.yml#L456)) requires every shard and the merge step
the same way it requires any other job — a missing, cancelled or failed shard reds the gate, never
a partial pass. A push still runs the plain, unsharded, uninstrumented suite in `backend-tests`
([ci.yml](../../.github/workflows/ci.yml#L117)); sharding a push wasn't measured to help (see the
PR that introduced this for the before/after numbers) and the owner's instruction was to leave it
alone absent a measured benefit there too.

`phpunit/phpcov` was added as a pinned dev dependency (`composer.json`) purely to merge the
shards' coverage; it isn't used anywhere else in the toolchain.

## Sharding and `DemoSeedCommandTest`

`DemoSeedCommandTest` seeds the demo dataset once per PHP process
([#1019](https://github.com/nukipratama/temari/issues/1019)), not once globally. Sharding adds no
new assumption here: `--parallel --processes=N` already forked multiple worker processes inside a
single job before sharding existed, so "once per process" already had to hold under multiple
concurrent processes. A shard is just another such process.
