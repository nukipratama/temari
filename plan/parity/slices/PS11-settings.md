# PS11 — Settings

**Program** prototype parity · **Slot** 3 (worktree, concurrent with two sibling slices) ·
**Blockers** `PP0`-`PP3`, `PP1` (shell), `C1` · **Status** in-progress

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

- [ ] Section list and order match the prototype: eyebrow → h1 → appearance → notifications →
      running (**training preferences first, then the zones disclosure**) → data use → the fine
      print → account.
- [ ] No `FaceIcon` anywhere on the screen (P10).
- [ ] Reflow #10 carried: `min-[900px]:grid-cols-4` on the max/resting HR grid.
- [ ] Reflow #11 carried: both the container and the button halves.
- [ ] Training preferences render as an always-open card (the prototype's `TrainingPreferencesCard`),
      not a disclosure, using the three shared preference controls.
- [ ] The zones disclosure is **closed by default**, as the prototype has it.
- [ ] Five zone-bound inputs, not ten; saving still sends valid `{lo, hi}` pairs.
- [ ] Both grounds check out; `grounds.json` regenerated and `DesignTokenContrastTest` green.
- [ ] `composer check` green; vitest green on every touched page/component.

## Coverage delta

_To be filled after the gate runs._

## Verification notes

_To be filled after the gate runs._

## Open questions

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
