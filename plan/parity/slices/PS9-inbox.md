# PS9 — Inbox

Inbox (`pages/Inbox.tsx`) to prototype parity, against
[`InboxScreen.tsx`](../../../resources/brand/prototype/src/components/pages/InboxScreen.tsx) as the
source of truth and [reference.md](../reference.md) §11 as the cross-check.

Decisions in scope: **P14** (badges surface in exactly two places; Inbox's unlock rows are one),
**P15** (badge surfaces show everything earned, nothing truncates), **P12** (unlock rows carry
rarity badges), **P36** (card radius `rounded-md`), **P3** (implement what the prototype wires to
nothing), plus P1/P2/P5/P6/P7/P33 which apply everywhere. **P10**: Inbox is one of the eight screens
the prototype draws with a `FaceIcon` — at `InboxScreen.tsx:264`, inside `EmptyInboxCard`, and
nowhere else on the screen.

## Goal

What `InboxScreen.tsx` draws, in its order, at its treatment, populated with real data:

1. **eyebrow** — "Inbox · N unread", flattening to plain "Inbox" when nothing is unread or the
   inbox is empty (`:292-296`).
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
| `resources/js/pages/Inbox.tsx` | the prototype's eyebrow (no "on this page" hedge — the count is the shared `unreadNotifications` prop now), accent second headline line, the prototype's bucket/row spacing, the Newer/Older pager replaced by P3's real "load older", the page-level framer-motion stagger dropped (no sibling ported screen keeps one and the prototype draws none) |
| `resources/js/components/inbox/InboxRow.tsx` | `padding="panel"` → `card` (the prototype's `p-4`), the rarity badge re-toned onto `PS6`'s chip treatment, stat chips added for post-run rows, body/timestamp tone matched to the prototype, `weekly_recap`/`monthly_recap` icon chip to the prototype's `text-foreground` |
| `resources/js/components/inbox/InboxStatChips.tsx` | new — the prototype's `value + LABEL` pill pair (`:218-232`) |
| `app/Http/Controllers/InboxController.php` | `?older=` window for P3; unlock rows resolve `rarity` from the unlock catalog; post-run rows carry `distance_m` / `moving_time_s` for the stat chips |
| `resources/js/types/inertia.ts` | `InboxItem` gains `distance_m` / `moving_time_s`; `notifications` becomes a window rather than a paginator |
| co-located `*.test.tsx` / `*Test.php` | one per changed file, per the 1:1 convention |
| `plan/parity/README.md` | the `PS9` progress row; the `PS12` row gains Inbox's data point on the empty-state shape |

## Blockers

None. `PP1` (the 900px/760px column, the pushed-screen chrome and `BACK_TARGETS['Inbox']`), `PP2`
(`FaceIcon`) and `PP3` (P14's cuts) all landed first.

## Acceptance criteria

- [ ] The page renders exactly the prototype's sections, in its order.
- [ ] The eyebrow reads "Inbox · N unread" against a true account-wide count, or plain "Inbox".
- [ ] Empty buckets are skipped; bucket order is fixed today → this week → earlier.
- [ ] P3: "load older" really pages — it fetches older rows from the server and hides itself when
      the history is exhausted. No Newer/Older pager survives.
- [ ] P12: an unlock row draws a rarity badge, fed by real data rather than a branch nothing reaches.
- [ ] P14: unlock rows are the only badge surface on this screen; no toast, no unlock modal, no
      gallery — verified absent, not redone.
- [ ] P15: every unlock ever granted is reachable; nothing truncates to a fixed count.
- [ ] P6/P33: back chevron, no bottom nav, back goes to Today.
- [ ] P10: the only `FaceIcon` on the screen is the empty state's.
- [ ] P36: every card surface is `rounded-md`, via `cardVariants`.
- [ ] Coverage delta recorded, measured at both ends against `e8b3393f`.
- [ ] `./vendor/bin/sail composer check` green on the final tree.

## Coverage delta

_Placeholder — measured against `epic/mobile-ux-port@e8b3393f` in this worktree at both ends._

| | before | after |
|---|---|---|
| statements | | |
| branches | | |
| functions | | |
| lines | | |

## Verification notes

_Filled in as the slice lands._

## Open questions

_Filled in as the slice lands._
