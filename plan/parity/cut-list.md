# Cut list

Per-feature disposition under the prototype-parity constraint, settled 2026-08-31. The companion to
[README.md](README.md) §2 — that file holds the decisions, this one holds what actually happens to
each surface and where the code lives.

**Reversal column**: where a row overrides a verdict from the original program's
[../ledger.md](../ledger.md) (final as of 2026-08-28), it says so. That ledger is **not edited** —
it remains the accurate record of what was decided then. This table supersedes it.

---

## 1. Cut

| feature | where it lives | reversal | notes |
|---|---|---|---|
| **Card reveal** | `CardReveal` component, `pending_reveal_card_id` flow, `POST /api/cards/{card}/seen`, `POST /api/cards/{card}/replay`, `CardSeenController`, `CardReplayController` | ledger: Kartu "restyle" (partial) | The prototype draws no reveal. Card generation survives (§2). |
| **Today's featured-card panel** | `FeaturedKartuPanel` on `Home.tsx`, its `briefing_featured_kartu_voice` narration | ledger: Kartu "restyle" (partial) | P29. A Kartu surface the prototype's Today screen does not draw. |
| **Persona mix** | `PersonaBar.tsx`, `PersonaMixTool.php` | **reverses** ledger "restyle" | P13. Replaced in the same Profile-hero slot by the prototype's Z1-Z5 time-in-zone bar. |
| **Dawn-shift** | `useDawnShift.ts`, `data-time-of-day` on `<body>`, its consumers in `designTokens.ts` / `shareCard.ts` | **reverses** ledger "keep, light-ground only" | P17. Light ground becomes one static palette. |
| **Relative effort** | `RelativeEffort.php`, its display in `Runs/Show.tsx` / `useRunShow.ts` | **reverses** ledger "keep, mechanical" | P18. The prototype's vitals card draws HR/cadence/grade/flat-pace/decoupling instead. |
| **Unlock toast + accessory-unlock modal** | `UnlockToast.tsx` (mounted in `AppShell`), `AccessoryUnlockModal.tsx` | ledger: badge "restyle" (partial) | P14. Note the ledger's own coupling warning: confirm whether `AccessoryUnlockModal` is accessory-only before deleting, since Accessories is separately cut. |
| **"Why this earned X" explainer** | Rarity-reason block on `Runs/Show.tsx` | ledger: Kartu "restyle" (partial) | P14/P28. |
| **Resync / notify utility row** | The "Resync from Strava" + "Send notification" row above the activity card on `Runs/Show.tsx` | new | P28. Not drawn by the prototype. |
| **Desktop `TopNav`** | `TopNav.tsx` (+ test) | **reverses** `V0` fork 5, [#678](https://github.com/nukipratama/temari/pull/678) | P9. Fork 5 shipped ~1h before P5/P6 existed. The bottom pill becomes the only nav at every width. |
| **`MeTabs`** | `components/me/MeTabs.tsx` (+ test) | new | P8. Replaced by the prototype's topbar gear/avatar entry points. |
| **Trends: milestones, badge board, strain & monotony, VDOT/pace history, personal bests** | `components/trends/`, `Trends.tsx` | new | P25. Trends keeps four blocks only. **Scoped to Trends**: VDOT survives on Profile as a stat tile (`ProfileScreen.tsx:27`) and PR history survives as Profile's `ProgressionCard` journey chart (`:486`), both of which the prototype draws. Do not read this row as an app-wide cut. |
| **Race: CTL/ATL fitness chart** | `race/CtlTrendChart.tsx` usage on `Race.tsx` | new | P26. ~~The chart itself survives — it is Trends' fitness chart.~~ **Corrected 2026-09-01 by `PS5`**: it does not. `PS6` built Trends' `FitnessPanel` on its own `lazy(() => import('@/components/collection/LineChart'))` rather than reusing `CtlTrendChart`, so that component now has **no consumer anywhere**. Left standing for `W2` to sweep, the same call `PS10` made on `ProgressionChart`. `LineChart` itself is untouched and still lazy-loaded by path from two places. |
| **Plan: "Season Track" tier module** | `SeasonTrack` on `Plan.tsx` | new | P24. Season goals still exist; surfaced as the prototype's single progress line. |
| **Profile: `SeasonStreakPanel` five-row layout** | `components/me/SeasonStreakPanel.tsx` | new | P24. Replaced by the prototype's small `SeasonCard` (phase bar + one line). |
| **Today: day-grained streak readout** | The "N Credited In A Row" line in `WeekPlanWidget.tsx` | **supersedes** the 2026-08-30 streak-redesign amendment (partial) | P27. "Streak" survives only as the week-streak badge chip on Trends. |
| **App-shell chrome on Onboarding** | `Onboarding/Index.tsx`'s layout | new | P28/P6. The prototype gives Login and Onboarding no topbar and no bottom nav. |
| **`TemariProto` mascot system** | `TemariProto.tsx`, its pose map, `build-mascot.mjs`, accessory-driven variants | **reverses** ledger art work in `F5` | P10. Replaced everywhere by the prototype's `FaceIcon`. |
| **Accessories** | page, routes, controller, request, service, 25 SVGs | ledger: already **cut** | P21. No change — still `W1`/`W2`'s job. |

**Depth (P4)**: every row above means *UI and routes removed, surface unreachable*. Orphaned models,
tables, jobs and services are swept by the original program's `W2`.

---

## 2. Keep — confirmed by the prototype

| feature | evidence in the prototype | notes |
|---|---|---|
| **Kartu generation + share** | Login's "a card for every run" teaser with a Legendary rarity chip; History calendar's kartu badge (inside a week row's expanded narration disclosure, `HistoryScreen.tsx:649-661` — not on a day cell); Inbox unlock rows with rarity badges (**corrected 2026-09-01 by `PS9`**: this surface had **never rendered** — `InboxRow` guarded on `kind === 'unlock' && rarity !== null`, but `UnlockGrantedNotification`'s payload carries `{unlock_key, name, icon, is_major}` and no rarity, so the badge was unreachable. It was aspirational evidence, not observed. `PS9` made it real, rating rows read-side from `config/temari_unlocks.php`. The row's other two references were and remain genuine, so P12's verdict stands) | P12. Those three references are prototype-backed. **The share button is not**: the prototype draws no share button and has no dialog or modal anywhere in its tree. It is a deliberate divergence, chosen so a generated card is viewable at all — see §4. There is no collection page, and none exists to cut. |
| **Leaflet route map** | `MapWeatherPanel` draws a placeholder with an "activate map" badge — a mockup stand-in for a real map | P16. The real map is kept, styled into that slot, with the weather row beneath. Confirms ledger "keep, mechanical". |
| **Run lenses** | `RunLenses` — the "what temari says" narration card with its claims list | P19. Confirms ledger "keep". |
| **Ask about this run** | `AskAboutRun` — Q&A panel with prior questions, suggestion chips, input | Earlier audit wrongly reported this as absent. |
| **Vitals / splits / laps** | `VitalsCard`, `SplitsChartCard`, `LapsCarousel` | Same correction — all three are drawn. |
| **Today's weekly stats block** | Inside the prototype's own "this week's stats" disclosure: stat figures, three vital bars, last-run card, condition card | Same correction. **Renders closed by default**, as the prototype does (`TodayScreen.tsx:464` passes no `defaultOpen`). Supersedes `V0` fork 4, which chose open-by-default under the old parity-optional rule and partly on a screenshot that never opened it. |
| **Badge granting + two surfaces** | Trends fitness-panel chips; Inbox unlock rows | P14. Everything else about badges is cut. |
| **Plan skip + move** | `onSkipSession`, `onMoveSession`, a `canSkip`/`canMove` guard, and a weekday-picker for move targets | P23. Pin, Block and Delete appear nowhere and are cut. |
| **Legal pages** | Linked from Settings' "the fine print" and the Login footer | P20. |
| **Operator console** | Not in the prototype at all | P20. Kept deliberately — operator tooling, outside the product surface; the AI dead-letter re-arm is load-bearing. |
| **AI narration everywhere** | Today spotlight, Plan "temari's take" (season, week and day), Trends "temari's read", History recap cards, Activity narration, Profile "what temari says about you" | Every narrator `B4` built has a prototype home. |
| **Real-data plumbing** | Not drawable in a static mockup | P1. Loading, error, empty, AI-pending, retry, pagination, auth all survive. |

---

## 3. Replace — same slot, different content

| shipped | becomes | decision |
|---|---|---|
| Persona mix bar (Profile hero) | Z1-Z5 time-in-zone bar with legend | P13 |
| `TemariProto` mascot | `FaceIcon` (simple line-art ring + face) | P10 |
| Mascot on share cards | Temari brand mark | P11 |
| Plan's flat day-card list | Nested week-cluster → week → day → session-segment timeline | P22 |
| Plan's "Season Track" | The prototype's `SeasonHeaderCard` | P24 |
| Profile's `SeasonStreakPanel` | The prototype's small `SeasonCard` | P24 |
| Desktop `TopNav` + `MeTabs` | Bottom pill at every width; topbar bell/avatar/gear | P7-P9 |
| Multi-column desktop layouts | One column, fluid to ~600-700px, centred | P5 |

---

## 4. Divergences from the prototype, accepted deliberately

Recorded so a later slice does not "fix" them back toward the mockup.

| divergence | why |
|---|---|
| **Badge chips wrap to show every earned badge** | The prototype hardcodes three. A real account earns a variable number; truncating to three would hide real progress. P15. |
| **Range tabs and "load older" actually work** | The prototype sets state nothing reads and swaps hardcoded arrays. Mockup limitation, not design intent. P3. |
| **Token-nearest sizing, not literal pixels** | The prototype hardcodes values (`rounded-[14px]`, `text-[9px]`, `size-[18px]`) the app deliberately replaced with token scales in `F2`/`F3`. Matching them literally would need one-off tokens and guard exceptions. P2. |
| **The nav pill is column-width, not full-bleed** | The prototype gives `AppTopbar`/`AppBottomNav` no container queries at all, deliberately keeping chrome full-bleed while content narrows to 760px. We diverge: at 1536px a full-bleed pill spreads its four items uncomfortably far apart. P32. (An earlier version of this row claimed the prototype "has no responsive opinion at all" — false, see [README.md](README.md) §6.) |
| **Activity detail has a share button** | The prototype draws no share button and no dialog/modal anywhere. Without one, a generated Kartu would be permanently unviewable, which contradicts the Login teaser's own promise of "a card for every run". P12. |
| **Real-data plumbing exists** | P1. |
| **Operator console exists** | P20. |
