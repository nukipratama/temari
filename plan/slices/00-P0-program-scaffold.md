# P0 — Program scaffold

**Wave** 0 · **Slot** main checkout · **Blockers** none · **Status** in-progress

## Goal

Create the tracked `plan/` tree so that every later slice, in every worktree, reads the same
orchestrator, the same rubrics and the same decision table. Land decision 17 in the same PR so
coverage signal exists from the very first merge into the epic.

No application code changes. Nothing under `app/`, `resources/js/`, `resources/css/` or `database/`
is touched.

## Files touched

| path | what |
|---|---|
| `plan/README.md` | orchestrator + living tracker |
| `plan/ledger.md` | written by `L0` |
| `plan/ia.md` | written by `L0` |
| `plan/verification/{engineer,devops,copywriter}.md` | subagent rubrics, run per slice |
| `plan/verification/{product-manager,designer}.md` | human templates, worked per wave |
| `plan/slices/*.md` | 30 docs; `00` and `01` full, the other 28 stubs |
| `plan/codemods/README.md` | placeholder; `F3` fills the directory |
| `.github/workflows/ci.yml` | decision 17 — one line |

## The CI change, precisely

```diff
 on:
   pull_request:
   push:
-    branches: [main]
+    branches: [main, 'epic/*']
```

**Widened from `epic/rebrand-temari` to the glob `epic/*`** (follow-up, same PR as `F1`, after the
nested-branch discovery — see the §5 amendment for decision 17): a literal branch name would have
covered `epic/rebrand-temari` but not the nested `epic/mobile-ux-port` this program actually pushes
to, nor any future nested epic. GitHub Actions matches `branches` patterns per-segment, so `epic/*`
covers every current epic branch (`epic/rebrand-temari`, `epic/mobile-ux-port`,
`epic/experience-rebuild`, `epic/ui-revamp`, `epic/v2`, `epic/ai-resume` — all single-segment names
after `epic/`) without needing a maintained list.

Verified safe against the rest of the workflow before writing it:

- `deploy` gates on `github.ref == 'refs/heads/main' && github.event_name == 'push'`. An epic push
  cannot deploy.
- `build` (the prod image push) gates on `(main && push) || changes.docker == 'true'`, and only
  pushes when `github.event_name == 'push'` — on the epic it runs only if docker inputs changed, and
  a `:sha` tag the deploy never pulls is harmless.
- `frontend-tests` runs `npm run test:coverage` (threshold-gated) whenever the event is **not** a
  pull request. That is the entire point: the 95% JS gate now fires on every merge into the epic
  rather than once, at the epic→main merge, across ~190 components. This is R3.
- `backend-tests` keeps its coverage on `pull_request` only, and `setup-php` keeps `pcov` off for
  pushes. Unchanged.
- `changes` diffs against `merge-base(origin/main, HEAD)`, which on the epic is the whole epic diff,
  so both suites will effectively always run. Acceptable and intended.

**Known consequence, accepted:** `concurrency.cancel-in-progress` is true for any ref that is not
`main`, so back-to-back merges into the epic cancel each other's coverage runs. If several slices
land in quick succession, confirm the last run completed before trusting the number.

## Acceptance criteria

1. `plan/` exists with all files listed above; every relative link in `plan/README.md` resolves.
2. `scripts/check-doc-citations.php` passes. It walks `docs/` only, so `plan/` sits outside its
   scan by design — but run it anyway to prove the CI edit did not break the unconditional job.
3. `.github/workflows/ci.yml` parses and CI runs green on the PR.
4. After merge, the epic push produces a run whose `frontend-tests` job logs
   `Running Vitest with coverage (threshold gate)`.
5. Nothing outside `plan/` and `.github/workflows/ci.yml` appears in the diff.

## Coverage delta

`n/a` — no application code.

## Verification notes

- Engineer rubric: scope check only; §1-§7 mostly do not apply and should be reported as such.
- Devops rubric: §1 in full. This slice **is** a CI change; it gets the closest reading of any slice
  in the program.
- Copywriter rubric: does not apply.

## Open questions

None. Everything here was settled in the planning session.
