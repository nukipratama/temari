# PS11 — Settings

**Program** prototype parity · **Slot** 3 (worktree, concurrent with two sibling slices) ·
**Blockers** `PP0`-`PP3`, `PP1` (shell), `C1` · **Status** in-review

## Goal

Rebuild `/settings` to the prototype's section list, order and treatment at P2 fidelity, against
[SettingsScreen.tsx](../../../resources/brand/prototype/src/components/pages/SettingsScreen.tsx)
with [reference.md](../reference.md) §13 as cross-check — and resolve **both** of the two
wide-only reflows `PP1` deferred to this slice (#10, the HR-zone bounds grid, and #11,
`AccountActions`).

Settings is a **pushed** screen (P6): back chevron, no bottom nav, back → Profile (P33). It is one
of the three screens that draw **no `FaceIcon`** (P10); none is added. P20 keeps the four legal
pages and Settings keeps linking to them. The appearance toggle shipped in `S11` stays and takes
**no** container query (P5's correction).

## Both deferred reflows, resolved

### Reflow #10 — `PP1`'s premise was false; carried verbatim, zero data change

`PP1`'s deferral table says "the prototype mocks **three** inputs in that grid; the shipped
`HrZonesDisclosure` has **two**", and its open question 3 says the reflow "becomes portable
verbatim if `PS11` adds the third and fourth zone-bounds inputs the prototype draws".

**There is no third or fourth input.** `SettingsScreen.tsx:473-490` is a `grid grid-cols-2 gap-2.5
@min-[900px]:grid-cols-4` containing exactly **two** `<label>`s — `max hr` (`defaultValue={188}`,
:476-480) and `resting hr` (`defaultValue={52}`, :483-487) — and nothing else. The five `ZONE_BOUNDS`
rows (decl :44-50, rendered :498-513) are a **separate** block below the grid and the
"auto-calculate" button, each a label plus one input in a `flex items-center justify-between` row,
not a grid cell.

`reference.md` is right and only `PP1` is wrong: §13's section table calls the block "max/resting HR
inputs (476-489)", and §1.2 row 10 records the reflow as a bare `grid-cols-2` → `grid-cols-4` with
no claim about the field count.

So the reflow is portable **as-is**, and the question the brief asked to stop on — whether the extra
inputs are real data or mockup shape — does not arise: there is nothing to add, and no data change.
Carried as a one-utility change: `min-[900px]:grid-cols-4` on the app's existing two-column
max/resting grid.

`PP1`'s stated objection ("four columns for two fields leaves each input a quarter-width") is the
prototype's *intent*, not a defect. Inside the 760px column the card's inner width is ≈696px; four
columns put each bpm field at ≈165px. A three-digit heart rate does not want the ≈340px a
two-column split gives it. The prototype narrows the fields on purpose and the empty right half is
the point.

### Reflow #11 — the section is restructured, so the reflow lands verbatim

`PP1`: "the prototype draws a button pair; the app draws a `SettingsRow` list with `mdi:logout`.
There is no row to turn sideways until `PS11` restructures the section."

Restructured. `AccountActions` (:579-597) is now what the app draws: a full-width bordered
"log out" button with a logout glyph, and a bare destructive "delete account" text button beneath
it — `flex-col items-center gap-3` → `min-[900px]:flex-row min-[900px]:justify-center`, the log-out
button `w-full` → `min-[900px]:w-auto min-[900px]:px-6`. Both container and button halves of the
reflow are carried.

The delete-confirm modal survives the restructure (P1: plumbing a static mockup cannot draw), now
opened by the text button instead of a row.

## The zone-bounds editor: ten inputs become five

Separate from reflow #10, and the one structural change inside `HrZonesDisclosure`.

The prototype draws **one** input per zone (:507-510, five rows over `ZONE_BOUNDS`). The app drew
**two** per zone — `lo` and `hi` — nine editable fields plus a `∞` sentinel for `Z5.hi`, with an
inline "Some zones don't line up" error and a `zones.N.hi` invalid state per field.

Those `hi` fields carry no information. `UpdateHrZonesRequest::enforceZoneInvariants()` rejects any
submission where `hi[i] !== lo[i+1]`, so given the five lower bounds every upper bound is already
determined, and the only submissions the extra fields make expressible are ones the server refuses.
Collapsing to the prototype's five inputs therefore loses nothing, and it makes the gap/overlap
error class **structurally unreachable** rather than merely validated.

Client-only: the PATCH payload still sends `{lo, hi}` pairs, derived on submit. `UpdateHrZonesRequest`,
`HeartRateZones` and the route are byte-unchanged. The two server invariants that survive
(`zones.0.lo >= resting_hr`, `zones.4.hi > max_hr`) still surface on the Z1 field and as a
form-level message.

## Files touched

**Rewritten**: `resources/js/pages/Settings/Index.tsx`,
`resources/js/components/settings/HrZonesDisclosure.tsx`,
`resources/js/components/settings/TrainingPreferencesDisclosure.tsx` → `TrainingPreferencesCard.tsx`
(+ their tests).
**Touched**: `resources/js/components/settings/AppearanceCard.tsx`.
**Reused, not rebuilt**: `components/onboarding/{IconChoiceCard,SessionsDial,DayPicker}.tsx` —
already 1:1 ports of the prototype's `PreferenceControls.tsx`, which Settings and Onboarding share
in the prototype too.
**Generated**: `resources/brand/grounds.json`.
**Docs**: `docs/features/settings.md`, `docs/features/settings-hr-zones.md`,
`plan/parity/slices/PP1-shell-nav.md` (reflow accounting).

## Blockers

None outstanding. `PP1` shipped the shell (back chevron, no bottom nav, 900px/760px column) and
`S11` shipped the appearance toggle, so this slice is content-only.

## Acceptance criteria

- [x] Section list and order match the prototype: eyebrow → h1 → appearance → notifications →
      running (**training preferences first, then the zones disclosure**) → data use → the fine
      print → account.
- [x] No `FaceIcon` anywhere on the screen (P10).
- [x] Reflow #10 carried: `min-[900px]:grid-cols-4` on the max/resting HR grid.
- [x] Reflow #11 carried: both the container and the button halves.
- [x] Training preferences render as an always-open card (the prototype's `TrainingPreferencesCard`),
      not a disclosure, using the three shared preference controls.
- [x] The zones disclosure is **closed by default**, as the prototype has it.
- [x] Five zone-bound inputs, not ten; saving still sends valid `{lo, hi}` pairs.
- [x] Both grounds check out; `grounds.json` regenerated and `DesignTokenContrastTest` green.
- [x] `composer check` green; vitest green on every touched page/component.

## Coverage delta

Measured on this worktree against `epic/mobile-ux-port` @ `7a8d54c9`, same run configuration, each
run uncontended (see the note below on why that matters):

| | before | after |
|---|---|---|
| statements | 97.37% | **97.52%** |
| branches | 90.71% | **90.73%** |
| functions | 97.03% | **97.39%** |
| lines | 97.67% | **97.81%** |

Up on all four. 1803 → 1811 frontend tests, 206 files both sides. The two rewritten components
land at 96.4% (`HrZonesDisclosure`) and 92% (`TrainingPreferencesCard`); collapsing ten bound
inputs to five deleted more untested branching than the rewrite added.

## Verification notes

**Gate**: `composer check` green **end to end in a single run**, exit 0 — pint · phpstan (0
errors) · rector (0 errors) · pest `--parallel --no-tia` 3636 tests / 10782 assertions · tsc ·
eslint · prettier · vitest 1811/1811 · `check:palette` (434 files, zero off-token) ·
`check:chunks` · doc-citation and `{@see}` guards. `npm run build` + `check:chunks` green
separately too; **no entry-chunk budget re-baselined** (Login 140.6 / Home 205.4 / Runs/Show 206.5
/ Profile 191.2 kB gz, all comfortably under).

**Both grounds** are covered the way this repo covers them: `grounds.json` is regenerated and
`DesignTokenContrastTest` scores every registered panel/text pair against **both** grounds and
fails closed. It is green (14/14).

**A contention warning for the other wave-3 slices.** Three Sail stacks on one machine will make
vitest fail spuriously. Two concurrent `test:coverage` runs produced **44 failures** across files
this slice never touches (`UserAvatarLink`, `Devtools`, `SessionsDial`, `Race`, `Trends`…), all
5000ms test timeouts, and one `composer check` run died on rector's 120s child-process timeout
while listing files no branch had modified. Every one of them passed on a quiet re-run. Re-run
before believing a red, and do not run two coverage passes at once.

**Not done: a real browser pass.** `PP1` and `PS1` both drove Chromium; this slice did not. The
900px reflows and the two grounds are verified at the class-string and token-contrast level only.
Worth one sweep before the epic merges.

## Open questions

0. **`epic/mobile-ux-port` was already red at `7a8d54c9`.** `DesignTokenContrastTest` failed on an
   orphaned `foreground/0.06` registration in `grounds.json` that nothing in `resources/js` paints.
   Confirmed against the base checkout, not just this worktree, so every concurrent wave-3 slice
   would have hit it. Fixed here in its own commit; if a sibling slice fixes it too, the two
   conflict trivially on one deleted block.

1. **The prototype's AI-replan control has no app equivalent.** `TrainingPreferencesCard`'s save
   button becomes an inert `AiReplanPill` under `aiReplanState === 'cooldown'`, and
   `onTriggerAiReplan` is the screen's only prop callback (`reference.md` §13). The app has no
   AI-replan feature at all — `/settings/training-preferences` is a plain PATCH. Building one is a
   backend feature, not a screen port, so the button ships as the real save. Left recorded, not
   invented.
2. **Per-screen bottom padding is still unimplemented**, matching `PS8`'s precedent and `PP1`'s
   open question 2. `reference.md` §1.1 gives Settings `pb-7` → `min-[900px]:pb-22`; `AppShell`
   supplies the `pb-7` for every pushed screen and has no per-screen map for the wide step. Adding
   the step on the page's own container would stack on top of `AppShell`'s, not replace it.
3. **Three prototype details were deliberately not copied**, each because copying would remove
   something true. (a) The "where it goes" scope note ("Bot replies and system alerts still come
   through") stays: the prototype has no maintainer-alert channel to be honest about, and dropping
   the line would make the mute toggles overclaim. (b) "Reset to default" stays hidden while the
   source *is* default, where the prototype shows it always — a control that provably does nothing
   is a false affordance. (c) Zone names keep the app's vocabulary (`Z2 · Easy`, `Z3 · Aerobic`)
   rather than the prototype's (`aerobic`, `tempo`); those names are domain data shared with
   time-in-zone surfaces, not screen styling.
4. **`SettingsRow`'s `description` prop and its `danger` tone now have one call site each**
   (the notification rows, and nothing respectively) after the legal and account sections stopped
   using them. `tone="danger"` now has **no call site and no test** — verified by grep across
   `resources/js`. Left for `W2`'s dead-code sweep, which P4 exists for, rather than widening this
   slice into a shared primitive two sibling slices may also be editing.
