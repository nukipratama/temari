# Prototype reference

The per-screen spec that slices `PS1`-`PS11` implement against, sourced from the frozen prototype at
[../../resources/brand/prototype/src/](../../resources/brand/prototype/src/) and sanity-checked
against `PP0`'s full-scroll capture set.

Companion to [README.md](README.md) (the decisions) and [cut-list.md](cut-list.md) (what happens to
each surface). This file describes **what the prototype draws**. It does not decide anything; where
the prototype and a decision disagree, the decision wins and the row says so.

Read it as a checklist: a screen slice is done when every section below renders, in this order, with
the interactions listed, and the `@min-[900px]:` column behaves as described.

---

## 0. How to read this

- **Citations** are `resources/brand/prototype/src/...:line` against the tree as frozen. The
  prototype is read-only (decision 19); a line that moves means someone edited it.
- **"Dead"** means the prototype draws the control and wires it to nothing. Per decision P3 those
  become real behaviour in the shipped app; they are listed anyway, because the control must exist.
- **Capture files** are `<pagekey>-<viewport>.jpg` for the light full-scroll pass across the five
  viewports, `<pagekey>-mobile-dark.jpg` for the dark ground, and
  `<pagekey>-mobile-<statename>-<statevalue>.jpg` for an alternate state.

---

## 1. The responsive model

The prototype has exactly **one** breakpoint and it is a **container query**, not a media query.
[PhoneFrame.tsx:49](../../resources/brand/prototype/src/components/rack/PhoneFrame.tsx) sets
`[container-type:inline-size]` on the element that wraps every screen, so `@min-[900px]:` resolves
against the *frame's* inner width, not the browser's.

Frame inner widths, from
[viewports.ts:1](../../resources/brand/prototype/src/components/rack/viewports.ts) and
[PhoneFrame.tsx:32](../../resources/brand/prototype/src/components/rack/PhoneFrame.tsx)
(`p-3.5` = 14px per side on phone/tablet chrome, `p-0` on browser chrome):

| viewport | frame | chrome | inner width | `@min-[900px]:` fires |
|---|---|---|---|---|
| `se` | 320x568 | phone | 292 | no |
| `mobile` | 390x844 | phone | 362 | no |
| `tablet` | 834x1112 | tablet | 806 | **no** |
| `desktop` | 1280x800 | browser | 1280 | yes |
| `wide` | 1536x864 | browser | 1536 | yes |

Tablet sits below the breakpoint. Its captures show the mobile layout at a wider measure, and that
is correct, not a capture bug.

`@min-[900px]:` appears **93 utility instances across 24 lines in all 11 screens**, and nowhere in
`components/rack/` or `components/ui/`. There is no other container-query prefix and no `sm:`/`md:`/
`lg:` breakpoint prefix anywhere in the prototype's screens or chrome. (The `sm:`/`lg:` tokens in
[button.tsx:26](../../resources/brand/prototype/src/components/ui/button.tsx) and
[toggle.tsx:19](../../resources/brand/prototype/src/components/ui/toggle.tsx) are `cva` size-variant
object keys, not Tailwind prefixes.)

### 1.1 The root-container step

Nine of the eleven screens carry one root wrapper whose whole responsive behaviour is
"centre, cap, repad". Onboarding uses the same shape with a different width; Login has **no root
wrapper at all** ([LoginScreen.tsx:79](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx)
is a bare `<div>` with no className) and instead puts the step on four separate blocks.

| screen | line | max-w | px | pt | pb |
|---|---|---|---|---|---|
| Today | [TodayScreen.tsx:419](../../resources/brand/prototype/src/components/pages/TodayScreen.tsx) | 760 | 4 → 6 | 16 → 6 | 22 → 10 |
| Plan | [PlanScreen.tsx:1729](../../resources/brand/prototype/src/components/pages/PlanScreen.tsx) | 760 | 4 → 6 | 16 → 6 | 22 → 24 |
| Race | [RaceGoalScreen.tsx:438](../../resources/brand/prototype/src/components/pages/RaceGoalScreen.tsx) | 760 | 4 → 6 | 16 → 6 | 22 → 24 |
| Trends | [TrendsScreen.tsx:301](../../resources/brand/prototype/src/components/pages/TrendsScreen.tsx) | 760 | 4 → 6 | 16 → 6 | 22 → 24 |
| History | [HistoryScreen.tsx:752](../../resources/brand/prototype/src/components/pages/HistoryScreen.tsx) | 760 | 4 → 6 | 16 → 6 | 22 → 24 |
| Activity | [ActivityDetailScreen.tsx:1030](../../resources/brand/prototype/src/components/pages/ActivityDetailScreen.tsx) | 760 | 4 → 6 | 16 → 6 | 7 → 14 |
| Inbox | [InboxScreen.tsx:291](../../resources/brand/prototype/src/components/pages/InboxScreen.tsx) | 760 | 4 → 6 | 16 → 6 | 7 → 24 |
| Profile | [ProfileScreen.tsx:537](../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx) | 760 | 4 → 6 | 16 → 6 | 7 → 22 |
| Settings | [SettingsScreen.tsx:613](../../resources/brand/prototype/src/components/pages/SettingsScreen.tsx) | 760 | 4 → 6 | 16 → 6 | 7 → 22 |
| Onboarding | [OnboardingScreen.tsx:685](../../resources/brand/prototype/src/components/pages/OnboardingScreen.tsx) | **520** | 4 → 6 | **16 → 16** | 10 → 16 |
| Login | — | see §1.2 | | | |

`pt-16` → `pt-6` holds for all nine 760px screens: the mobile top pad reserves room for the floating
topbar, and above 900px it does not need to. **Onboarding's `pt-16` does not shrink** — it has no
topbar to clear, so the value is a design choice, not chrome clearance. Bottom padding is not
uniform: it shrinks on Today, grows on the other eight.

### 1.2 The wide-only reflows

Beyond the root-container step, these are every layout that actually changes shape at 900px. There
are **eleven**, not four. `README.md` P5 names only four of them; the other seven are real and
`PP1` must carry them.

| # | screen | line | below 900 | at/above 900 |
|---|---|---|---|---|
| 1 | Login hero | [LoginScreen.tsx:82](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx) | `px-[22px] pt-14 pb-6` | `px-14 pt-16 pb-9` |
| 2 | Login headline | [LoginScreen.tsx:97](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx) | `text-[34px]` | `text-[46px]` |
| 3 | Login subhead | [LoginScreen.tsx:102](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx) | `max-w-[32ch] text-[13.5px]` | `max-w-[44ch] text-[15px]` |
| 4 | Login auth card | [LoginScreen.tsx:109](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx) | `mx-3.5 -mt-4.5`, full width | `mx-auto -mt-7.5 max-w-[440px]` |
| 5 | Login `WhyRow` item | [LoginScreen.tsx:58](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx) | horizontal strip, icon left, on a shared hairline list | vertical card: `flex-col items-start`, own `rounded-2xl border border-border-strong p-4` + shadow |
| 6 | Login "why the comparison is fair" list | [LoginScreen.tsx:156](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx) | `flex flex-col gap-px` hairline list inside a bordered container | `grid grid-cols-3 gap-2.5`, container border/bg/shadow removed |
| 7 | Login "what you get" list | [LoginScreen.tsx:191](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx) | same hairline list | `inline-grid grid-cols-2 mx-auto gap-2.5`, container border/bg/shadow removed |
| 8 | Today plan-card head | [TodayScreen.tsx:242](../../resources/brand/prototype/src/components/pages/TodayScreen.tsx) | `gap-4` between progress ring and stat grid | `gap-6` |
| 9 | Profile hero right block | [ProfileScreen.tsx:100](../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx) | `hidden` | `block`, right-aligned "with temari since / 12 jun 2026" |
| 10 | Settings **HR-zone bounds** input grid, inside `ZonesDisclosure` | [SettingsScreen.tsx:473](../../resources/brand/prototype/src/components/pages/SettingsScreen.tsx) | `grid-cols-2` | `grid-cols-4` |
| 11 | Settings `AccountActions` row | [SettingsScreen.tsx:581](../../resources/brand/prototype/src/components/pages/SettingsScreen.tsx), [:584](../../resources/brand/prototype/src/components/pages/SettingsScreen.tsx) | `flex-col items-center`, buttons `w-full` | `flex-row justify-center`, buttons `w-auto px-6` |

Row 10 is the only `grid-cols-2` → `grid-cols-4` in Settings. It is the zones editor's bounds
inputs, **not** the appearance toggle group, which takes no container query at all.

Login's two footer/pitch wrappers ([LoginScreen.tsx:152](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx),
[:230](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx)) also take the
`mx-auto max-w-[760px]` step, which is why Login's column is 760px for its pitch and footer and
440px only for the auth card.

### 1.3 The chrome takes no step

