# PS1 — Login

Login to prototype parity, per `plan/parity/reference.md`'s Login section.

## Goal

Adopt `LoginScreen.tsx`'s section list, order and treatment: the sky-to-leaf hero with its horizon
glow, the 440px auth card overlapping it, the hairline why-lists that become cards above 900px, the
kartu teaser row, a data & AI use disclosure, and the mono footer.

Login is one of the three screens that draws **no** `FaceIcon` (P10 — the others are Trends and
Settings). `PP2` removed the mascot from this page; nothing here adds one back.

## Files touched

| file | what |
|---|---|
| `resources/js/pages/Auth/Login.tsx` | the rebuild (519 lines reworked) |
| `resources/js/pages/Auth/Login.test.tsx` | +73 lines |
| `resources/css/app.css` | −65 lines: the `route-draw` keyframes and `.route-echo-path` rules that animated the old hero's route trace, dead once the prototype's hero replaced it |
| `resources/brand/grounds.json` | one panel registration |

## Blockers

None. `PP1` (shell, responsive model), `PP2` (mascot removal) and `PP3` (cuts) all landed first.

## Acceptance criteria

- [x] Section list, order and treatment match the prototype's Login screen.
- [x] Responsive model is the prototype's own — a single `min-[900px]` step, 760px column, and the
      auth card capped at **440px** rather than 760 (`Login.tsx:210`).
- [x] Feature list stacks below 900px and becomes `grid-cols-3` above it (`:117`).
- [x] CTA row becomes `inline-grid grid-cols-2` above 900px (`:126`).
- [x] Why-lists go from hairline-divided rows to bordered cards above 900px (`:272`, `:285`).
- [x] No `FaceIcon` anywhere on this page.
- [x] Lowercase copy treatment retained (scoped to Login by the `S1` copywriter ruling); small mono
      uppercase labels stay uppercase.
- [ ] **`PP1`'s deferred reflow #2 — the headline size step — is NOT done.** See Open questions.
- [ ] Login chunk reported against the 160 kB gz budget (R6).

## Coverage delta

_To be recorded from `npm run test:coverage`._

## Verification notes

**R6 is this slice's specific risk.** `bareLayout` is enforced framer-motion-free and capped at
160 kB gz, and `Auth/Login.tsx` is one of the four paths hardcoded in
`scripts/check-entry-chunks.mjs`'s `ROUTE_BUDGETS_KB`.

Two consequences shaped the implementation:

- The data & AI use disclosure is **hand-rolled rather than `ui/collapsible`**. That primitive is
  Base UI backed, and one Base UI portal in `BareShell`'s graph blows the budget.
- The removed `app.css` animation was already deliberately plain CSS rather than `lib/motion.ts`,
  for the same reason. It went because the prototype's hero replaced what it animated, not because
  the constraint changed.

Gate is `./vendor/bin/sail composer check` (single command since `C1`; it now runs exactly what CI
runs), plus `npm run test:coverage` and `npm run build && npm run check:chunks`.

## Open questions

1. **`PP1`'s deferred reflow #2 is unimplemented.** `PP1`'s slice doc assigned `PS1` the Login
   headline's `text-[34px]` → `text-[46px]` step at 900px, on the grounds that `PageHero` has no
   responsive size step and adding one is a type-system change rather than a layout change.

   This slice sidestepped `PageHero` entirely — commit `22125001` has the hero compose its own
   eyebrow and `<h1>`, because `PageHero`'s `h1` carries no font-weight class and rendered the title
   at 400 against the prototype's 600. That makes the size step **easier**, not harder: the `h1` at
   `Login.tsx:176` is now bespoke, so it can take a responsive step without touching the shared
   component or the token ladder. It currently reads flat `text-display-sm`.

   Land the step, then tick the box above and mark reflow #2 resolved in
   `plan/parity/slices/PP1-shell-nav.md` so wave 3 does not look for it twice.

2. Per-screen bottom padding (`PP1` §1.1's `pb` column) is still owned by the `PS` slices generally;
   confirm Login's against `reference.md` while in here.
