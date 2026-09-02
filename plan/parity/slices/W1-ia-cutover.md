# W1 — IA cutover

Wave 3's first slice: reconcile routes, `nav.ts` and the entry-chunk guard against what the eleven
screen slices actually landed as.

**Most of it was already done, and that is the finding.** Three of the four items in the original
stub need no work at all; the fourth turned out to be a real gap.

## What needed nothing

**Routes.** `route:list` carries no `/accessories`, `/cards`, `/records` or any other cut surface,
and `routes/web.php` mentions none of them. The Accessories route-level cleanup the stub assigns
here had already happened.

**Redirects.** `C1` pulled every legacy 301 forward on 2026-08-31, ruled by the user ("i dont
really need a perma redirect"): all nine went, five of which had been 301-ing into a 404 since
`S7`. The stub already records this.

**`nav.ts`.** Verified against its three governing decisions rather than assumed:

| decision | requirement | state |
|---|---|---|
| P6 | five screens keep the bottom nav, and **Race lights the `plan` tab** | `NAV_SCREENS` maps Home/Plan/Race/Trends/History across four tabs, Race → `plan` |
| P33 | fixed back parents: Activity → History, Inbox → Today, Profile → Today, Settings → Profile | `BACK_TARGETS` matches exactly |
| P35 | anything else defaults to pushed chrome | `backTargetFor` falls back to Today for an unknown component |

`F4` landed all three and `nav.test.ts` pins them in eight cases. No change.

**Page components.** Cross-checked every `Inertia::render` target in `app/` against
`resources/js/pages/`: no route names a missing component, and nothing on disk is unreachable. The
two files that appear unrendered — `Activities/Feed` and `Activities/Calendar` — are imported and
rendered by `History.tsx` behind its `?view=` param, exactly as P35 records. They sit under
`pages/` without being pages, which is untidy, but moving them is churn across imports and tests
for no user-visible gain; recorded, not done.

## What actually needed doing: the entry-chunk guard

`ROUTE_BUDGETS_KB` budgeted four routes, chosen before the port. Measured after it, the set is
wrong:

| page | gz | was budgeted |
|---|---|---|
| Runs/Show | 205.7 | yes, 245 |
| Home | 200.7 | yes, 240 |
| **History** | **200.4** | **no** |
| **Settings** | **199.6** | **no** |
| **Plan** | **196.0** | **no** |
| Profile | 185.8 | yes, 230 |

**Three of the app's heaviest routes were unguarded entirely**, each heavier than a route that was
guarded. The port moved weight around and the guard never followed.

The list is now **one entry per screen the prototype draws**, which is also every screen a user can
land on cold — a set that maps 1:1 onto this program's own scope rather than onto whatever four
routes happened to be picked once. Operator pages and the legal documents stay out, per P20.

Budgets are the measured weight plus ~10%, rounded up to 5. **Every one is tighter than the number
it replaces** (Home 240 → 225, Runs/Show 245 → 230, Profile 230 → 205, Login 160 → 155), so this
re-baselines the guard without weakening it — which is exactly what P34 permits and no more.

## Acceptance criteria

1. Every routed page resolves to a component that exists, and nothing on disk is unreachable.
2. `nav.ts` satisfies P6, P33 and P35, with tests pinning each.
3. All eleven screens are budgeted, every budget below its predecessor, and the guard still fails
   on a breach.
4. `./vendor/bin/sail composer check` green (`--no-tia` on pest).

## Verification notes

- **The guard was tested by breaching it**, not by watching it go green: dropping Today's budget to
  150 produced `over its 150 kB budget by 50.7 kB` and exit code 1; restoring it returned exit 0.
  The same discipline that caught `T1`'s vacuous icon guard.
- Every page's closure was measured, not estimated, by running the real script over a generated
  budget list covering all 17 page components.

## Amendment recorded here: `W6` no longer needs a data migration

The user ruled on 2026-09-02 that **the epic's merge to `main` will be followed by a
`migrate:fresh`**. `W6`'s whole difficulty was that three values are *persisted* and could not be
renamed in place — the `AnalysisType` cases `briefing_featured_kartu_voice` and `aku_profile_voice`,
and the `rute` share-card layout token. With no data surviving the merge, they become ordinary
renames.

What does **not** go away is the environment variable
`AZURE_OPENAI_BRIEFING_FEATURED_KARTU_VOICE_DEPLOYMENT`: that is deployment configuration, not data,
so renaming it still needs a coordinated change on the production host. `W6` keeps that half.