Neither [AppTopbar.tsx](../../resources/brand/prototype/src/components/rack/AppTopbar.tsx) nor
[AppBottomNav.tsx:61](../../resources/brand/prototype/src/components/rack/AppBottomNav.tsx) carries
a single container query. The nav pill stays `inset-x-3.5` full-bleed at every width while content
narrows to 760px. Decision **P32** diverges from this on purpose; see
[cut-list.md](cut-list.md) §4.

---

## 2. The shell

### 2.1 Frame

[PhoneFrame.tsx:28-61](../../resources/brand/prototype/src/components/rack/PhoneFrame.tsx) renders
device chrome around each screen: `rounded-[52px] p-3.5` with a notch pill for `phone`, the same
without the notch for `tablet`, and a `rounded-2xl` browser bar with three dots for `browser`
([:39-45](../../resources/brand/prototype/src/components/rack/PhoneFrame.tsx)). The frame is a
gallery device, not a shipped surface — only the inner content div
([:46-59](../../resources/brand/prototype/src/components/rack/PhoneFrame.tsx)) has app meaning:
`overflow-hidden bg-background text-foreground`, the topbar slot, a scroll container, the bottom-nav
slot.

### 2.2 Topbars

| topbar | file | contents |
|---|---|---|
| `AppTopbar` | [AppTopbar.tsx:14-35](../../resources/brand/prototype/src/components/rack/AppTopbar.tsx) | left: `TemariMark` + "temari" lockup pill; right: bell button with a `2` unread badge, then a 7px avatar circle with initial `N` |
| `ActivityTopbar` | [ActivityTopbar.tsx:8-19](../../resources/brand/prototype/src/components/rack/ActivityTopbar.tsx) | back chevron only |
| `InboxTopbar` | [InboxTopbar.tsx:8-19](../../resources/brand/prototype/src/components/rack/InboxTopbar.tsx) | back chevron only |
| `ProfileTopbar` | [ProfileTopbar.tsx:12-39](../../resources/brand/prototype/src/components/rack/ProfileTopbar.tsx) | back chevron; right: gear then bell (with unread badge) |
| `SettingsTopbar` | [SettingsTopbar.tsx:12-30](../../resources/brand/prototype/src/components/rack/SettingsTopbar.tsx) | back chevron; right: bell (with unread badge) |

All five are `absolute inset-x-0 top-0 z-10 ... p-4 pb-2.5` — floating over content with no
background, which is what the `pt-16` on each screen's root wrapper clears.

Note the ordering difference: `ProfileTopbar` puts the **gear before the bell**; `SettingsTopbar`
has a bell and **no gear**; `AppTopbar` has bell then avatar, no gear, no back chevron.

### 2.3 Bottom nav

[AppBottomNav.tsx:59-71](../../resources/brand/prototype/src/components/rack/AppBottomNav.tsx).
Four items, in order: `today` (Sunrise), `plan` (CalendarCheck), `trends` (LineChart), `history`
(History) — [:6-11](../../resources/brand/prototype/src/components/rack/AppBottomNav.tsx). The
active item grows to `grow-[1.6]`, gains a horizon gradient fill, and reveals its uppercase mono
label by animating `max-w` 0 → 60px; inactive items are icon-only at `size-[18px]`
([:24-48](../../resources/brand/prototype/src/components/rack/AppBottomNav.tsx)). It is a frosted
`backdrop-blur-xl` pill and has **no box-shadow** — the docblock at
[:52-58](../../resources/brand/prototype/src/components/rack/AppBottomNav.tsx) records that the
original HTML's shadow referenced an undefined var and never rendered.

There is no fifth nav item. Race is reachable only from Plan's tab bar.

### 2.4 Chrome per screen

From [App.tsx:139-705](../../resources/brand/prototype/src/App.tsx):

| screen | topbar | bottom nav | nav item lit |
|---|---|---|---|
| Login | none | none | — |
| Onboarding | none | none | — |
| Today | `AppTopbar` | yes | `today` |
| Plan | `AppTopbar` | yes | `plan` |
| Race | `AppTopbar` | yes | **`plan`** |
| Trends | `AppTopbar` | yes | `trends` |
| History | `AppTopbar` | yes | `history` |
| Activity | `ActivityTopbar` | **no** | — |
| Inbox | `InboxTopbar` | **no** | — |
| Profile | `ProfileTopbar` | **no** | — |
| Settings | `SettingsTopbar` | **no** | — |

Race lighting the `plan` tab is [App.tsx:387](../../resources/brand/prototype/src/App.tsx). Every
`AppTopbar`/`ProfileTopbar`/`SettingsTopbar` bell is wired to `openInbox`
([App.tsx:85](../../resources/brand/prototype/src/App.tsx)).

The outer page-picker header ([App.tsx:90-130](../../resources/brand/prototype/src/App.tsx)), its
mobile counterpart ([App.tsx:708-734](../../resources/brand/prototype/src/App.tsx)) and the
per-screen state toggle pills are the **gallery's own chrome, outside the device frame**. None of it
is app surface.

### 2.5 Shared primitives

- [FaceIcon.tsx](../../resources/brand/prototype/src/components/FaceIcon.tsx) — the ring+face
  line-art mascot (decision P10).
- [ProgressRing.tsx](../../resources/brand/prototype/src/components/ProgressRing.tsx) — credited/total ring.
- [TemariMark.tsx](../../resources/brand/prototype/src/components/rack/TemariMark.tsx) — the brand mark.
- [AiReplanPill.tsx:5-16](../../resources/brand/prototype/src/components/pages/AiReplanPill.tsx) —
  the shared cooldown pill: an inert `<span>`, `cursor-not-allowed`, clock icon, "next in 5h 40m".
  Used by Plan, Race and Settings.
- [ScheduleRaceTabs.tsx:14-33](../../resources/brand/prototype/src/components/pages/ScheduleRaceTabs.tsx) —
  the two-tab schedule/race-goal switch shared by Plan and Race.
- [collapsible.tsx](../../resources/brand/prototype/src/components/ui/collapsible.tsx) — the
  disclosure primitive. Uncontrolled and **closed by default** unless a `defaultOpen` is passed.

---

## 3. Login

`PS1`. Capture: `login-{se,mobile,tablet,desktop,wide}.jpg`, `login-mobile-dark.jpg`.
Source: [LoginScreen.tsx](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx).
Props `{ onConnect, onTryDemo }` at [:74-77](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx).

**Chrome**: none. No topbar, no bottom nav ([App.tsx:139-148](../../resources/brand/prototype/src/App.tsx)).

### Sections, top to bottom

| # | section | line | what it draws |
|---|---|---|---|
| 1 | hero band | 82-106 | dark sky→leaf gradient block, explicitly **not theme-reactive** (comment at 80-81) |
| 1a | glow | 83-89 | blurred radial circle in `--horizon`, decorative |
| 1b | wordmark | 90-93 | `TemariMark` + "temari" |
| 1c | eyebrow | 94-96 | mono uppercase "running companion" |
| 1d | headline | 97-101 | italic serif "you vs / past you.", the second line emphasised |
| 1e | subhead | 102-105 | "every run gets matched against one you've already done..." |
| 2 | auth card | 109-149 | `Card` overlapping the hero by `-mt-4.5` |
| 2a | label + copy | 110-116 | "start with your history" / "sign in through Strava - no separate account, read-only access." |
| 2b | Strava button | 117-131 | Strava-orange button with an inline brand glyph |
| 2c | demo button | 132-139 | ghost button, `PlayCircle`, "try the demo" |
| 2d | footnote | 140-148 | "read-only, and only for you." + a "what temari stores" link |
| 3 | "why the comparison is fair" | 153-160 | eyebrow + 3 `WhyRow`s from `WHY_FAIR` (21-37) |
| 4 | "what you get" | 162-195 | eyebrow; a kartu mock `Card` (165-189) with a gradient thumbnail, a wavy SVG route line and a **"Legendary" rarity chip** (180-182), caption "a card for every run - route, pace and mood, collectible and occasionally rare"; then 2 `WhyRow`s from `WHY_GET` (39-50) |
| 5 | "data & AI use" disclosure | 197-227 | `Collapsible`; content holds "what temari stores" (210-217) and "before you take its advice" (218-225) |
| 6 | footer | 230-250 | 4 legal links - terms, privacy, how temari uses AI, training disclaimer (231-246) - then the mono tagline "temari · your running companion, every step" (247-249) |

`WhyRow` is declared at [:52](../../resources/brand/prototype/src/components/pages/LoginScreen.tsx).

### Interactions

| control | line | behaviour |
|---|---|---|
| "connect with Strava" | 117-131 | calls `onConnect` |
| "try the demo" | 132-139 | calls `onTryDemo` |
| "what temari stores" | 142-147 | dead `href="#"` |
| "data & AI use" disclosure | 198-208 | real toggle; chevron rotates on `group-aria-expanded` (205). Closed by default |
| 4 footer legal links | 238-244 | dead `href="#"` |

