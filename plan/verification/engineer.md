# Verification rubric — engineer

**How to use**: dispatch a subagent with this file and the slice's diff (`git diff <base>...HEAD`)
plus its slice doc. It returns findings. **Every finding is resolved before the PR opens.** This is
not a rubber stamp — a slice with zero findings should be rare and suspicious on a large diff.

Report each finding as `file:line — what is wrong — why it matters`. Rank by severity. If a check
does not apply to this slice, say so explicitly rather than staying silent.

---

## 1. The ladder actually passed

Not "should pass". Ask for the terminal output. In order, stopping at the first failure:

```bash
./vendor/bin/sail pest --group=structure
./vendor/bin/sail bin pest --filter=<Name>
./vendor/bin/sail npm run test:coverage
./vendor/bin/sail npm run build && npm run check:chunks
./vendor/bin/sail composer check
```

- A `--no-tia` run is required if the slice deleted or renamed anything — TIA replays unchanged
  tests from cache and will happily replay a test whose subject no longer exists.
- `composer check` does **not** run `scripts/check-doc-citations.php`. If the slice touched `docs/`
  or deleted any file cited by a doc, that script must have been run directly.

## 2. The 1:1 test convention holds

- Every new PHP class has a matching test file — `tests/Unit/Architecture/EveryClassHasATestTest.php`
  enforces it, but check the *quality*, not just the existence.
- Every new `.tsx` / `.ts` under `resources/js` has a co-located test —
  `resources/js/test/structure.test.ts` enforces existence only.
- **A new entry in `EXEMPT`, `TS_EXEMPT` or the PHP exemption array is a finding** unless the slice
  doc names it and justifies it. Silently exempting a file to get green is the failure mode this
  check exists for.
- Deleted a component? Its `.test.tsx` must be deleted in the **same commit**.

## 3. Tests assert behaviour, not shape

- No snapshot tests added for restyled components. A snapshot of a component whose styling is being
  deliberately rewritten asserts nothing and will be blindly re-recorded.
- Assertions on rendered *semantics* (role, accessible name, visible text, state) rather than on
  class strings. `expect(el).toHaveClass('bg-card')` is a finding: it passes when the token is
  wrong and fails when the markup is refactored.
- A test that mocks the thing it is testing is a finding. Mocking siblings is fine and encouraged.

## 4. Nothing on the coupling list moved silently

Cross-check the diff against §8 of [../README.md](../README.md). Flag any of these touched without
the slice doc mentioning it:

- `resources/brand/grounds.json` — must be **regenerated**, never hand-edited.
- `resources/js/types/inertia.ts`, `resources/js/types/generated.ts`
- `tests/Unit/Architecture/DesignTokenMirrorsTest.php` (`MIRROR_FILES`, `OFF_TOKEN`)
- `resources/js/lib/cn.ts` — a renamed `--text-*` token breaks class merging **with no failing
  test**. If the diff renames a font-size token and does not touch `cn.ts`, that is a finding.
- `scripts/check-entry-chunks.mjs` — `ROUTE_BUDGETS_KB` hardcodes four source paths. A moved or
  renamed `Auth/Login.tsx`, `Home.tsx`, `Runs/Show.tsx` or `Profile.tsx` must update it.
- `tests/Unit/Architecture/EveryClassHasATestTest.php`, `resources/js/test/structure.test.ts`
- `docs/features/plan-periodizer.md` — if the slice deletes a file it cites, the doc edit must be in
  the **same commit**, or every open PR in the epic goes red.

## 5. Token discipline

- Ground-dependent values use the **semantic** layer (`bg-card`, `text-foreground`, `border-border`).
  Fixed-identity values use the **named palette** (`--mood-*`, `--rarity-*`, `--color-strava-orange`,
  `--color-horizon` as an accent fill). Using the wrong layer is a finding in both directions.
- No raw hex in `.tsx`, `.ts`, `.css` or `.blade.php`. **`npm run check:palette` does not catch
  this** — it checks Tailwind palette *utility class names*, off-token shadows and off-scale radii.
  Raw hex applied via an inline `style` prop ships completely ungated. Grep for it.
- Any new value that must survive on both grounds is registered in `grounds.json` and scored.

## 6. No orphans, no unrelated cleanup

- Imports, variables, helpers and types that **this change** made unused are removed.
- Pre-existing dead code is **not** removed — mention it, don't delete it. That is `W2`'s job.
- Adjacent code is not "improved", reformatted, or refactored. Every changed line traces to the
  slice's stated goal.

## 7. Scope

- The diff matches the slice doc's **Files touched**. A file outside that list is either a finding or
  a slice-doc amendment — but not silent.
- No speculative abstraction: no service injected for stateless logic, no config knob nobody asked
  for, no error handling for impossible states.
- Laravel: new integer columns (including `_id` FKs) are in `casts()` as `'integer'`; booleans and
  enums are cast. `is<Role>()`-style checks live on the backing enum.
