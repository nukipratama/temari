# V0 — Visual parity audit

**Wave** 3 (blocking, inserted before `W1`) · **Slot** main checkout · **Blockers** none (all of wave 2b merged)

## Goal

Reconcile the shipped wave-2b screens (`S1`-`S12`) against the frozen prototype's own visual
spec, now that decision 5 is amended: the prototype is source of truth for UI/UX, not just a
loose reference a codemod sweep + independent redesign was allowed to drift from. For every
divergence found: if it traces to an already-logged decision or `ledger.md` verdict, it stays
as-is (documented, not re-litigated); if it's unintentional drift, it gets fixed to match the
prototype; if it's a genuine new conflict between "match the prototype" and a prior grilling
decision, it goes back to the user before anything is resolved.

The prototype ships its own review harness for exactly this comparison: `Rack`
(`resources/brand/prototype/src/components/rack/Rack.tsx`) renders every screen in three
side-by-side `PhoneFrame`s (light/dark/system) at a switchable viewport
(`components/rack/viewports.ts`), reachable by clicking through the `PAGES` nav in
`src/App.tsx`. No screenshot rig needs to be invented — it needs to be driven.

## Files touched

TBD — driven by what the audit finds. Likely spans most of `resources/js/pages/` and
`resources/js/components/` touched by `S1`-`S12`, plus possibly `resources/brand/grounds.json`
and `docs/features/*.md` citations if any fix moves markup around.

## Blockers

None — all of `S1`-`S12` merged. Blocks `W1` onward (see `plan/README.md` §2/§3).

## Acceptance criteria

- [ ] Every one of the 12 ported screens screenshotted from the prototype's own `Rack` harness
      (both grounds at minimum; system optional) and from the shipped app at matching viewports
      and grounds.
- [ ] Every material divergence classified: matches a logged decision/ledger verdict (kept,
      cited) / unintentional drift (fixed) / genuine new conflict (escalated to the user via
      `AskUserQuestion` before resolving).
- [ ] No prototype file edited (still frozen per decision 19).
- [ ] `browser-review` run against the shipped app **after** any fix and **after** `npm run build`
      to confirm the fix landed and introduced no new overflow/regression.
- [ ] `plan/README.md` §5 amendment log and this slice doc's "What actually landed" filled in
      before `W1` unblocks.

## Coverage delta

n/a unless fixes touch component logic (unlikely — expect markup/token/spacing corrections).

## Verification notes

TBD.

## Open questions

None yet — this doc is a stub until the audit sweep runs.