No `useState` in the file; the disclosure owns the only state.

### At `@min-[900px]:`

Login is the densest screen in the responsive layer: **36 of the 93 utility instances**, on 9 lines.
See §1.2 rows 1-7 for the reflows. The column is **760px** for the pitch (152) and footer (230);
the auth card is **440px** (109). There is no single root wrapper to cap - the step is applied
per-block.

### Alternate states

None. The screen has no props that branch and no state beyond the disclosure.

---

## 4. Onboarding

`PS2`. Capture: `onboarding-{se,mobile,tablet,desktop,wide}.jpg`, `onboarding-mobile-dark.jpg`.
Source: [OnboardingScreen.tsx](../../resources/brand/prototype/src/components/pages/OnboardingScreen.tsx)
plus [PreferenceControls.tsx](../../resources/brand/prototype/src/components/pages/PreferenceControls.tsx).
Props `{ onFinish }` at [:677-679](../../resources/brand/prototype/src/components/pages/OnboardingScreen.tsx).

**Chrome**: none ([App.tsx:150-158](../../resources/brand/prototype/src/App.tsx)). Decision P34 puts
this on `BareShell`.

A three-step wizard held in one screen. `step` is local state at
[:680](../../resources/brand/prototype/src/components/pages/OnboardingScreen.tsx).

### Sections

| # | section | line | what it draws |
|---|---|---|---|
| 1 | `StepProgress` | decl 172, used 686 | 3 circles (welcome / training / race goal) joined by connector bars; a 4-dot sub-progress row (201-218) appears **only** while `step === 'preferences'` |
| 2 | `ConnectedStep` | decl 257, used 687-689 | `FaceIcon` with a radial glow (260-275); "you're connected, nuki." (276-280); a `Card` of 3 `WHAT_LANDS` bullet rows (282-294); "continue" (296-301) |
| 3 | `PreferencesStep` | decl 401, used 690-699 | back button (432-443, only when `subIndex > 0`); the current question (452-457); body is either `SessionsDial` (459-470) or a motion-staggered list of `IconChoiceCard` (472-493) |
| 3a | `DaysSubStep` | decl 317 | "which days do you usually run?" (338-340); "pick N - M of N selected" (341-344); a `DayRow` of togglable `DayCell`s (346-367); once `atTarget`, a nested muted card asking "which one's your long run?" with a second `DayRow` limited to the selected days (370-396) |
| 4 | `GoalStep` | decl 500, used 700-702 | recap line "got it - {summary}." (532-536, only when `prefsSummary` is set); "got a race in mind?" + an "optional" pill (537-546); copy (547-550); a pace-ring card (552-600) with a glow, an SVG progress ring tweened by `useCountUp` (562-582), a centred `FaceIcon` (583-590) and a "required pace" readout (593-598); a form `Card` (602-656) with name, race-day date, `DISTANCE_PRESETS` pills and hour/minute goal-time inputs; an action row (658-672) |

Sub-components: `formatPace` 152, `circleTone` 162, `StepProgress` 172, `PillOption` 236,
`ConnectedStep` 257, `labelFor` 306, `DaysSubStep` 317, `PreferencesStep` 401, `GoalStep` 500.

### Interactions

| control | line | behaviour |
|---|---|---|
| "continue" | 296-301 | advances `step` to `'preferences'` (688) |
| back | 434-441 | `subIndex - 1`; hidden at `subIndex === 0` |
| `SessionsDial` bars | 465-469 | sets `answers.sessions` and advances `subIndex` (416-419) |
| `IconChoiceCard` | 483-489 | sets that answer and advances `subIndex` |
| day toggles | 346-367 | `toggleDay` (324-334); a cell is disabled once the target count is reached and it is not already picked (349-350) |
| long-run picks | 374-390 | `finishDays` (421-428) builds the summary string, then `step = 'goal'` (694-697) |
| distance pills | 622-630 | `setDistanceKm`; recomputes the pace ring |
| hour / minute inputs | 638-654 | `setHours` / `setMinutes`; recompute the pace ring |
| name input | 605-609 | **uncontrolled, dead** - no `value`/`onChange` |
| race-day date input | 613-616 | **uncontrolled, dead** |
| "set my goal & finish" | 659-664 | calls `onFinish`; no entered field is passed |
| "skip for now" | 665-671 | calls the **same** `onFinish` - the mockup does not distinguish them |

### At `@min-[900px]:`

One line, [:685](../../resources/brand/prototype/src/components/pages/OnboardingScreen.tsx):
`mx-auto max-w-[520px]`, `px-4` → `px-6`, `pb-10` → `pb-16`. **`pt-16` does not change.** No
container query anywhere in `PreferenceControls.tsx`. Onboarding is the only 520px column.

### Alternate states

All local, none prop-driven, so none has a dedicated capture: `step` (680), `subIndex` vs the
3-question list (413), `sessions` vs icon-choice question bodies (459/472), `prefsSummary` set or
empty (532-536), `atTarget` gating the long-run block (322, 369), day cells disabled at capacity
(349-350), `subIndex > 0` gating back (433), and `circleTone`'s three stepper states (162-170).

---

## 5. Today

`PS3`. Capture: `today-{se,mobile,tablet,desktop,wide}.jpg`, `today-mobile-dark.jpg`,
`today-mobile-planstate-empty.jpg`.
Source: [TodayScreen.tsx](../../resources/brand/prototype/src/components/pages/TodayScreen.tsx).
Props `{ planState: 'has' | 'empty' }` at [:415-417](../../resources/brand/prototype/src/components/pages/TodayScreen.tsx).

**Chrome**: `AppTopbar` + bottom nav, `today` lit ([App.tsx:185-186](../../resources/brand/prototype/src/App.tsx)).

### Sections

| # | section | line | what it draws |
|---|---|---|---|
| 1 | plan card | decl 229, used 420 | header row: "this week's plan" + a `Badge` holding `PhaseSparkline` and the phase name "base" (232-240); a `ProgressRing credited={3} total={5}` beside a 2-stat grid "3/5 sessions" / "18.2 km this week" (242-262); a 7-column `DayCell` grid mon-sun (264-268); a footer link row "today · easy · 6 km · 5:38-5:55/km" with a chevron (270-282) |
| 1a | `PhaseSparkline` | 75-94 | 4 vertical bars, one per phase, the current one solid and the rest dimmed |
| 1b | `DayCell` | 172-227 | weekday label, a flame/feather/bed icon per session type coloured by status, distance text; today's cell gets an inset ring (202) |
| 2 | "you vs past you" | 422-439 | eyebrow "you vs past you · last 90 days"; serif h2 "you're faster than you were in june."; a one-line stat; a bordered list of 2 `EvidenceRow`s (decl 315) each showing before → now with a delta pill |
| 3 | today message card | 441-462 | bordered card with `FaceIcon`, "today" eyebrow, a bold italic line and a second italic paragraph |
| 4 | "this week's stats" disclosure | 464-545 | `Collapsible`; see below |
| 4a | stat strip | 478-485 | 3 `StatFigure`s (decl 342) - "4 runs", "18.2 km", "312 trimp" |
| 4b | vital bars | 487-509 | 3 `VitalRow`s (decl 358) - vibe / readiness / recovery, each a labelled bar with a sub-caption |
| 4c | last-run + condition | 512-543 | 2-column grid: "last run · yesterday" with `MiniRow`s (decl 406) and a "view run detail" link; "condition · 7 days" with `MiniRow`s and a "technical detail" link |

### Interactions

| control | line | behaviour |
|---|---|---|
| today-session footer link | 270-282 | dead `href="#"` |
| "set up a plan" (empty state) | 303-309 | dead `href="#"` |
| "this week's stats" disclosure | 464-545 | real toggle. **No `defaultOpen`, so it renders closed.** Decision P1 / `V0` fork 4 opens it by default in the shipped app - a recorded divergence |
| "view run detail" | 520-526 | dead `href="#"` |
| "technical detail" | 535-541 | dead `href="#"` |

### At `@min-[900px]:`

Two lines. Root [:419](../../resources/brand/prototype/src/components/pages/TodayScreen.tsx)
(760px, `pt-16` → `pt-6`, `pb-22` → `pb-10`) and
[:242](../../resources/brand/prototype/src/components/pages/TodayScreen.tsx) - the plan card's
ring/stats row widens from `gap-4` to `gap-6`.

### Alternate states

`planState`. `'has'` → the plan card; `'empty'` → `NoPlanCard` (decl 287): a `FaceIcon`, "no plan
yet.", body copy and a "set up a plan" link. Capture `today-mobile-planstate-empty.jpg`.

### Explicitly absent

No "Temari's top pick" / featured-kartu panel. No "N in a row" streak line.

---

## 6. Plan

