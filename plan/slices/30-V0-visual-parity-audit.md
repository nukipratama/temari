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

## What actually landed

**The comparison sweep itself**: all 12 prototype screens (via the prototype's own `Rack` harness,
mobile viewport, all three theme frames) captured against the shipped app's 11 matching pages
(both grounds forced via the `temari-theme` localStorage key), then reviewed in 4 parallel
design-QA passes. Every finding was cross-checked against `ledger.md` and the actual slice docs
before being treated as real — several apparent "big divergences" (Profile's persona chart, Trends'
badge board, Today's Kartu panel, History's cut filters) turned out to already match explicit
ledger verdicts or the streak-redesign amendment, not drift.

**A root cause bigger than styling, found mid-audit: the demo account had zero `PlannedSession`,
zero `InboxNotification`, and zero `trend_read` `Analysis` rows.** This was distorting the audit
itself — Today's `WeekPlanWidget` and Plan's populated state are conditional on data that never
existed for the demo account, so what looked like structural drift on Today/Plan/Inbox/Trends was
partly just "nothing to show." Resolved by `F7` (see
[08-F7-demo-data-and-fixtures.md](08-F7-demo-data-and-fixtures.md)), dispatched as a companion
piece to this slice rather than folded into it.

**Five genuine forks surfaced, all put to the user, all recorded in
[plan/README.md](../README.md) §5** (not re-litigated here): headline voice styling app-wide
(fork 1, **implemented** — see below), header brand mark switching to the prototype's abstract
ring (fork 2), Plan's phase-bar and week timeline getting a real backend follow-up (fork 3),
Today's supporting-detail disclosure confirmed staying open by default as shipped (fork 4), and
desktop `TopNav` getting a redesign despite the prototype having no desktop nav spec at all
(fork 5). Implementation of forks 2/3/5 is tracked as follow-up work, not part of this slice's
own diff.

**First bug-fix batch** (dark-mode contrast + History truncation, confirmed via the sweep):
[Profile.tsx](../../resources/js/pages/Profile.tsx),
[variants.ts](../../resources/js/lib/variants.ts),
[pace.ts](../../resources/js/lib/pace.ts),
[RunListRow.tsx](../../resources/js/components/run/RunListRow.tsx).

### Fork 1 — headline voice (implemented, `slice/v0-headline-voice`)

Every ported page's hero headline (Onboarding, Today, Plan, Race, Trends, History, Runs/Show,
Inbox, Profile, Settings — Login stays on its own separate `S1` lowercase ruling) moved from the
"Page title" typography role (`font-serif text-display-lg text-foreground`) to the "Temari voice /
quote" role (`font-serif italic text-quote-lg`), matching the frozen prototype's own `<h1>` hero
headlines, and each page's own static authored copy was lowercased (dynamic content — `{firstName}`,
`detail.name` — left as-is).

