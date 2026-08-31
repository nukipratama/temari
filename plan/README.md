# Temari rebrand — program orchestrator

Porting the mobile-UX shadcn/ui prototype at [resources/brand/prototype/](../resources/brand/prototype/)
into the shipped app.

This file is the **living tracker**. It is the only place a decision may be amended and the only
place slice status is recorded. Everything else in `plan/` hangs off it.

- **Epic branch**: `epic/mobile-ux-port`, a **nested epic branched off `epic/rebrand-temari`**
  (amended from decision 15 — see §5). Mirrors the precedent already set by `epic/experience-rebuild`
  (#633): every slice PR from `F1` onward targets `epic/mobile-ux-port`; when the whole program is
  done, one PR merges it into `epic/rebrand-temari`, which stays the record for #593 to merge into
  `main` (which auto-deploys to prod) on its own separate, deliberate timeline. `P0`/`L0` were
  committed directly to `epic/rebrand-temari` before this was reconsidered — grandfathered in, not
  moved; `epic/mobile-ux-port` was branched from that point forward.
- **Prototype**: frozen, read-only spec. SHA below.
- **Scale**: 415 files in `resources/js` (148 `.tsx`, 63 `.ts`, 204 co-located tests).
  30 slices, ~45-50 PRs. Committed scope is wave 0 + wave 1, then re-assess.

---

## 1. Decisions

Nineteen, settled in the planning session. Later slices must not silently re-open these. To change
one, add a row to the amendments log (§5) and edit the entry here in the same commit.

| # | Decision |
|---|---|
| 1 | **Scope**: a wave-0 reconciliation ledger rules keep / restyle / cut / defer on every omitted shipped feature. Cutting is permitted. |
| 2 | **Backend**: all four new capabilities in scope — training preferences, Compliance v2, structured session segments, plan narration. |
| 3 | **Dependencies**: adopt `@base-ui/react`, `shadcn`, `lucide-react`, `clsx`, plus `tw-animate-css` (added in `F1` — see §5). |
| 4 | **Tokens**: the prototype's semantic layer becomes canonical and is the preferred vocabulary in components. The app's named palette survives beneath it. |
| 5 | ~~**Transition**: one codemod-driven mechanical sweep first, then real redesign per screen. Visual parity is explicitly *not* promised.~~ **Amended, see §5**: visual/UX parity with the frozen prototype is now required, reconciled by `V0`. |
| 6 | **Theming**: both grounds authored, both shipped. Dark is the default; light and system reachable via a Settings toggle. |
| 7 | **Tests**: test-as-you-port. The 1:1 gate and the 95% coverage threshold stay untouched. |
| 8 | **Planning docs**: a tracked `plan/` directory at repo root, outside `docs/`. |
| 9 | **Slicing**: wave 0 ledger → wave 1 foundation (serialized) → waves 2a/2b in parallel worktrees → wave 3 cleanup. |
| 10 | **Verification**: engineer / devops / copywriter as subagent rubrics per slice; PM / designer as human review per wave. |
| 11 | **Plan narration is voice-only.** Rules still own every number. No superseding ADR, only an amendment. |
| 12 | **Token pipeline**: extend `build-tokens.mjs`; add `darkGrounds()` beside `paperGrounds()`. |
| 13 | **Art**: a dedicated wave-1 slice re-cuts mascot, accessories, Kartu chrome, share cards and the Strava mark for two grounds. |
| 14 | **Nav / IA**: deferred to the ledger slice (`L0`), resolved in [ia.md](ia.md). |
| 15 | ~~**Branching**: all slice PRs target `epic/rebrand-temari`.~~ **Amended, see §5**: `F1` onward targets the nested `epic/mobile-ux-port`. No merge to `main` until the whole chain (this program → `epic/rebrand-temari` → `main`) is done. |
| 16 | **Icons**: full swap to `lucide-react`; delete `iconBundle.ts` and `build-icon-bundle.mjs`. |
| 17 | **Coverage CI**: match any `epic/*` branch in the workflow's `push.branches` (widened from a literal `epic/rebrand-temari` — see §5). |
| 18 | **Commitment**: wave 0 + wave 1 firm; re-assess before the wave-2 fan-out. |
| 19 | **Prototype**: frozen at a tagged SHA after wave 0, read-only thereafter, deleted in wave 3. |

---

## 2. Wave map

```
wave 0  ──  P0 ─┐
                ├─→  (blocking; main checkout, no worktrees)
            L0 ─┘

wave 1  ──  F1 → F2 → F3 → F4          (serialized; critical path)
                  │      └──→ F5 ┐     (worktree, after F2)
                  └──────────→ F6 ┘    (worktree, after F2)
                              F7       (worktree, after B2 + B3)

            ★ CHECKPOINT — re-assess scope with real velocity

wave 2a ──  B3 → B2 → B1 → B4          (ONE worktree slot, strictly sequential)

wave 2b ──  S1 S2 S3 S4 S5 S6 S7 S8 S9 S10 S11 S12
                                        (3 parallel worktree slots; per-slice blockers below)

wave 3  ──  V0 → W1 → W2 → W3 → W4 → W5   (main checkout)
```

`V0` (added 2026-08-31, see §5) audits every wave-2b screen against the prototype's own Rack
review harness and reconciles drift before any further cleanup work — see decision 5's amendment.

`F2` is the single serialization point of the whole program. Nothing else in wave 1 runs
concurrently with it — see [R1](#r1).

---

## 3. Progress

Status vocabulary: `todo` · `in-progress` · `in-review` · `merged` · `blocked` · `cut`.
Coverage delta is the frontend Vitest line-% change reported by `npm run test:coverage`, recorded by
the slice itself (see [R3](#r3)); `n/a` for backend-only or docs-only slices.

| id | name | wave | doc | status | PR | slot | cov Δ | notes |
|---|---|---|---|---|---|---|---|---|
| P0 | Program scaffold | 0 | [00](slices/00-P0-program-scaffold.md) | merged | — | main | n/a | committed directly (7c056d61) |
| L0 | Reconciliation ledger + IA | 0 | [01](slices/01-L0-reconciliation-ledger.md) | merged | — | main | n/a | committed directly (fe927c1e) |
| F1 | Dependency adoption | 1 | [02](slices/02-F1-dependency-adoption.md) | merged | [#654](https://github.com/nukipratama/temari/pull/654) | main | n/a | squashed as 997b6ba8 |
| F2 | Two-ground tokens | 1 | [03](slices/03-F2-two-ground-tokens.md) | merged | [#655](https://github.com/nukipratama/temari/pull/655) | main | 94.17→95.06% fn | squashed as 5e5a3d6f |
| F3 | Mechanical sweep | 1 | [04](slices/04-F3-mechanical-sweep.md) | merged | [#656](https://github.com/nukipratama/temari/pull/656) | main | 95.06→95.04% fn | squashed as 14816771 |
| F4 | Shell + nav | 1 | [05](slices/05-F4-shell-and-nav.md) | merged | [#657](https://github.com/nukipratama/temari/pull/657) | main | 95.04→95.11% fn | squashed as ae8a92d7 |
| F5 | Two-ground art | 1 | [06](slices/06-F5-two-ground-art.md) | merged | [#659](https://github.com/nukipratama/temari/pull/659) | wt | 95.05% fn (no change) | squashed as 6aa3f9fc |
| F6 | Charts, two grounds | 1 | [07](slices/07-F6-charts.md) | merged | [#658](https://github.com/nukipratama/temari/pull/658) | wt | 95.11→95.05% fn | squashed as ee4f7881 |
| F7 | Demo data + fixtures | 1 | [08](slices/08-F7-demo-data-and-fixtures.md) | todo | — | wt | | after B2/B3 |
| B3 | Structured session segments | 2a | [09](slices/09-B3-session-segments.md) | merged | [#660](https://github.com/nukipratama/temari/pull/660) | wt-be | n/a | freezes `WeekPlanDay`; squashed as ab8f33aa |
| B2 | Compliance v2 | 2a | [10](slices/10-B2-compliance-v2.md) | merged | [#661](https://github.com/nukipratama/temari/pull/661) | wt-be | n/a | persisted score, daily scoring pass; squashed as 5ec8d3e6 |
| B1 | Training preferences | 2a | [11](slices/11-B1-training-preferences.md) | merged | [#662](https://github.com/nukipratama/temari/pull/662) | wt-be | n/a | preference-over-behavior override, 2-day template; squashed as c9338293 |
| B4 | Plan narration | 2a | [12](slices/12-B4-plan-narration.md) | merged | [#663](https://github.com/nukipratama/temari/pull/663) | wt-be | n/a | voice-only; regenerate rate-limit; squashed as afa210ab |
| S1 | Login | 2b | [13](slices/13-S1-login.md) | merged | [#664](https://github.com/nukipratama/temari/pull/664) | wt | 95.37% fn (no change) | 160 kB gz budget; Login at 138.8 kB gz; squashed as 8bdfd11e |
| S2 | Onboarding | 2b | [14](slices/14-S2-onboarding.md) | merged | [#672](https://github.com/nukipratama/temari/pull/672) | wt | 95.62→95.68% stmts | preferences restructured into a per-question sub-stepper (StepProgress/IconChoiceCard/SessionsDial/DayPicker), no backend fork; squashed as 8c8c804f |
| S3 | Today | 2b | [15](slices/15-S3-today.md) | merged | [#669](https://github.com/nukipratama/temari/pull/669) | wt | 95.56→95.63% stmts, 95.40→95.44% fn | streak reframed off-badge, compliance-v2 colors surfaced; squashed as 1d7a95b9 |
| S4 | Plan | 2b | [16](slices/16-S4-plan.md) | merged | [#668](https://github.com/nukipratama/temari/pull/668) | wt | 95.56→95.58% stmt | streak panel removed (redesign decision), segment breakdown added; squashed as f812dabc |
| S5 | RaceGoal | 2b | [17](slices/17-S5-race-goal.md) | merged | [#670](https://github.com/nukipratama/temari/pull/670) | wt | 95.65% stmts, 89.39% branches, 95.48% fn, 96% lines | gauge + client-side goal-time warnings, ember tokens; squashed as ce2d97c2 |
| S6 | Trends | 2b | [18](slices/18-S6-trends.md) | merged | [#667](https://github.com/nukipratama/temari/pull/667) | wt | 95.6→95.61% stmts | page shell + RangeToggle + NarrationHeadline restyle, charts kept from F6; streak badge-board entry per §5 amendment; squashed as adea9b6d |
| S7 | History | 2b | [19](slices/19-S7-history.md) | merged | [#675](https://github.com/nukipratama/temari/pull/675) | wt | 95.56% stmts, 89.39% branches, 95.46% fn, 95.97% lines | feed filters + journey strip cut per ledger (also orphaned ActiveFilterChips/ResumeFilterChip/useLastFilter/Calendar mood filter); RecapCard unifies weekly+monthly recap chrome, compact RunListRow, load-older-weeks reveal; squashed as 74a9cb9b |
| S8 | ActivityDetail | 2b | [20](slices/20-S8-activity-detail.md) | merged | [#673](https://github.com/nukipratama/temari/pull/673) | wt | 95.7% stmts (no change) | hero headline/supporting/secondary tier, Vitals card wrap; Leaflet/lenses/effort kept mechanical per L0; charts kept from F6; squashed as 0d0c5576 |
| S9 | Inbox | 2b | [21](slices/21-S9-inbox.md) | merged | [#665](https://github.com/nukipratama/temari/pull/665) | wt | 95.92→95.95% lines | bucketed sections + time toggle, new scope; squashed as cd0ecd08 |
| S10 | Profile | 2b | [22](slices/22-S10-profile.md) | merged | [#671](https://github.com/nukipratama/temari/pull/671) | wt | 95.61→95.62% stmts | persona mix restyle, milestone-display ledger ruling satisfied via SeasonStreakPanel, Accessories tab cut; squashed as 396b9d13 |
| S11 | Settings | 2b | [23](slices/23-S11-settings.md) | merged | [#666](https://github.com/nukipratama/temari/pull/666) | wt | 95.37→95.40% fn | appearance toggle UI; squashed as cc3f5f5a |
| S12 | Undrawn survivors | 2b | [24](slices/24-S12-undrawn-survivors.md) | merged | [#674](https://github.com/nukipratama/temari/pull/674) | wt (slot 3) | 95.7% stmts, 89.5% branches, 95.55% fn, 96.05% lines | Devtools/Design.tsx investigated + confirmed sound against post-F2 tokens (real-browser verified both grounds), not actually broken; Devtools.tsx had a real ground-reactivity bug (raw cream tokens) fixed; squashed as 0c360f14 |
| V0 | Visual parity audit | 3 | [30](slices/30-V0-visual-parity-audit.md) | in-progress | — | main | | blocks W1-W5; prototype is now source of truth, see §5 2026-08-31 |
| W1 | IA cutover | 3 | [25](slices/25-W1-ia-cutover.md) | todo | — | main | | blocked on `V0` |
| W2 | Dead-code sweep | 3 | [26](slices/26-W2-dead-code-sweep.md) | todo | — | main | | |
| W3 | Coverage reconciliation | 3 | [27](slices/27-W3-coverage.md) | todo | — | main | | do not cut |
| W4 | Docs | 3 | [28](slices/28-W4-docs.md) | todo | — | main | n/a | |
| W5 | Merge readiness | 3 | [29](slices/29-W5-merge-readiness.md) | todo | — | main | n/a | deletes the prototype |

---

## 4. Frozen prototype SHA

Per decision 19, `resources/brand/prototype/` is a **read-only spec** from this point. No edits. A
spec change goes into the owning slice's doc, never into the prototype.

| | |
|---|---|
| SHA | `6f7d401863e5ed5ada328916ce8fb7f75c82c563` |
| Tag | `prototype-frozen` |
| Commit | `feat(prototype): mobile-ux shadcn/ui prototype (#652)`, 2026-08-28 |

Reading it back at any later point:

```bash
git show prototype-frozen:resources/brand/prototype/src/index.css
```

---

## 5. Amendments

Every deviation from §1 lands here, dated, with the reason. Empty is the healthy state.

| date | decision | change | why |
|---|---|---|---|
| 2026-08-28 | 15 | Slice PRs (`F1` onward) target a new nested branch `epic/mobile-ux-port`, branched off `epic/rebrand-temari`, instead of `epic/rebrand-temari` directly. | `epic/rebrand-temari` already carries open PR #593 ("Temari v1"), which explicitly states further work should land on a nested epic branch rather than reopening that diff — a pattern already proven once by `epic/experience-rebuild` (#633). This was discovered only after `P0`/`L0` had already landed directly on `epic/rebrand-temari`; those two are grandfathered rather than rewritten, since `epic/rebrand-temari` was already pushed and rewriting a branch backing an open PR is avoidable risk for no benefit. |
| 2026-08-28 | 3 | `tw-animate-css` added to `dependencies` alongside the four originally named packages. | `F1` confirmed by reading the frozen prototype: `src/index.css` imports it directly and `toggle-group.tsx` (one of the six primitives `F3` adopts) uses the `data-[state=...]` animate utilities it provides. Not speculative — demonstrably required by code this program ports; omitting it would break `F3`'s `toggle-group` swap. |
| 2026-08-28 | 17 | `push.branches` widened from the literal `epic/rebrand-temari` to the glob `epic/*`. | The literal name missed `epic/mobile-ux-port` — the nested branch this program actually pushes to, created by the same amendment that added decision 15's row above. A glob covers every current and future single-segment `epic/*` branch without needing to hand-maintain a list. |
| 2026-08-30 | new | **Streak feature redesign**, decided across `S3`/`S4`/`S6`, all three running as parallel wave-2b worktrees when this was settled. The week-grained lifetime streak (`WeeklySnapshot::consecutiveWeekStreak()`, wrapped by `SeasonStreakSummaryBuilder::streakPayload()`) becomes the only thing called "streak" — it moves to a Trends badge-board entry (`S6`), using a non-flame icon. The day-grained current-week count (`CurrentWeekPlanBuilder::streakDays()`) stays on Today (`S3`), reframed as a plain progress readout — not a streak, no flame badge. Plan's `StreakPanel` (`S4`) is removed entirely, no replacement on that page. | The frozen prototype doesn't draw a streak concept on its Today or Plan mockups at all (only a badge-board entry on Trends and a notification kind on Inbox) — porting the shipped app's two independently-computed streak metrics onto the new design as-is would keep an inconsistency (different units both called "streak") the redesign was a chance to fix, and would create a real icon collision once the prototype's own flame-for-tempo-session day-glyph convention lands on the same pages. Grilled directly with the user mid-wave; not part of the original 19 because it surfaced only once S3/S4/S6 were about to start, not during initial planning. |
| 2026-08-31 | 5 | **Decision 5 reversed: visual/UX parity with the frozen prototype now *is* required**, not merely un-promised. A new blocking slice `V0` (visual parity audit, inserted before wave 3) screenshots every wave-2b screen against the prototype's own built-in Rack review harness (both grounds, matching viewports) and reconciles every divergence — either it traces to an already-logged decision/ledger verdict (kept as-is) or it's unintentional drift that gets fixed to match the prototype. Any newly-found case that isn't a routine implementation call goes back to the user before it's resolved, same as every other program-level fork. | User review of the shipped wave-2b screens found the actual layout diverging substantially from the prototype's handcrafted mockups. Decision 5 was written to license a mechanical-sweep-then-redesign transition without promising pixel parity along the way — it was never meant to license silent, uncontrolled drift once each screen slice's "real redesign" pass landed. The user's instruction: pause further wave-3 work, audit against the prototype as source of truth, ask before resolving genuine conflicts with prior grilling decisions. |
| 2026-08-31 | new | **`V0` fork 1 — headline voice.** Every screen's hero "Temari voice" headline (the italic quote-style line the prototype renders lowercase+italic, e.g. "the weeks ahead.") gets restyled app-wide to the existing `font-display italic text-quote-lg` quote treatment, matching the prototype. Page titles, section labels and UI chrome stay Title Case, unchanged — only this one headline slot moves. | The copywriter ruling (`S1`) scoped all-lowercase *copy* to Login only, but the italic/lowercase treatment on every other screen's hero line is a distinct, pre-existing "Temari voice" token role the port had stopped applying outside Login. `V0`'s comparison sweep found every one of the 11 screens diverging on this one axis; put to the user, resolved app-wide rather than re-litigated per screen. |
| 2026-08-31 | new | **`V0` fork 2 — header brand mark.** The shell header's persistent brand mark switches from the mascot-face icon + Title-Case "Temari" wordmark (as shipped) to the prototype's abstract ring/arc mark + lowercase "temari" wordmark. The mascot face is unaffected everywhere else (hero avatars, empty states, Kartu, etc.) — this is the persistent shell chrome only. | `V0`'s sweep found the shipped header consistently using the mascot mark where the frozen prototype always uses a distinct abstract mark. Put to the user; chose prototype fidelity over the mascot-forward header that had shipped without an explicit ruling either way. |
| 2026-08-31 | new | **`V0` fork 3 — Plan's phase bar and week timeline.** `S4` explicitly deferred the prototype's season-wide phase-progress bar (Base/Build/Peak/Taper) and week-by-week expandable timeline as "a real fork, not a routine call," since `PlanController::index()` only serves a 3-history/4-lookahead window, not the full season. A new backend aggregate plus the frontend `SeasonHeaderCard`/`SeasonTimeline`/`WeekVolumeChart` port is now in scope, as a follow-up slice. | `S4`'s own slice doc left this as an open question pending a human call on backend cost. `V0` surfaced it again during the parity audit; put to the user, who chose to build it now rather than leave Plan without it. |
| 2026-08-31 | new | **`V0` fork 4 — Today's supporting-detail disclosure stays open by default.** No change from what `S3` shipped: the "this week" stats block (weekly tiles, Vibe/Readiness/Break, last-run detail, training-load card) stays wrapped in a `Collapsible` set `defaultOpen`, not collapsed to match the prototype's denser default. | `S3`'s own reasoning (a full pixel rebuild of six components into the prototype's condensed shape was out of proportion to the value, and defaulting open loses no existing visibility) reconfirmed by the user during `V0`'s audit — recorded so this isn't re-litigated by a future slice. |
| 2026-08-31 | new | **`V0` fork 5 — desktop `TopNav` gets a real redesign, despite no prototype spec.** `TopNav` (`≥1024px`, the `lg` breakpoint) is redesigned onto the same frosted-glass/floating-pill/ground-reactive language `F4` already gave `MobileBottomNav`/`MobileTopBar`, even though the prototype itself never specs desktop nav — its own `Rack`/`PhoneFrame`/`AppBottomNav` review harness has no responsive branch at all; "desktop"/"wide" viewport previews just resize the same floating mobile pill nav into a wider browser-chrome frame. `TopNav` is otherwise functionally correct (4 tabs, right routes, lucide icons, per `F4`) — this is a pure visual-coherence follow-up, owned by whichever slice picks it up. | User noticed desktop doesn't carry a prototype-matched nav design. Confirmed via `F4`'s own slice doc ("`TopNav` needed zero code changes") and the prototype's `AppBottomNav.tsx` source (no breakpoint logic) that this isn't a missed port step, just genuinely unspecified scope — F4 had nothing to port TopNav onto. Put to the user anyway since leaving it stuck in its pre-port visual language now reads as inconsistent against every other post-port surface; chose to extrapolate the new mobile language upward rather than leave it untouched. |

---

## 6. The rest of the tree

- [ledger.md](ledger.md) — keep / restyle / cut / defer on every shipped feature the prototype omits.
- [ia.md](ia.md) — routes, tabs, and the literal diff spec against `resources/js/lib/nav.ts`.
- [slices/](slices/) — 30 slice docs, numbered in dependency order.
- [verification/](verification/) — three subagent rubrics (engineer, devops, copywriter) run per
  slice; two human templates (PM, designer) worked per wave.
- [codemods/](codemods/) — `F3`'s generated passes. Per [R2](#r2) these **are** the review artifact.

---

## 7. Risks

Carried from the planning session. Each is owned by the slice named in its mitigation.

<a id="r1"></a>**R1 — the two-ground contrast system's failure mode is a *green* CI.**
`designTokens()` in `DesignTokenContrastTest`, `readColorTokens()` in `grounds.mjs`, and
`declaredTokenValues()` in `DesignTokenMirrorsTest` all parse `--color-x: #rrggbb` by regex only. The
prototype's dark layer is `color-mix(in oklab, var(--cream) 72%, transparent)` and `var()`
indirection; authored that way, all three pass while scoring nothing.
*Mitigation (`F2`):* first commit is a pure derivation-and-test change with the palette
byte-identical — add `darkGrounds()`, `inkOnDark()` and a parallel `PAIRS_DARK`, prove both grounds
score against existing values, and add a test asserting `@theme static` contains only literal hex (no
`var(`, no `color-mix(`). Only the second commit introduces new values.

<a id="r2"></a>**R2 — the sweep is unreviewable, and "no visual parity promised" removes the
reviewer's oracle.**
*Mitigation (`F3`):* codemods land in the PR under `codemods/` and are the review artifact; one
commit per pass, each 100% script-generated with zero hand edits; a single `manual-fixups` commit is
the only thing read line by line; `browser-review` before/after shots in the PR body to make the
accepted shifts enumerable rather than to claim parity.

<a id="r3"></a>**R3 — frontend coverage debt accrues invisibly.** CI runs `npm run test` (no
coverage) on `pull_request` and `test:coverage` only on push, so without decision 17 the 95% JS gate
would fire exactly once, at the epic→main merge, across ~190 components.
*Mitigation:* decision 17, plus `npm run test:coverage` as every frontend slice's local definition of
done with the delta recorded in §3. `W3` stays budgeted regardless.

<a id="r4"></a>**R4 — `grounds.json` collides across every parallel worktree.**
*Mitigation (`F2`):* make it fully generated by a new `resources/brand/build-grounds.mjs` driven by
the existing `enumerateBackgrounds()` / `panelSiteDrift()` scan, so a conflict is resolved by
re-running a script rather than hand-merging 635 lines of JSON. "Regenerate `grounds.json`" becomes
the last step of every slice checklist.

<a id="r5"></a>**R5 — `demo:seed` won't produce the new shapes**, so screen slices get designed
against empty states.
*Mitigation:* seeder extension is an acceptance criterion *inside* `B1`-`B4`, not a follow-up; `F7`
adds the shared fixtures module.

<a id="r6"></a>**R6 — Login's first-paint budget vs. the shadcn stack.** `bareLayout` is enforced
framer-motion-free and capped at 160 kB gz. `button`, `card` and `badge` are Base-UI-free; `toggle`,
`toggle-group` and `collapsible` are not, and one Base UI portal in `BareShell`'s graph blows the
budget.
*Mitigation:* the `base-ui` chunk group in `F1`; `S1` restricted to the Base-UI-free primitives;
`npm run build && npm run check:chunks` as the definition of done for both.

<a id="r7"></a>**R7 — the backend slices serialize** on `dayPayload()`, `inertia.ts`,
`planned_sessions` migrations and the unconditional doc-citation guard.
*Mitigation:* one worktree slot, strict `B3 → B2 → B1 → B4`, `WeekPlanDay` frozen at the end of `B3`,
every exemption-array edit in a single dedicated first commit per slice.

<a id="r8"></a>**R8 — the prototype drifts as the spec** across a months-long program. Its
`lib/palette.ts` is already annotated "keep in sync by hand", and it carries decisions the app must
*not* inherit (the 3-way `[data-theme]` rack, `PhoneFrame`, `--radius-4xl` on cards).
*Mitigation:* decision 19 and §4.

<a id="r9"></a>**R9 — `.claude/skills/temari/SKILL.md` is already wrong** (documents a gold
`horizon #d9a53c` on "warm linen" while `app.css` ships Pewter `#ade047`), and nothing gates it, so
every agent-driven slice starts primed with a dead palette. `DesignTokenDocsTest`'s forbidden-name
loop covers only `CLAUDE.md`, `README.md` and `docs/design-tokens.md`.
*Mitigation:* fix in `F2`; add the skill file to that loop.

<a id="r10"></a>**R10 — the radius guard gets silently weakened.** `check-raw-palette.mjs` rule 3
must be re-authored in `F2` to accept the shadcn ladder, and that is exactly the edit where it can be
reduced to a no-op.
*Mitigation:* the re-authored rule is reviewed as its own commit, with a test asserting it still
rejects an off-scale value.

---

## 8. Coupling that will bite

Files edited by slices that otherwise look independent. Several fail on the *epic* rather than in the
colliding PR.

| file | who touches it | failure mode |
|---|---|---|
| [resources/brand/grounds.json](../resources/brand/grounds.json) | every screen slice | 635 lines keyed by exact file path; `DesignTokenContrastTest` fails closed both ways. Worst coupler in the program — see R4. |
| [resources/js/types/inertia.ts](../resources/js/types/inertia.ts) | written by `B1`-`B4`, read by `S3` `S4` `S5` `S7` `S11` | one file, nine slices |
| `resources/js/types/generated.ts` | `B2` (`PlannedSessionStatus`) and `B4` (`AnalysisType`) | regenerated by both |
| [DesignTokenMirrorsTest.php](../tests/Unit/Architecture/DesignTokenMirrorsTest.php) | any token/art/chart slice | `MIRROR_FILES` spans frontend *and* backend; a frontend-only chart tweak fails the **backend** structure group |
| [resources/js/lib/cn.ts](../resources/js/lib/cn.ts) | `F2`, `F3` | `extendTailwindMerge`'s `font-size` group lists 19 literal token names. Rename a `--text-*` token and class merging breaks **with no failing test** |
| [scripts/check-entry-chunks.mjs](../scripts/check-entry-chunks.mjs) | `F1`, `F4`, `S1`, `S3`, `S8`, `S10`, `W1` | `ROUTE_BUDGETS_KB` hardcodes four source paths; move or rename any and the guard fails "missing from the manifest" |
| [EveryClassHasATestTest.php](../tests/Unit/Architecture/EveryClassHasATestTest.php) | all four backend slices | exemption array + 30-import `use` block; pint and rector reshuffle it. Guaranteed 4-way conflict |
| [docs/features/plan-periodizer.md](../docs/features/plan-periodizer.md) | `B3`, `B4` | 24 `code_refs` and the doc-citation job is **unconditional**. The moment `B3` deletes `DistanceBandKm.php` it reddens **every open PR**. Land the doc edit in the same commit as the deletion |
| `resources/brand/build-accessories.mjs` | `F2` (transitively), `F5` | imports `COLOR` from `build-tokens.mjs`, so `F2` silently changes 25 accessory SVGs that `TemariProto.test.tsx` pins via the `@brand` alias |
| `resources/js/lib/iconBundle.ts` | every worktree, until `F3` | tracked *and* regenerated on `predev` |
| [resources/js/test/structure.test.ts](../resources/js/test/structure.test.ts) | several | the `EXEMPT` / `TS_EXEMPT` sets, a 6-line block |

---

## 9. Verification ladder

Per slice, before the PR opens — run the three subagent rubrics against the diff and resolve every
finding, then this ladder, stopping at the first failure:

```bash
./vendor/bin/sail pest --group=structure          # DB-free 1:1 gate, run first
./vendor/bin/sail bin pest --filter=<Name>        # the narrowest thing that can fail
./vendor/bin/sail npm run test:coverage           # frontend DoD — record the delta in §3
./vendor/bin/sail npm run build && npm run check:chunks
./vendor/bin/sail composer check                  # full gate, pre-push
```

Any slice touching tokens, grounds or artwork additionally regenerates `grounds.json` and runs
`php scripts/check-doc-citations.php` directly — a green `composer check` does **not** cover it.

Per wave — work [verification/product-manager.md](verification/product-manager.md) and
[verification/designer.md](verification/designer.md), and run a `browser-review` sweep **after**
`npm run build`; the review server serves `public/build`, not live source, so an unbuilt sweep
silently screenshots stale output. Check both grounds.

**Wave-1 exit criteria, before the checkpoint:**

1. `@theme static` contains literal hex only; the assertion test for this exists and passes.
2. `DesignTokenContrastTest` scores **both** grounds, and every `-ink` tier passes AA on the ground
   it is used on.
3. The app builds and the full existing test suite passes after the sweep.
4. `check:chunks` green with the shadcn stack in the graph; Login still under 160 kB gz.
5. `check:palette` rule 3 accepts the shadcn radius ladder and still rejects an off-scale value.
6. The appearance toggle switches grounds live, persists across reloads, and does not flash.
7. Mascot, accessories, Kartu and share cards render correctly on both grounds, client and server.
8. `demo:seed` produces a usable dataset on both grounds.