`PS4`, the largest slice. Capture: `plan-{se,mobile,tablet,desktop,wide}.jpg`,
`plan-mobile-dark.jpg`, `plan-mobile-planstate-empty.jpg`, `plan-mobile-racestate-set.jpg`,
`plan-mobile-aireplanstate-cooldown.jpg`, `plan-mobile-weekdaysvariant-showcase.jpg`.
Source: [PlanScreen.tsx](../../resources/brand/prototype/src/components/pages/PlanScreen.tsx).
Props at [:1654-1670](../../resources/brand/prototype/src/components/pages/PlanScreen.tsx).

**Chrome**: `AppTopbar` + bottom nav, `plan` lit ([App.tsx:296-297](../../resources/brand/prototype/src/App.tsx)).

At 7445px tall on mobile this is the tallest screen in the set by a factor of three.

### Sections

| # | section | line | what it draws |
|---|---|---|---|
| 1 | eyebrow "plan" | 1730-1732 | mono uppercase label |
| 2 | header row | 1733-1754 | h1 "the weeks / ahead." plus either `AiReplanPill` (1740) or a "regenerate" link (1742-1752) |
| 3 | intro paragraph | 1755-1774 | race-state-dependent copy, ending in a "change your race" / "set a race" link (1767-1773) |
| 4 | `ScheduleRaceTabs` | 1776-1779 | two-tab switch, `active="schedule"` |
| 5 | body | 1781-1788 | `NoPlanState` (1782) or `SeasonHeaderCard` + `SeasonTimeline` (1785-1786) |

### The nested timeline (decision P22)

```
SeasonTimeline (decl 1519)
 └─ per-phase block (1541-1585, 1587-1623)
     ├─ WeekCluster (decl 1464) - a collapsed "N weeks behind/ahead" summary button
     │    ...which expands into a list of SeasonWeekRow
     └─ SeasonWeekRow (decl 1342)
          ├─ hasDetail=false (1444-1458): a flat summary card - "wk N", date range,
          │  focus text. No chart, no day rows, no actions.
          └─ hasDetail=true (1369-1443): a Collapsible week card
               ├─ trigger (1378-1398): "wk N", range, km, session count, chevron
               └─ content (1399)
                    ├─ "temari's take" label - done weeks only (1400-1405)
                    ├─ focus text (1406-1414)
                    ├─ WeekVolumeChart (decl 974, used 1416) - planned-vs-actual bars
                    └─ WeekDayRow[] (decl 1149, used 1420-1440)
                         ├─ trigger (1195-1237): weekday + type icon, day type,
                         │  summary or "ran anyway", MiniSessionBar preview (1220),
                         │  status/score badge, chevron
                         └─ content (1238-1321)
                              ├─ "temari's take" + day.detail (1239-1245)
                              ├─ SessionBarGraph (1246-1248) - the zone-coloured
                              │  session-segment bar + legend, when segments exist
                              ├─ "view activity" link (1249-1261), when an activity exists
                              └─ actions (1262-1320): the weekday picker grid (1264)
                                 or the move/skip button row (1289)
```

`SeasonHeaderCard` (decl 1066) draws "season · week N of M" (1074-1076), an adherence percentage
(1082-1088), a **4-bar phase progress chart** over `PHASES` = base/build/peak/taper (26-31, rendered
1090-1136), and a "temari's take" label with an italic serif narration paragraph (1137-1144).

Zone colouring: `ZONE_COLOR` maps zones 1-5 to a teal→green→amber→orange→red ramp
([:81-87](../../resources/brand/prototype/src/components/pages/PlanScreen.tsx)); `ZONE_HEIGHT_PCT`
sizes the bars ([:88-94](../../resources/brand/prototype/src/components/pages/PlanScreen.tsx)).
`SessionBarGraph` collapses repeat intervals into one "N× interval" legend block when `reps > 0`
(899, 917-957).

Other sub-components: `MiniSessionBar` 841, `SegmentLegendItem` 864, `SessionBarGraph` 890,
`WeekVolumeChart` 974, `SeasonHeaderCard` 1066, `WeekDayRow` 1149, `SeasonRailNode` 1326,
`SeasonWeekRow` 1342, `WeekCluster` 1464, `SeasonTimeline` 1519, `NoPlanState` 1628.

### Interactions

| control | line | behaviour |
|---|---|---|
| "regenerate" (AI replan) | 1742-1752 | `preventDefault` then `onTriggerAiReplan()`. Rendered only when `aiReplanState !== 'cooldown'` |
| `AiReplanPill` | 1740 | inert `<span>`, `cursor-not-allowed`, "next in 5h 40m" |
| "change your race" / "set a race" | 1767-1773 | dead `href="#"` |
| `ScheduleRaceTabs` schedule tab | tabs 14-23 | `onNavigate('schedule')`; Plan's handler ignores it (1778) |
| `ScheduleRaceTabs` race tab | tabs 24-33 | `onNavigate('race')` → `onNavigateRace()` |
| `NoPlanState` "regenerate" | 1643-1649 | dead `href="#"` |
| week-cluster "show" (past) | 1498-1514, wired 1561 | `setPastOpen(true)`; replaces the cluster with the real week rows |
| week-cluster "show" (future) | 1498-1514, wired 1620 | `setFutureOpen(true)`; same, per later phase |
| week expand/collapse | 1369-1398 | `Collapsible`, `defaultOpen={week.status === 'current'}` (1370) - **the current week starts open, every other week starts closed** |
| day expand/collapse | 1189-1237 | `Collapsible`, no `defaultOpen` - **every day row starts closed** |
| "move this session" | 1291-1302 | `setPicking(true)`; swaps the action row for the weekday picker. Rendered only when `canMove` |
| weekday picker cells | 1265-1288 | 7 buttons, each `disabled={!isValidMoveTarget(wd)}`; on click calls `onMove(wd)` then closes the picker. Bubbles to `moveSession` (1678-1699), which swaps the two days' type/summary/status while preserving each slot's `wd` and `today` |
| "skip this session" | 1304-1316 | `onSkip` → `skipSession` (1701-1715): sets `status: 'skip'`, clears `score` and `actualKm` |
| "view activity" | 1249-1261 | `preventDefault` then `onViewActivity()` |

**Guards.** `canMove` (1184-1185) = status is `upcoming` or `skip` **and** at least one valid
target exists. `isValidMoveTarget` (1178-1182) = a different weekday, strictly later in the week than
today, whose existing entry is a `rest` day. `canSkip` (1186) = `day.status !== 'skip'`.

### At `@min-[900px]:`

One line, the root at [:1729](../../resources/brand/prototype/src/components/pages/PlanScreen.tsx).
760px, `px-4` → `px-6`, `pt-16` → `pt-6`, `pb-22` → `pb-24`. The timeline itself does not reflow.

### Alternate states

| prop / state | values | line | difference | capture |
|---|---|---|---|---|
| `planState` | has / empty | 1781-1788 | empty → `NoPlanState` (decl 1628) instead of header card + timeline | `plan-mobile-planstate-empty.jpg` |
| `raceState` | unset / set | 1756-1774 | intro copy and the link label ("set a race" vs "change your race") | `plan-mobile-racestate-set.jpg` |
| `aiReplanState` | ready / cooldown | 1739-1753 | the regenerate link becomes the inert cooldown pill | `plan-mobile-aireplanstate-cooldown.jpg` |
| `weekDaysVariant` | default / showcase | 1659, 1675 | showcase swaps week 6's days for `STATUS_SHOWCASE_DAYS`, one of every `DayStatus` | `plan-mobile-weekdaysvariant-showcase.jpg` |
| `week.status` | done / current / upcoming | 1370, 1373-1375, 1388-1391, 1400-1411 | default-open, border accent, header stat ("this week" vs a %), and whether the focus text is labelled and styled as narration |
| `hasDetail` | derived | 1348, 1368 | weeks with no day data render as a flat summary card |
| `day.status` | 6 values | 222-237, 1221-1231 | badge text/colour and bar fill; the badge is hidden for `upcoming` |
| `isRest` / `ranAnyway` | derived | 1163-1164, 1210-1219 | rest days suppress summary, badge and the whole action block; a rest day with an activity shows a leaf-coloured "ran anyway · ..." line |

### Explicitly absent

No "Season Track" 5-goal-tier module - the only tiered element is the 4-phase bar. No Pin, Block or
Delete day-action anywhere (the `Ellipsis` icon at :6 is a decorative rail glyph at 1486).

---

## 7. Race

`PS5`. Capture: `race-{se,mobile,tablet,desktop,wide}.jpg`, `race-mobile-dark.jpg`,
`race-mobile-racestate-set.jpg`, `race-mobile-projectionstate-none.jpg`,
`race-mobile-aireplanstate-cooldown.jpg`.
Source: [RaceGoalScreen.tsx](../../resources/brand/prototype/src/components/pages/RaceGoalScreen.tsx).
Props at [:419-431](../../resources/brand/prototype/src/components/pages/RaceGoalScreen.tsx).

