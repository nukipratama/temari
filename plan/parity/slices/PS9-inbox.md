# PS9 — Inbox

Inbox (`pages/Inbox.tsx`) to prototype parity, against
[`InboxScreen.tsx`](../../../resources/brand/prototype/src/components/pages/InboxScreen.tsx) as the
source of truth and [reference.md](../reference.md) §11 as the cross-check.

Decisions in scope: **P14** (badges surface in exactly two places; Inbox's unlock rows are one),
**P15** (a badge surface shows everything earned, nothing truncates), **P12** (unlock rows carry
rarity badges), **P36** (card radius `rounded-md`), **P3** (implement what the prototype wires to
nothing), plus P1/P2/P5/P6/P7/P33 which apply everywhere. **P10**: Inbox is one of the eight screens
the prototype draws with a `FaceIcon` — at `InboxScreen.tsx:264`, inside `EmptyInboxCard`, and
nowhere else on the screen.

## Goal

What `InboxScreen.tsx` draws, in its order, at its treatment, populated with real data:

1. **eyebrow** — "Inbox · N unread", flattening to plain "Inbox" when nothing is unread (`:292-296`).
2. **h1** — "everything i told you, / still here.", the second line accent-italic (`:297-301`).
3. **body** — either `EmptyInboxCard` or the populated branch (`:303-341`).
4. **buckets** — today / this week / earlier in that fixed order, a bucket label then its rows,
   **empty buckets skipped entirely** (`:307-326`).
5. **"load older"** — a centred pill, shown only while more history exists (`:328-339`).

`InboxRow` (`:154-259`) carries: a kind icon chip; a header row with either a rarity badge (unlock)
or the kind label, plus a timestamp that toggles relative ↔ absolute; the title; optional stat chips;
the body; an optional "open" link; and an unread dot.

## Files touched

| area | what |
|---|---|
| `resources/js/pages/Inbox.tsx` | the prototype's eyebrow (no "on this page" hedge — the count is the shared `unreadNotifications` prop now), accent second headline line, the prototype's bucket/row spacing, the Newer/Older pager replaced by P3's real "load older", the page-level framer-motion stagger dropped (the prototype draws none and no sibling ported screen kept one) |
| `resources/js/components/inbox/InboxRow.tsx` | `padding="panel"` → the prototype's `p-4`, the rarity badge re-toned onto `PS6`'s chip treatment, post-run stat chips added, unread dot to `bg-icon-accent` as the prototype draws it |
| `app/Http/Controllers/InboxController.php` | the paginator becomes a `?shown=` growing window with a `hasOlder` flag; unlock rows resolve `rarity` from the unlock catalog; post-run rows carry `distance_m` / `moving_time_s` |
| `resources/js/types/inertia.ts` | `InboxItem` gains `distance_m` / `moving_time_s`; `PaginatedResponse` deleted (Inbox was its only consumer) |
| co-located `*.test.tsx` / `InboxControllerTest.php` | one per changed file, per the 1:1 convention |
| `docs/features/notification-inbox.md`, `docs/features/targets-accessories.md`, `docs/features/gamification.md` | kept true in the same PR |
| `plan/parity/README.md` | the `PS9` progress row; the `PS12` row gains Inbox's data point on the empty-state shape |

## Blockers

None. `PP1` (the 900px/760px column, the pushed-screen chrome and `BACK_TARGETS['Inbox']`), `PP2`
(`FaceIcon`) and `PP3` (P14's cuts) all landed first.

## Acceptance criteria

- [x] The page renders exactly the prototype's sections, in its order.
- [x] The eyebrow reads "Inbox · N unread" against a true account-wide count, or plain "Inbox".
- [x] Empty buckets are skipped; bucket order is fixed today → this week → earlier.
- [x] P3: "load older" really pages — it re-requests with a wider `?shown=` and hides itself once
      the history is exhausted. No Newer/Older pager survives.
- [x] P12: an unlock row draws a rarity badge fed by real data rather than a branch nothing reaches.
- [x] P14: unlock rows are the only badge surface on this screen; no toast, no unlock modal, no
      gallery — verified absent, not redone.
- [x] P15: every unlock ever granted is reachable; nothing truncates to a fixed count.
- [x] P6/P33: back chevron, no bottom nav, back goes to Today.
- [x] P10: the only `FaceIcon` on the screen is the empty state's.
- [x] P36: every card surface is `rounded-md`, via `cardVariants`.
- [x] Coverage delta recorded, measured at both ends against `e8b3393f`.
- [x] `./vendor/bin/sail composer check` green on the final tree.

## Coverage delta

Measured against `epic/mobile-ux-port@e8b3393f` by checking that commit out **in this worktree** and
running `npm run test:coverage` on it, rather than trusting a sibling slice's recorded figure.

| | before (e8b3393f) | after |
|---|---|---|
| statements | 97.55% | 97.55% |
| branches | 91.39% | **91.43%** |
| functions | 97.33% | 97.33% |
| lines | 97.76% | 97.76% |

Flat on three axes, branches up 0.04. The file count is unchanged — the stat chips live inline in
`InboxRow` rather than as a new component, since they have exactly one call site. Well clear of the
95% gate. Test count 1823 → 1828.

`Inbox.tsx` chunk: **6.28 kB raw / 2.65 kB gzipped**. Inbox carries no `ROUTE_BUDGETS_KB` entry
(`check:chunks` budgets only Login, Home, Runs/Show and Profile); the guard passed unchanged, all
four budgeted routes inside their limits.

## Verification notes

**`reference.md` §11 is accurate throughout.** Every line citation, the section table, the
interaction table and the alternate-state note check out against `InboxScreen.tsx`. So does its
reflow claim: the file contains exactly **one** `@min-[900px]` occurrence, the root at `:291`
(`px-4`→`px-6`, `pt-16`→`pt-6`, `pb-7`→`pb-24`, 760px), and nothing inside the screen reflows.
`PP1`'s §1.2 table lists no Inbox row, correctly — `PageContainer` and `AppShell`'s
`min-[900px]:pt-6` already carry the whole of it. This is the second §-level cross-check to come
back clean (`PS7` verified §9).

**P14's cuts verified, not redone.** No `UnlockToast`, `AccessoryUnlockModal`, badge gallery or
"why this earned X" survives anywhere under `resources/js`, and `route:list` has no `badge`,
`unlock`, `accessor`, `collection` or `card` URI at all. Unlike `PS6`'s finding on Trends, `PP3`
left nothing standing here.

**P12's rarity badge was live code with dead data — this slice is what makes it render.** See the
discrepancy section below. Confirmed end-to-end on the demo account: 21 of its 23 inbox rows are
unlocks and every one now resolves a tier (`common` … `legendary`), and the post-run row ships
`distance_m=6700 / moving_time_s=2767` for its stat chips.

**Text tiers follow the app, not the prototype's literals.** The prototype puts the row body, the
timestamp button and the neutral kind chip all on `text-foreground`; the app's documented three-tier
system puts a timestamp on `text-text-3` and supporting body on `text-text-2`, and eleven other
sites already read that way. Applied uniformly rather than cherry-picked, as `cut-list.md` §4's
"token-nearest, not literal" row does for sizing. The one colour that *did* move is the unread dot,
`bg-horizon` → `bg-icon-accent`, which is a fill and the prototype's own token name.

**`grounds.json` needed no edit.** The only new `bg-*` utilities are `bg-muted` (registered paper)
and `bg-icon-accent` (registered fill); `DesignTokenContrastTest` is green unchanged.

**Gate**: `./vendor/bin/sail composer check` green in one run — pint, eslint, the palette guard,
phpstan (0 errors), rector (0 changed files), the full `pest --parallel` suite, `tsc`, `vitest`,
build and `check:chunks`. The first baseline coverage run hit
the known contention flake (2 timeouts in files this slice never touches, `PushNotificationToggle`
and one other); a clean re-run passed 1823/1823, which is the figure recorded above.

## Plan / prototype discrepancies found

1. **P15's Inbox half has no surface to apply to, and the plan does not say so.** P15 is written
   about *badge chips* ("the prototype hardcodes three ... the row wraps rather than truncating"),
   and after its 2026-09-01 correction it names `PS6` and `PS9` as its two owners. `InboxScreen.tsx`
   draws **no chip row of any kind**: its badge surface is the unlock *notification row*
   (`:91-100`, one sample, `rarity: 'uncommon'`), one row per grant, with no fixed count anywhere to
   diverge from. What P15 reduces to here is "nothing truncates", which is a property of the list,
   not of a chip row — satisfied because P3's window pages the whole history. Mild, and the intent
   is honoured, but the correction that moved P15 off Profile landed it half-right: `PS6` owns the
   literal decision, `PS9` owns only its spirit.

2. **P12's "Inbox unlock rows with rarity badges" was cited as prototype-backed evidence for a
   surface that had never rendered.** `cut-list.md` §2's Kartu row lists it as one of the three
   references that justify keeping Kartu, and `InboxRow.tsx` did carry the branch —
   `kind === 'unlock' && rarity !== null`. But `UnlockGrantedNotification`'s payload is
   `{unlock_key, name, icon, is_major}` with **no rarity**
   ([GrantEligibleUnlocksAction::celebration()](../../../app/Actions/Gamification/GrantEligibleUnlocksAction.php)),
   and the only `rarity` any inbox payload ever carried is the *card* rarity on **post-run** rows,
   which that same guard excludes. So the condition was never true for any row in any environment,
   and the badge had never been seen. Fixed read-side: the tier is resolved from
   `config/temari_unlocks.php` by `unlock_key`, which also rates every row recorded before now, and
   leaves the per-season `season.{id}.*` keys (which have no catalog entry and never notify anyway)
   unrated. `docs/features/notification-inbox.md` had encoded the same wrong belief in prose ("a
   post-run row still carries `run_card_id` and the card's `rarity` — the rarity is what styles an
   unlock row's badge without a join"); corrected.

3. **`docs/features/notification-inbox.md` argued *against* a cumulative loader on a premise P3
   overrides.** It said the Newer/Older pager stays "rather than adopting a cumulative load more"
   because the deep-link resolver jumps to the page a row sits on. Under the window that argument
   dissolves — the resolver widens the window instead of selecting a page — but it is worth flagging
   that a doc, not just the plan, was carrying a decision the parity program reverses.

4. **Demo seed: unlock rows exist only after the queue drains.** `demo:seed` grants 21 accessory
   unlocks, but `UnlockGrantedNotification` is `ShouldQueue`, so on a box with no worker running the
   demo inbox holds 2 rows and P12's surface is invisible. Draining the queue produces all 21. Not a
   `PS9` defect (prod runs Horizon) and not fixed here, but P30 asks `PP4` for "inbox variety across
   kinds" — that variety depends on the queue being worked after the seed, which the seed does not
   itself do.

## Open questions

1. **The "load older" pill's markup is duplicated from `Activities/Feed.tsx`.** The prototype draws
   the same control on both screens and `PS7` built it there first; this slice copies the treatment
   rather than extracting a shared component into a screen it does not own. One duplication, worth
   consolidating in a sweep rather than from inside a screen slice.

2. **`MAX_SHOWN = 500` is a judgement call.** Nothing prunes the notifications table and retention
   is still undecided, so the window needs *some* ceiling to stop a hand-typed `?shown=` scanning an
   old account's whole history. 500 is 25 presses deep; it is not a measured number.

3. **Pre-existing dead references noticed, not touched** (`W2`'s sweep, per P4): `run_card_id` is
   still flattened onto every inbox row and nothing reads it now that `PP3` cut the reveal;
   `RunCard::allBadgeCountsForUser()` is still orphaned (`PS6` flagged the same); and
   `PlannedSession`, `Season` and `GrantSeasonUnlocksAction` docblocks still describe "the badge
   board" P14 cut.

4. **The prototype *is* consistent about the empty-state shape** — recorded on `PS12`'s row rather
   than settled here. `EmptyInboxCard` (`InboxScreen.tsx:261-281`) is the same compact horizontal
   card with a 40px `FaceIcon` that `PS7` found on History (`HistoryScreen.tsx:509-529`), against the
   app's centred `EmptyPanel` at 48. Two screens now agree, which is what that row was deferred to
   find out.