**Investigated and resolved before implementing**: [docs/design-tokens.md](../../docs/design-tokens.md)'s
`.voice` component-utility class (`app.css:656`, `font-serif text-quote-lg italic text-foreground`)
and the `temari` skill's own typography table disagree on the quote role's text color
(`text-foreground` vs `text-text-2`). Checked against real precedent — the `.voice` class itself,
[NarrationHeadline.tsx](../../resources/js/components/trends/NarrationHeadline.tsx)'s title line,
[VerdictHero.tsx](../../resources/js/components/home/VerdictHero.tsx)'s default tone, and every
one of the prototype's own hero `<h1>` elements (`TodayScreen.tsx`, `TrendsScreen.tsx`,
`PlanScreen.tsx`, etc., all `text-foreground italic`) — all agree on `text-foreground` (`text-cream`
on a dark hero panel, matching `PageHero`'s existing `onSky` branch). The skill's table row is
stale; not corrected here since fixing skill docs is outside this slice's diff. Font-weight was
deliberately **not** copied from the prototype's `font-semibold` — the prototype loads
`Fraunces Variable` (any weight), but the shipped app loads fixed static Fraunces weights via
Google Fonts (`app.blade.php`) with no `600` italic face, so forcing `font-semibold` on italic
text would render an unloaded weight; the existing quote-voice precedents (`.voice`,
`NarrationHeadline`, `VerdictHero`) already omit it for the same reason.

**Extended `PageHero`** (`resources/js/components/ui/PageHero.tsx`) rather than composing
per-page: added a `'quote-lg'` step to its `size` prop (→ `text-quote-lg`), leaving its existing
`italic` prop and its already-correct `onSky ? text-cream : text-foreground` color logic
untouched — the quote treatment's color was already PageHero's default, so the only real gap
was the size scale. Race, Trends, Inbox, Profile, Settings, and both History sub-pages
(`Activities/Calendar.tsx`, `Activities/Feed.tsx`) now pass `size="quote-lg" italic`. Three pages
don't route through `PageHero` and were edited directly: `Plan.tsx`'s inline `<h1>`, `Runs/Show.tsx`'s
inline `<h1>` (on a dark hero panel, so kept `text-cream`; its `'Run'` fallback lowercased to
`'run'` as the page's own authored word — `detail.name` itself, and the unrelated `<Head title>`
document-title fallback, stay untouched as dynamic content / UI chrome respectively), and
`VerdictHero.tsx` (Today's headline was already italic serif with verdict-conditional tone
color — a deliberate `S3` design choice, left alone — only its size moved from `text-display-xs`
to `text-quote-lg`; its text is already-lowercase templated copy from `lib/verdict.ts`, no copy
change needed).

Both History sub-pages had a `not-italic` class on their `<em>` "has a story." span that predates
this change; left in place it would have fought the newly-italic parent, so it was removed as
part of making the whole headline italic (not a drift fix — a direct consequence of this slice's
own edit). Per-word `<em>` emphasis colors (`text-icon-accent`/`text-horizon-ink`/`text-text-2`,
which vary per page and in a couple of cases don't match the prototype's own `text-icon-accent`)
were deliberately left untouched — out of this fork's scope, which is the headline's overall
size/style/case, not its existing inline-emphasis palette.

Verified visually via a one-off Playwright script (chromium installed per the `browser-review`
skill's `setup.sh`): Home, Profile, Settings, Trends at the iPhone 13 viewport, both grounds
forced via the `temari-theme` localStorage key, subagent-reviewed for contrast/overflow/font
rendering. All four confirmed correct; Home's headline (verdict-conditional, e.g. "you're faster
than you were in April.") required a full-page (not viewport-only) capture since it sits below an
AI-narration card.

## Files touched

The bug-fix batch above, plus fork 1's implementation:
[PageHero.tsx](../../resources/js/components/ui/PageHero.tsx) (+ its test),
[VerdictHero.tsx](../../resources/js/components/home/VerdictHero.tsx),
[Onboarding/Index.tsx](../../resources/js/pages/Onboarding/Index.tsx) (+ its test),
[Race.tsx](../../resources/js/pages/Race.tsx),
[Trends.tsx](../../resources/js/pages/Trends.tsx) (+ its test),
[Inbox.tsx](../../resources/js/pages/Inbox.tsx),
[Profile.tsx](../../resources/js/pages/Profile.tsx) (+ its test, same file as the dark-mode-contrast
fix above but a distinct hunk),
[Settings/Index.tsx](../../resources/js/pages/Settings/Index.tsx) (+ its test),
[Plan.tsx](../../resources/js/pages/Plan.tsx),
[Runs/Show.tsx](../../resources/js/pages/Runs/Show.tsx) (+ its test),
[Activities/Calendar.tsx](../../resources/js/pages/Activities/Calendar.tsx),
[Activities/Feed.tsx](../../resources/js/pages/Activities/Feed.tsx).
The rest of this slice's "touch" is the audit and the doc/tracker amendments — forks 2/3/5's
implementations land in their own follow-up slices, not counted here.

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

n/a for the bug-fix batch below — both fixes are markup/token/formatter corrections; existing
1:1 tests (`RunListRow.test.tsx`, `Profile.test.tsx`, `Settings/Index.test.tsx`) already cover the
touched components' rendering and pass unchanged.

Fork 1 (`slice/v0-headline-voice`): 95.7%→95.51% stmts, 89.5%→89.36% branches, 95.55%→95.47% fn,
96.05%→95.95% lines (vs `S12`'s last recorded numbers) — still comfortably above the 95%
line+function gate. `PageHero.test.tsx` gained one assertion for the new `quote-lg` size step;
`Onboarding/Index.test.tsx`, `Trends.test.tsx`, `Profile.test.tsx`, `Settings/Index.test.tsx` and
`Runs/Show.test.tsx` had their copy-casing assertions updated to match the lowercased headlines,
no new test files needed.

## Verification notes

Two confirmed, unrelated bugs found during the comparison sweep and fixed in one PR (both small,
both part of `V0`'s bug-fix scope):

**Dark-mode contrast.** Profile's "Training · pace targets" tiles (`StatTile tone="cream"`) and
the Settings "Send test notification" pill (`pillButtonVariants` `outline` tone) both painted with
the raw `cream`/`cream-deep` palette tokens (`--color-cream`, `--color-cream-deep`), which are
**not** redefined under `[data-theme='dark']` in `resources/css/app.css` — unlike `--color-card`/
`--color-border`, which are. That made both surfaces render light/white regardless of ground.
Fixed by switching both to the already-ground-reactive `card`/`border` pair: `StatTile`'s existing
`tone="card"` variant for the four pace tiles, and `bg-card border-border` in place of
`bg-cream border-cream-deep` for the `PillButton` `outline` tone (a shared component — the fix
applies everywhere `tone="outline"` is used, all of them equally miscoloured in dark mode before
this). `DesignTokenContrastTest` passed without needing a `grounds.json` regeneration — both
`card` and `border` were already classified panel backgrounds.

**History row title truncation.** `RunListRow.tsx`'s markup is structurally identical to the
prototype's `RunRow` (same `flex min-w-0` / `truncate` chain), so the truncation wasn't a CSS
layout bug — it was content width. The row calls `formatNaiveIdDate(detail.start_date_local)` for
the trailing date, and that formatter's `'short'` branch (the default) still sets
`weekday: 'long'` in its `toLocaleDateString` call, per its own doc comment ("weekday + date") —
correct for `formatNaiveIdDate`'s other ~15 call sites (chart labels, streak copy, PR captions),
but the S7 restyle put this same call inline, on one line, next to the title, where a full weekday
name ("Monday, Aug 31") eats most of the available width the shared component's flex-none siblings
would otherwise leave for the name — matching the prototype's own terse `"12 aug · 6:12am"` date,
not a weekday-prefixed one. Fixed by adding `formatNaiveMonthDayId` (`resources/js/lib/pace.ts`,
a null-safe wrapper around the existing `formatMonthDayId`/`parseNaiveLocalDate`, e.g. `"Aug 25"`)
and switching `RunListRow` to it, leaving `formatNaiveIdDate` and its other call sites untouched.

Both verified visually: `npm run build` + logging into the demo account at the iPhone 13 viewport,
`temari-theme` forced to dark for the contrast fix, both grounds for History.

## Open questions

None blocking. Headline voice (fork 1) is implemented, see above. The three remaining forks
(brand mark, Plan phase bar, desktop nav) are queued as follow-up slices — not open questions,
since the user has already ruled on all of them (see `plan/README.md` §5); they're implementation
work, not undecided scope.