**Chrome**: `AppTopbar` + bottom nav, **`plan` lit** ([App.tsx:386-387](../../resources/brand/prototype/src/App.tsx)).

### Sections

| # | section | line | what it draws |
|---|---|---|---|
| 1 | eyebrow "race" | 439-441 | mono uppercase label |
| 2 | heading + intro | 442-466 | h1 branches on `raceState` ("your race, on the calendar." / "give the plan something to aim at."); static intro paragraph 462-465 |
| 3 | `ScheduleRaceTabs` | 468-471 | `active="race"` |
| 4 | `RaceCard` **or** `NoRaceState` | decl 84 / 226, used 474-479 / 473 | race summary card with `StatTile`s (decl 68) for distance and goal time; or the no-race empty card |
| 5 | `ProjectionBlock` | decl 178, used 477 | wraps `ProjectionGauge` (decl 125), an SVG arc gauge showing a low / predicted / high finish-time range. Only when `raceState === 'set'` |
| 6 | `RaceGoalForm` | decl 245, used 481-488 | **always rendered.** name, race-day date, `DISTANCE_PRESETS` pills + a custom-distance input, h/m/s goal-time inputs, two warning banners, and the set/update trigger |

### Interactions

| control | line | behaviour |
|---|---|---|
| `ScheduleRaceTabs` schedule tab | 470 | `onNavigateSchedule()` |
| distance preset pills | 309-316 | `setDistanceKm`; drives the derived pace and both warnings |
| custom distance input | 320-329 | `setDistanceKm(Number(...))` |
| hours / minutes / seconds inputs | 338-366 | real state; drive `goalTimeSec` and `paceSecPerKm` |
| name input | 291-295 | **uncontrolled, dead** |
| race-day date input | 299-302 | **uncontrolled, dead** |
| "set race" / "update race" | 404-413 | `preventDefault` then `onTriggerAiReplan()`. Label follows `raceState` (412). Rendered only when `aiReplanState !== 'cooldown'` |
| `AiReplanPill` | 399-402 | inert cooldown span |

Two derived warning banners, both real: `paceIsImplausible` (263-266) shows a world-record-pace
warning (369-382); `showPersonalizedWarning` (281) shows an "ahead of your projected range" warning
(383-396) and is deliberately suppressed while `paceIsImplausible` (comment 278-280).

### At `@min-[900px]:`

One line, the root at [:438](../../resources/brand/prototype/src/components/pages/RaceGoalScreen.tsx).
760px, `px-4` → `px-6`, `pt-16` → `pt-6`, `pb-22` → `pb-24`.

### Alternate states

| prop | values | line | difference | capture |
|---|---|---|---|---|
| `raceState` | unset / set | 444/452, 286, 412, 473-479 | heading, form label ("set your race" / "edit your race"), trigger label, and whether `RaceCard` + `ProjectionBlock` or `NoRaceState` renders | `race-mobile-racestate-set.jpg` |
| `projectionState` | ready / none | 421, 432-435, 181-188 | `none` → `ProjectionBlock` renders a "no personal record yet..." placeholder instead of the gauge | `race-mobile-projectionstate-none.jpg` |
| `aiReplanState` | ready / cooldown | 399-414 | trigger link becomes the inert pill | `race-mobile-aireplanstate-cooldown.jpg` |

### Explicitly absent

No CTL/ATL fitness chart. The only chart-like element is `ProjectionGauge`, an arc gauge. The intro
copy mentions "tracks your fitness trend against it" (464) but draws no chart for it.

---

## 8. Trends

`PS6`. Capture: `trends-{se,mobile,tablet,desktop,wide}.jpg`, `trends-mobile-dark.jpg`,
`trends-mobile-regenstate-cooldown.jpg`.
Source: [TrendsScreen.tsx](../../resources/brand/prototype/src/components/pages/TrendsScreen.tsx).
Props `{ theme, regenState }` at [:290-296](../../resources/brand/prototype/src/components/pages/TrendsScreen.tsx).

**Chrome**: `AppTopbar` + bottom nav, `trends` lit ([App.tsx:423-424](../../resources/brand/prototype/src/App.tsx)).

Exactly the four blocks decision P25 names.

### Sections

| # | section | line | what it draws |
|---|---|---|---|
| 1 | header | 302-312 | eyebrow "trends"; serif h1 "how things / are going." with the second line italic-accented; copy "a year of running, read as lines rather than a list." |
| 2 | range tab bar | 314-330 | 3 pill buttons from `RANGES` - 30 days / 90 days / 12 months |
| 3 | `NarrationCard` | decl 146, used 332 | "temari's read" eyebrow + `Sparkles` (151-154); bold italic headline; an italic paragraph; a bottom-right regenerate control |
| 4 | `FitnessPanel` | decl 183, used 334 | see below |
| 4a | header + copy | 189-199 | eyebrow "fitness"; headline "climbing, not spiking."; a CTL/ATL/form explainer |
| 4b | stat tiles | 201-215 | 3-column grid from `STAT_ROW` (35-39): 62 fitness / 58 fatigue / +4 form |
| 4c | `FitnessChart` | decl 93, used 217 | 150px Chart.js line chart: a **solid** 90-day CTL series and a **dashed** ATL series from `genFitnessSeries(90)` (63-76). Axes, legend and tooltip are off; only a 4-tick Y axis shows |
| 4d | legend | 219-228 | hand-built, not Chart.js: a solid swatch "fitness" and a dashed swatch "fatigue" |
| 4e | badge chips | 230-265 | one button per `BADGES` entry (41-60) - **3 hardcoded**: `first-10k` (rare), `hm-pr` (epic), `streak-6` (uncommon) - each a `Medal` icon tinted by rarity plus a label |
| 4f | badge detail panel | 267-285 | conditional; the selected badge's icon, label and detail text |

### Interactions

| control | line | behaviour |
|---|---|---|
| range tabs | 315-329 | `setRange(r)` is real state (297) and restyles the active pill, but **`range` is read nowhere else** - the chart, stats and badges do not change. Decision P3 makes these genuinely refetch |
| regenerate | 163-178 | `cooldown` → an inert `cursor-not-allowed` span "next read in 4h 12m" (165-168); `ready` → a `<button>` (170-176) with **no `onClick`** |
| badge chips | 234-263 | real toggle on `openBadge` (184); opens the detail panel and tints the chip by rarity; clicking the same chip closes it |

### At `@min-[900px]:`

One line, the root at [:301](../../resources/brand/prototype/src/components/pages/TrendsScreen.tsx).
760px, `px-4` → `px-6`, `pt-16` → `pt-6`, `pb-22` → `pb-24`.

### Alternate states

| prop / state | values | line | difference | capture |
|---|---|---|---|---|
| `regenState` | ready / cooldown | 148, 164-177 | regenerate button becomes the cooldown span | `trends-mobile-regenstate-cooldown.jpg` |
| `theme` | light / dark / system | 294, 78-91 | `useIsChartDark` picks `CHART_PALETTE.dark` or `.light` (95), changing line, tick and grid colours (102, 110, 127, 129). `system` follows `prefers-color-scheme` live | `trends-mobile-dark.jpg` |
| `openBadge` | null / a key | 184, 267-285 | the badge detail panel |

### Explicitly absent

No milestones section. No badge board (the chip row lives inside `FitnessPanel`, it is not a
standalone board). No strain or monotony metric - `STAT_ROW` is only fitness/fatigue/form. No VDOT
and no pace-history chart. No personal-bests table; the only PR reference is the single `hm-pr`
badge label (50).

---

## 9. History

`PS7`. Capture: `history-{se,mobile,tablet,desktop,wide}.jpg`, `history-mobile-dark.jpg`,
`history-mobile-historystate-partial.jpg`, `history-mobile-historystate-empty.jpg`,
`history-mobile-tab-calendar.jpg`.
Source: [HistoryScreen.tsx](../../resources/brand/prototype/src/components/pages/HistoryScreen.tsx).
Props `{ historyState }` at [:746-748](../../resources/brand/prototype/src/components/pages/HistoryScreen.tsx).

**Chrome**: `AppTopbar` + bottom nav, `history` lit ([App.tsx:460-461](../../resources/brand/prototype/src/App.tsx)).

### Sections

| # | section | line | what it draws |
|---|---|---|---|
| 1 | eyebrow | 753-755 | "history · 42 activities" |
| 2 | h1 | 756-760 | "every run / has a story." |
| 3 | tab nav | 762-787 | two buttons, feed / calendar |
| 4 | tab content | 789-793 | `FeedView` (decl 531) or `CalendarView` (decl 669) |

**Feed view** (536-565): `NoRunsCard` (decl 509) alone when empty; otherwise `WeekSection`
(decl 485) for the current week (only when `populated`), `WeekSection` for last week, a "load older
weeks" reveal button, and `WeekSection` for the older week once revealed. Each `WeekSection` draws a
title/meta row (491-498), a `RecapCard` (decl 381, used 499) and a card of `RunRow`s (decl 438,
500-504). `RecapCard` holds a `FaceIcon`, an italic narration line, mood-tagged chips and a
`BellPlus` button (392-435). `RunRow` shows a `Sparkle` tinted by rarity when `run.rarity` is set
(453-459).

**Calendar view** (674-744): a prev/label/next month row (676-696); month meta (699-701); a
month-scoped `RecapCard size="month"` (703-708); a `MOOD_LEGEND` dot row (710-723); a weekday header
(725-737); then one `WeekRow` (decl 567) per week (739-741). A `WeekRow` is a 30px week cell button
(573-610: week key, km total, mood dot, chevron) plus 7 day boxes (611-633: day number and an
optional mood dot). When expanded and the week has narration, it reveals a disclosure panel
(635-664) with the italic line, chips and - **only here** - the kartu badge (649-661).

### Interactions

| control | line | behaviour |
|---|---|---|
| feed / calendar tabs | 763-786 | `setTab`; real |
| "load older weeks" | 549-556 | `setOlderRevealed(true)`. One-way: the button is gated on `!olderRevealed` (547) and never comes back. Decision P3 makes this genuinely page |
| `RecapCard` bell button | 426-431 | **dead** - a `<button>` with no `onClick` |
| prev month | 676-684 | `setMonth('jul')`; `disabled` while already at the earliest month (202) |
| next month | 688-696 | `setMonth('aug')`; `disabled` at the latest month (304) |
| week-row expand | 574-610 | `setExpanded(e => !e)`; `disabled` when the week has no narration (569), which dims it (580). Chevron rotates (600-606) |
| day cells | 611-633 | plain `<div>`s, **not interactive** |

### At `@min-[900px]:`

One line, the root at [:752](../../resources/brand/prototype/src/components/pages/HistoryScreen.tsx).
760px, `px-4` → `px-6`, `pt-16` → `pt-6`, `pb-22` → `pb-24`.

### Alternate states

| prop / state | values | line | difference | capture |
|---|---|---|---|---|
| `historyState` | populated / partial / empty | 748, 533, 536-544 | `empty` → `NoRunsCard` only; `populated` → adds the current-week section. **`'partial'` has no explicit branch** - it falls through and renders as `populated` minus the current week | `history-mobile-historystate-empty.jpg`, `history-mobile-historystate-partial.jpg` |
| `tab` | feed / calendar | 749, 789-793 | which view renders | `history-mobile-tab-calendar.jpg` |
| `olderRevealed` | bool | 534, 547-562 | reveal button vs the older week section |
| `month` | aug / jul | 670-671 | which hardcoded month the calendar shows |
| `expanded` | bool, per row | 568, 635-664 | the week narration panel |
| `size` on `RecapCard` | week / month | 385, 396, 410 | padding and body type size |


---

## 10. Activity detail

`PS8`. Capture: `activity-{se,mobile,tablet,desktop,wide}.jpg`, `activity-mobile-dark.jpg`,
`activity-mobile-awaitingdetail-hydrating.jpg`, `activity-mobile-pastyoustate-none.jpg`,
`activity-mobile-rereadstate-cooldown.jpg`.
Source: [ActivityDetailScreen.tsx](../../resources/brand/prototype/src/components/pages/ActivityDetailScreen.tsx).
Props at [:1020-1028](../../resources/brand/prototype/src/components/pages/ActivityDetailScreen.tsx).

**Chrome**: `ActivityTopbar` (back chevron only), **no bottom nav**
([App.tsx:542-543](../../resources/brand/prototype/src/App.tsx)).

The screen `V0` misread. Everything below the fold is real.

### Sections

| # | section | line | what it draws |
|---|---|---|---|
| 1 | eyebrow "activity" | 1031-1033 | mono uppercase label |
| 2 | `HydratingNotice` | decl 154, used 1035-1037 | conditional; explains partial data |
| 3 | `HeroPanel` | decl 412, used 1039 | `FaceIcon`, date/time, title, mood chip, the headline distance stat, two supporting stats, three `SecondaryStatTile`s (decl 193) for HR / TRIMP / elevation, and an embedded `MapWeatherPanel` (477) |
| 3a | `MapWeatherPanel` | decl 221 | a **hand-built SVG placeholder** - grid lines, a fake route path, two circles (225-339) - overlaid with an "activate map" pill (340-342), then a weather strip. No map library, no tiles, no geo data. Decision P16 puts the real Leaflet map in this slot |
| 4 | `PastYouCard` | decl 369, used 1041-1043 | conditional; the "you vs past you" comparison with a "view that run" link |
| 5 | `RunLenses` | decl 583, used 1047 | the "what temari says" narration card: a run story, a "what stood out" claims list, and the reread control |
| 6 | `AskAboutRun` | decl 516, used 1048 | the Q&A panel: a list of prior `QuestionRow`s (decl 482), suggestion chips, an input and a submit |
| 7 | eyebrow "the breakdown" | 1050-1052 | section header |
| 8 | `VitalsCard` | decl 675, used 1053 | HR avg (687-689) and max (695-697) on a scale bar (703-712); cadence (720-725); steepest grade (732-737); flat pace per km (744-749); decoupling on a gradient bar (758-770) |
| 9 | `SplitsChartCard` | decl 784, used 1054 | per-km bar chart with an HR polyline overlay and a tap-to-reveal tooltip |
| 10 | `LapsCarousel` | decl 972, used 1055 | horizontally scrollable lap cards |
| 11 | footer | 1059-1061 | static caption "synced from strava · 19 feb 2026 · 07:15 · #4821" |

Sections 5-10 are all gated on `awaitingDetail === 'ready'` (1045-1057).

### Interactions

| control | line | behaviour |
|---|---|---|
| "check again" (hydrating, `stopped` only) | 180-186 | dead `<button>`. The call site hardcodes `stopped={false}` (1036), so this branch is unreachable as composed |
| "view that run" | 382-388 | dead `href="#"` |
| suggestion chips | 543-551 | `setDraft(s)` - real; fills the input |
| question input | 558-566 | controlled on `draft` (560-561) |
| "ask" | 567-574 | `type="submit"`, `disabled` under 3 characters (569). The form's `onSubmit` only calls `preventDefault` (555) - the disabled gating is real, the ask is a no-op |
| "ask it again" (failed question) | 504-509 | dead `<button>` |
| "reread" | 653-661 | dead `<button>`; the cooldown branch (648-652) is an inert span "next in 3h 05m" |
| split bars | 890-950 | `handleBarClick` (818-833) - real state `tip` (805); toggles a tooltip with pace/HR/cadence, clears on a second click of the same bar (825-827), position clamped in a `useLayoutEffect` (807-816); unselected bars dim to `opacity-40` |
| laps carousel | 978 | native `overflow-x-auto` scroll with a hidden scrollbar. **No paging buttons** |

### At `@min-[900px]:`

One line, the root at [:1030](../../resources/brand/prototype/src/components/pages/ActivityDetailScreen.tsx).
760px, `px-4` → `px-6`, `pt-16` → `pt-6`, `pb-7` → `pb-14`. Nothing inside reflows - the hero, the
splits chart and the laps carousel keep their mobile shape at every width.

### Alternate states

| prop / state | values | line | difference | capture |
|---|---|---|---|---|
| `awaitingDetail` | ready / hydrating | 1025, 1035-1057 | `hydrating` → `HydratingNotice` renders and **sections 4-10 do not render at all** | `activity-mobile-awaitingdetail-hydrating.jpg` |
| `pastYouState` | match / none | 1026, 1041 | `none` → `PastYouCard` is simply absent; there is no empty-state markup for it | `activity-mobile-pastyoustate-none.jpg` |
| `rereadState` | ready / cooldown | 1027, 648-661 | reread button becomes the cooldown span | `activity-mobile-rereadstate-cooldown.jpg` |
| `item.status` per question | done / pending / failed | 109-123, 488-511 | italic answer; spinner + "thinking about it."; or a destructive-toned message with "ask it again" |
| `tip` | null / a split | 805, 856-870 | the splits tooltip, and dimming of the other bars |

### Explicitly absent

No achievement / collectible / kartu block. No "why this earned Common" rarity explainer. No
"Resync from Strava" or "Send notification" utility row - the only Strava reference is the
non-interactive footer caption. **No share button of any kind**: the file imports no share icon and
contains no "share" string.

---

## 11. Inbox

`PS9`. Capture: `inbox-{se,mobile,tablet,desktop,wide}.jpg`, `inbox-mobile-dark.jpg`,
`inbox-mobile-inboxstate-empty.jpg`.
Source: [InboxScreen.tsx](../../resources/brand/prototype/src/components/pages/InboxScreen.tsx).
Props `{ inboxState }` at [:283-285](../../resources/brand/prototype/src/components/pages/InboxScreen.tsx).

**Chrome**: `InboxTopbar` (back chevron only), **no bottom nav**
([App.tsx:575](../../resources/brand/prototype/src/App.tsx)).

### Sections

| # | section | line | what it draws |
|---|---|---|---|
| 1 | eyebrow | 292-296 | "inbox · N unread", or plain "inbox" when there are none |
| 2 | h1 | 297-301 | "everything i told you, still here." |
| 3 | body | 303-341 | `EmptyInboxCard` (decl 261) or the populated branch |
| 3a | buckets | 307-326 | `BUCKET_ORDER` = today / week / earlier; a bucket label (316-318) then its `InboxRow`s (319-323). **Empty buckets are skipped entirely** (311-313) |
| 3b | "load older" | 328-339 | shown only while `!olderRevealed` |

`InboxRow` (decl 154, body 158-257): a kind icon chip (168-176); a header row carrying either a
rarity badge or the kind label (180-204) plus a timestamp toggle (205-211); the title `h2` (214-216);
optional stat chips (218-232); the body paragraph (234-236); an optional "open" link (238-246); and
an unread dot (249-255, `role="status"`, not interactive).

`InboxItem.kind` ([:17-22](../../resources/brand/prototype/src/components/pages/InboxScreen.tsx))
has **one** `'unlock'` variant, not separate badge and kartu variants. The rarity badge renders
whenever `kind === 'unlock' && item.rarity` is set (180-199). The single sample unlock item (91-100)
is a badge unlock, `rarity: 'uncommon'`.

### Interactions

| control | line | behaviour |
|---|---|---|
| timestamp toggle, per row | 205-211 | `showAbsolute` (156) - real; flips relative ↔ absolute time |
| "open" link | 239-245 | dead `href="#"` |
| "load older" | 330-337 | `setOlderRevealed(true)` (286) - real; reveals `OLDER_ITEMS` and hides itself. One-way |

### At `@min-[900px]:`

One line, the root at [:291](../../resources/brand/prototype/src/components/pages/InboxScreen.tsx).
760px, `px-4` → `px-6`, `pt-16` → `pt-6`, `pb-7` → `pb-24`.

### Alternate states

`inboxState` (285). `'empty'` → `EmptyInboxCard`: a `FaceIcon`, "nothing here yet." and body copy
(263-279); it also flattens the eyebrow to plain "inbox" (293-295). Capture
`inbox-mobile-inboxstate-empty.jpg`.

---

## 12. Profile

`PS10`. Capture: `profile-{se,mobile,tablet,desktop,wide}.jpg`, `profile-mobile-dark.jpg`,
`profile-mobile-racestate-set.jpg`, `profile-mobile-planstate-empty.jpg`.
Source: [ProfileScreen.tsx](../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx).
Props at [:527-535](../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx).

**Chrome**: `ProfileTopbar` (back chevron; gear then bell), **no bottom nav**
([App.tsx:612-614](../../resources/brand/prototype/src/App.tsx)). The gear is the only route to
Settings (decision P7).

### Sections

| # | section | line | what it draws |
|---|---|---|---|
| 1 | eyebrow "profile" | 538-540 | mono uppercase label |
| 2 | header row | 541-553 | h1 "nuki, your story." beside an avatar circle "N" |
| 3 | `HeroPanel` | decl 64, used 555 | see below |
| 4 | `HasRaceCard` / `NoRaceCard` | decl 189 / 166, used 557-561 | race summary with distance, date and a days countdown; or the no-race prompt |
| 5 | `SeasonCard` | decl 222, used 563 | "season" label (225-227); a `PHASES` bar (244-269); one progress bar (270-275); one progress line "62% to sub-50 10K" (276-278) |
| 6 | `PaceCard` | decl 285, used 564 | a gradient pace-target bar with 4 `PACE_MARKERS` dots and labels |
| 7 | `ProgressionCard` | decl 486, used 565 | `DIST_TABS` pills (489-503); "journey · 10K" label (504-506); a then/now time headline (507-510); a quote (511-513); stat chips (514-521); `JourneyChart` (decl 330, used 522) |

`HeroPanel` (64-164): a decorative radial blob (66-73); a header with `FaceIcon`, the "what temari
says about you" eyebrow and the est. date, plus the desktop-only "with temari since" block
(100-107); an italic narrative paragraph (110-113); **the Z1-Z5 time-in-zone bar** - label "time in
zone · last 12 weeks" (115-116), a segmented bar over `ZONES` (15-21, rendered 118-126) and a legend
of coloured dots with percentages (127-140); a divider (142); and a horizontally scrolling row of
five `STATS` tiles (23-29, rendered 143-161) - total km, total runs, longest run, VDOT, threshold
pace.

`JourneyChart` (330-484) is an SVG polyline/area chart over `JOURNEY_POINTS` with clickable point
markers (430-458) that toggle a tooltip (471-481); an outside click closes it (341-351).

### Interactions

| control | line | behaviour |
|---|---|---|
| `NoRaceCard` link | 170-186 | `preventDefault` then `onNavigateRace()` |
| `HasRaceCard` link | 193-219 | same |
| `SeasonCard` "start one on plan" | 231-237 | dead `href="#"` |
| `JourneyChart` point markers | 430-458 | `handlePointClick` (364-386) - real `tip` state (333-339) |
| `DIST_TABS` pills | 489-503 | plain `<span>`s, **not buttons**. "10K" is hardcoded active (495-498). Decision P3 makes these real |

### At `@min-[900px]:`

Two lines. Root [:537](../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx)
(760px, `px-4` → `px-6`, `pt-16` → `pt-6`, `pb-7` → `pb-22`) and
[:100](../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx) - the
`ml-auto hidden flex-none text-right` "with temari since / 12 jun 2026" block flips to `block`. This
is the prototype's only visibility-toggled element and it is visible in `profile-desktop.jpg` and
`profile-wide.jpg`, absent from `profile-mobile.jpg` and `profile-tablet.jpg`.

### Alternate states

| prop / state | values | line | difference | capture |
|---|---|---|---|---|
| `raceState` | unset / set | 532, 557-561 | `HasRaceCard` vs `NoRaceCard` | `profile-mobile-racestate-set.jpg` |
| `planState` | has / empty | 533, 228 | `SeasonCard` shows a "no season yet" CTA instead of the phase bar and progress line | `profile-mobile-planstate-empty.jpg` |
| `tip` | null / a point | 333-339 | the journey-chart tooltip |

### Explicitly absent

No behavioural persona mix. No five-row season/streak panel - the only five-item list is the `STATS`
tile row inside `HeroPanel`, which is not season or streak content.

---

## 13. Settings

`PS11`. Capture: `settings-{se,mobile,tablet,desktop,wide}.jpg`, `settings-mobile-dark.jpg`,
`settings-mobile-tgstate-connected.jpg`, `settings-mobile-zonesource-default.jpg`,
`settings-mobile-zonesource-manual.jpg`, `settings-mobile-aireplanstate-cooldown.jpg`.
Source: [SettingsScreen.tsx](../../resources/brand/prototype/src/components/pages/SettingsScreen.tsx).
Props at [:599-611](../../resources/brand/prototype/src/components/pages/SettingsScreen.tsx).

**Chrome**: `SettingsTopbar` (back chevron; bell, no gear), **no bottom nav**
([App.tsx:700-702](../../resources/brand/prototype/src/App.tsx)).

### Sections

| # | section | line | what it draws |
|---|---|---|---|
| 1 | eyebrow "settings" | 614-616 | mono uppercase label |
| 2 | h1 | 617-621 | "tune it your way." |
| 3 | "appearance" + `AppearanceCard` | 623-626, decl 152 | a "theme" label and a `ToggleGroup` of **three** items: light (168-174), dark (175-181), auto (182-188) |
| 4 | "notifications" + `NotificationsCard` | 628-631, decl 194 | "what gets sent" with a "keep me posted" row and toggle (199-213); a divider (215); "where it goes" (217-267) branching on `tgState`; a push row with a toggle (269-283); a "send test notification" button (285-290) |
| 5 | "running" + `TrainingPreferencesCard` | 633-639, decl 295 | experience level, 3 `IconChoiceCard`s (337-353); sessions per week, a `SessionsDial` (355-364); training goal, 4 `IconChoiceCard`s (366-382); usual run days, a `DayRow` of `DayCell`s (384-401); "which one's the long run?", a second `DayRow` limited to the selected days (403-422); then the AI-replan control (424-437) |
| 6 | `ZonesDisclosure` | 640, decl 442 | a `Collapsible`. Trigger (454-471) shows a summary line keyed on `zoneSource`. Content (472-538): max/resting HR inputs (476-489), an "auto-calculate" button (491-496), per-zone bpm inputs over `ZONE_BOUNDS` (44-50, rendered 498-513), "save zones" / "reset to default" (516-527), and a "resync from strava" button that renders only when `zoneSource === 'manual'` (529-537) |
| 7 | "how temari uses your data" + `DataUseCard` | 642-645, decl 543 | a bullet list of `DATA_USE_ITEMS` |
| 8 | "the fine print" + `LegalCard` | 647-650, decl 555 | 4 links from `LEGAL_LINKS` (58-63): terms of use, privacy policy, how temari uses ai, training disclaimer |
| 9 | "account" + `AccountActions` | 652-655, decl 579 | "log out" (582-588) and "delete account" (589-594) |

`DayCell`, `DayRow`, `IconChoiceCard` and `SessionsDial` come from
[PreferenceControls.tsx](../../resources/brand/prototype/src/components/pages/PreferenceControls.tsx)
(imported 37-42) - the same four controls Onboarding uses. All four are stateless: visual state is
prop-driven, `onClick` is a passed-in callback.

### Interactions

| control | line | behaviour |
|---|---|---|
| `SettingsToggle` ×3 | decl 125, used 212 / 258 / 282 | real local `on` state (129), flipped on click (136) |
| appearance `ToggleGroup` | 161-189 | real local `value` (155) via `onValueChange` (162-163); purely local, nothing is called |
| telegram connect link | 222-242 | dead `href="#"` |
| "disconnect telegram" | 260-265 | dead `<button>` |
| "send test notification" | 285-290 | dead `<button>` |
| experience `IconChoiceCard`s | 342-350 | `setExperience` - real (302-303) |
| `SessionsDial` | 359-363 | `setSessions` - real (304) |
| goal `IconChoiceCard`s | 371-379 | `setGoal` - real (305-306) |
| run-day `DayCell`s | 389-397 | `toggleDay` (316-326) - real; capped at the `sessions` count (321-323) |
| long-run `DayCell`s | 409-416 | `setLongRunDay` (415) - real; unselected days render as plain non-interactive `div`s (417-419) |
| "save changes" | 429-436 | `onTriggerAiReplan()` - the only prop callback on the screen |
| `AiReplanPill` | 426 | inert cooldown span |
| `ZonesDisclosure` trigger | 454-471 | real toggle. No `defaultOpen`, so **closed by default** |
| max / resting HR inputs | 476-489 | `defaultValue` only - **uncontrolled, dead** |
| "auto-calculate" | 491-496 | dead `<button>` |
| per-zone bpm inputs | 507-510 | `defaultValue` only - **uncontrolled, dead** |
| "save zones" / "reset to default" | 516-527 | dead `<button>`s |
| "resync from strava" | 530-536 | dead `<button>`; renders only under `zoneSource === 'manual'` |
| 4 legal links | 559-573 | dead `href="#"` |
| "log out" / "delete account" | 582-594 | dead `<button>`s |

### At `@min-[900px]:`

Four lines, ten utility instances - the most of any screen bar Login. Root
[:613](../../resources/brand/prototype/src/components/pages/SettingsScreen.tsx) (760px,
`px-4` → `px-6`, `pt-16` → `pt-6`, `pb-7` → `pb-22`); the zones bounds grid at
[:473](../../resources/brand/prototype/src/components/pages/SettingsScreen.tsx)
(`grid-cols-2` → `grid-cols-4`); and `AccountActions` at
[:581](../../resources/brand/prototype/src/components/pages/SettingsScreen.tsx) /
[:584](../../resources/brand/prototype/src/components/pages/SettingsScreen.tsx)
(stacked full-width buttons → a centred row of auto-width buttons).

### Alternate states

| prop | values | line | difference | capture |
|---|---|---|---|---|
| `tgState` | unset / connected | 606, 196, 221 | `unset` → a connect link (222-242); `connected` → the telegram row with its toggle plus a "disconnect telegram" button (244-267) | `settings-mobile-tgstate-connected.jpg` |
| `zoneSource` | default / strava / manual | 607, 444-450, 529 | changes the disclosure's summary line, and only `manual` shows "resync from strava" | `settings-mobile-zonesource-default.jpg`, `settings-mobile-zonesource-manual.jpg`; the light pass captures `strava` |
| `appearance` | light / dark / auto | 608, 626, 154 | passed as `defaultValue` only - it seeds the toggle group, it does not branch content |
| `aiReplanState` | ready / cooldown | 609, 299, 424 | "save changes" becomes `AiReplanPill` | `settings-mobile-aireplanstate-cooldown.jpg` |

---

## 14. Capture index

Full-scroll heights, light pass, in device pixels. The frame is 844px tall at `mobile`; every
capture above that is proof the pass scrolled rather than screenshotting the viewport.

| screen | se (292w) | mobile (362w) | tablet (806w) | desktop (1280w) | wide (1536w) |
|---|---|---|---|---|---|
| login | 1495 | 1350 | 1265 | 1260 | 1260 |
| onboarding | 679 | 623 | 552 | 628 | 628 |
| today | 1301 | 1239 | 1205 | 1117 | 1117 |
| plan | 8187 | **7445** | 7185 | 7170 | 7170 |
| race | 1064 | 988 | 969 | 937 | 937 |
| trends | 1137 | 1082 | 977 | 945 | 945 |
| history | 1658 | 1568 | 1472 | 1440 | 1440 |
| activity | 2690 | **2483** | 2345 | 2333 | 2333 |
| inbox | 1348 | 1197 | 1107 | 1135 | 1135 |
| profile | 1262 | 1201 | 1167 | 1187 | 1187 |
| settings | 2927 | **2760** | 2687 | 2673 | 2673 |

Alternate-state capture heights are recorded in `_heights.json` alongside the images.

Disclosure note: the light pass renders every screen in its **default** disclosure state. Today's
"this week's stats", Login's "data & AI use", Settings' `ZonesDisclosure` and every Plan day row are
closed by default, so their contents are not in the light-pass captures. Plan's current week *is*
open by default (`defaultOpen={week.status === 'current'}`), which is most of why `plan-mobile` is
7445px tall.

---

## 15. Cross-check against `cut-list.md`

Every row of [cut-list.md](cut-list.md) §1 and §2 was verified against the prototype source. All but
five are confirmed exactly as written. The five below are recorded as **findings only** - `PP0`
changed nothing in the cut list, and no slice should act on them without the user's call.

| # | row | what the cut list says | what the prototype does |
|---|---|---|---|
| 1 | §1 `TemariProto` mascot system | `FaceIcon` replaces it "everywhere"; README P10 says "9 of 11 screens" | `FaceIcon` is imported and rendered on **8** of 11: Today, Plan, Race, History, Activity, Inbox, Profile, Onboarding. It is absent from Login, Trends and Settings |
| 2 | §2 Kartu generation + share | "a share button on activity detail opens the share-card popup. That is the only way to view a card" | The prototype draws **no share button anywhere**, and no dialog/modal/popup component exists in the tree at all. The row's three cited evidences (Login teaser, History kartu badge, Inbox unlock rows) are all correct; the share affordance is a decision with no prototype backing |
| 3 | §2 Today's weekly stats block | "Stays a disclosure, open by default per the original `V0` fork 4" | The prototype's `Collapsible` at [TodayScreen.tsx:464](../../resources/brand/prototype/src/components/pages/TodayScreen.tsx) passes no `defaultOpen`, and `@base-ui/react`'s `Collapsible.Root` defaults to closed. The prototype renders it **closed**. Open-by-default is a divergence, and the row reads as if it were parity |
| 4 | §1 Trends cuts | lists "VDOT/pace history" and "personal bests" among the cut | Accurate for Trends. But VDOT survives on Profile as a `STATS` tile ([ProfileScreen.tsx:27](../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx)) and `ProgressionCard` ([:486](../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx)) is a per-distance PR journey chart. The row is right as scoped and misleading if read as app-wide |
| 5 | README P5 | "four wide-only layouts" | Eleven, per §1.2 above. The four named are real; seven more are not named |

Two further nuances, not discrepancies:

- §2's "History calendar's kartu badge" is correct, but the badge sits inside a week row's
  **expanded narration disclosure** ([HistoryScreen.tsx:649-661](../../resources/brand/prototype/src/components/pages/HistoryScreen.tsx)),
  not on a calendar day cell. Day cells draw only a day number and an optional mood dot (611-633).
- §1's "Desktop `TopNav`" row is confirmed: the only in-frame chrome is `AppTopbar`, which has no
  container query. The page-picker nav at [App.tsx:100-128](../../resources/brand/prototype/src/App.tsx)
  and [:708-734](../../resources/brand/prototype/src/App.tsx) is the gallery's own, outside the
  device frame.

Everything else in §1 and §2 is confirmed: no card reveal, no featured-kartu panel, no persona mix
(a Z1-Z5 zone bar in that slot instead), no dawn-shift or any time-of-day palette anywhere, no
relative effort, no toast or unlock modal, no rarity explainer, no resync/notify row, no `MeTabs`
equivalent, no Race fitness chart, no Season Track tier module, no five-row season/streak panel, no
day-grained streak line, no chrome on Login or Onboarding, and no Pin/Block/Delete day action.
