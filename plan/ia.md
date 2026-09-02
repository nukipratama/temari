# Information architecture

Resolves decision 14. Ruled by the user during `L0` (2026-08-28): **adopt the prototype's 4-tab
bottom nav** — today / plan / trends / history — replacing the shipped app's 3 tabs, where Plan was
previously a sub-destination of Today.

---

## `nav.ts` diff spec

Literal target state for [resources/js/lib/nav.ts](../resources/js/lib/nav.ts), landed by `F4`:

```ts
export type TabId = 'today' | 'plan' | 'trends' | 'history';

export interface NavItem {
    id: TabId;
    label: string;
    href: string;
    icon: string; // lucide-react component name, per decision 16 — not an iconify string
    prefixes: ReadonlyArray<string>;
}

export const ITEMS: ReadonlyArray<NavItem> = [
    { id: 'today',   label: 'Today',   href: '/',        icon: 'Sunrise',      prefixes: ['/'] },
    { id: 'plan',    label: 'Plan',    href: '/plan',     icon: 'CalendarCheck', prefixes: ['/plan', '/race'] },
    { id: 'trends',  label: 'Trends',  href: '/trends',   icon: 'LineChart',    prefixes: ['/trends'] },
    { id: 'history', label: 'History', href: '/history',  icon: 'History',     prefixes: ['/history', '/activities'] },
];
```

Changes from current:

| | current | new |
|---|---|---|
| `TabId` | `'today' \| 'trends' \| 'history'` | adds `'plan'` |
| icons | `mdi:*` (iconify strings) | lucide component names (decision 16 — full swap, `iconBundle.ts` deleted in `F3`) |
| `today.prefixes` | `['/', '/plan', '/race']` | `['/']` only — Plan and Race move out |
| `plan` | not a tab; folded under `today` | own tab, `prefixes: ['/plan', '/race']` |

**Open question for `F4` to confirm, not blocking**: `/race` is grouped under the `plan` tab here
(RaceGoal is goal-setting content, semantically closer to Plan than to Today's "what's happening
now"). This is an assumption, not a ruled decision — if `F4` finds a reason `/race` should highlight
`today` instead, that is a routine implementation call for that slice, not a program-level amendment.

`activeTabFromUrl()`'s matching logic (exact `/` for the root, prefix match otherwise) is unchanged.

---

## Route inventory

Every route in [routes/web.php](../routes/web.php), classified. `cut` rows are scheduled for
removal per [ledger.md](ledger.md) — they still exist in the codebase as of `L0`; `W1` removes the
routes, `W2` removes the backend they call.

### Guest

| route | name | renders | class |
|---|---|---|---|
| `GET /login` | `login` | `Auth/Login` | tab-adjacent entry (unauthenticated) |
| `POST /auth/demo` | `auth.demo` | — | action |
| `GET /terms`, `/privacy`, `/ai-use`, `/training-disclaimer` | `legal.*` | `Legal/Document` | pushed, linked from Login/Settings footer |
| `GET/POST /strava/webhook`, `POST /telegram/webhook`, `POST /client-errors` | — | — | API/webhook, no UI entry point |
| `GET /auth/strava/redirect`, `/auth/strava/callback` | `auth.strava.*` | — | OAuth redirect |

### Authenticated, pre-onboarding

| route | name | renders | class |
|---|---|---|---|
| `GET/POST /onboarding` | `onboarding.*` | `Onboarding/Index` | forced flow, not nav-reachable |
| `POST /logout` | `auth.logout` | — | action |

### Authenticated + onboarded

| route | name | renders | class | tab |
|---|---|---|---|---|---|
| `GET /` | `dashboard` | `Home` | **tab** | today |
| `GET /plan`, `POST /plan/regenerate`, `PATCH /plan/sessions/{id}` | `plan.*` | `Plan` | **tab** + actions | plan |
| `GET /race`, `POST /race` | `race`, `race.store` | `Race` | pushed, under `plan` prefix | plan |
| `GET /trends` | `trends` | `Trends` | **tab** | trends |
| `GET /history` | `history` | `History` | **tab** | history |
| `GET /activities/{id}` | `activities.show` | `Runs/Show` | pushed, from feed/history | history |
| `POST /activities/{id}/resync`, `/send` | — | — | action | — |
| `POST /recaps/weekly/{snapshot}/send`, `/recaps/monthly/{month}/send` | — | — | action | — |
| ~~`GET /accessories`~~ | ~~`accessories`~~ | ~~`Collection/Accessories`~~ | **cut** — see ledger | — |
| ~~`POST /api/accessories/equip`~~ | ~~`api.accessories.equip`~~ | — | **cut** — see ledger | — |
| `GET /inbox` | `inbox` | `Inbox` | pushed, from bell icon | — |
| `GET /profile` | `profile` | `Profile` | pushed, from avatar | — |
| `PATCH /profile/notifications`, `DELETE /profile/telegram`, `POST /profile/notifications/test`, `POST/DELETE /profile/push` | — | — | action | — |
| `GET /settings` | `settings` | `Settings/Index` | pushed, from Profile | — |
| `DELETE /account`, `PATCH/DELETE /settings/zones`, `POST /settings/zones/resync-strava` | — | — | action | — |
| `POST /strava/sync` | `strava.sync` | — | action | — |
| `POST /api/cards/{card}/seen`, `/replay` | — | — | action | — |
| `POST /api/notifications/{id}/read` | — | — | action | — |
| `GET/POST /api/analyses/{type}/{subjectId}[/trigger]` | — | — | API (Inertia partial reload target, not a page) | — |
| `GET/POST /api/activities/{id}/questions` | — | — | API | — |

### Legacy 301 redirects — all removed

Every legacy redirect is gone, ruled by the user in `C1` (2026-08-31) and pulled forward out of
`W1`. Each of these paths now 404s.

| from | to | status |
|---|---|---|
| `/catatan`, `/runs`, `/progress` | `/activities` | **removed** — and `/activities` had no bare route, so these had been 301-ing into a 404 since `S7` |
| `/kalender` | `/calendar` | **removed** — `/calendar` has no route either; same broken chain |
| `/akun` | `/account` | **removed** — only `DELETE /account` exists, so this too 301-ed into a 404 |
| `/pengaturan` | `/settings` | **removed** — target was live |
| `/profil` | `/profile` | **removed** — target was live |
| `/rekor` | `/trends` | **removed** — target was live |
| `/runs/{activity}` | `/activities/{activity}` | **removed** — target was live; the only one of the nine with real deep-link value |
| ~~`/aksesori`~~ | ~~`/accessories`~~ | **removed** — per the ledger's Accessories cut, ruled 2026-08-28 |
| ~~`/target`~~, ~~`/goals`~~ | ~~`/accessories`~~ | **removed** — same ruling |

### Devtools (HTTP Basic Auth + throttled — not part of the tab IA)

| route | name | renders |
|---|---|---|
| `GET /devtools` | `devtools.index` | `Devtools` |
| `GET /devtools/design` | `devtools.design` | `Devtools/Design` |
| `GET /ai-usage`, `POST /ai-usage/recover`, `POST /ai-usage/users/{id}/retry-failed` | `ai-usage.*` | `AiUsage` |

---

## Entry points for non-tab destinations

Per the product-manager review template's reachability sweep — every pushed screen's tap-through
origin, so `W1`/screen slices don't accidentally strand one:

| screen | reached from |
|---|---|
| Onboarding | forced redirect for any authenticated-not-onboarded user |
| RaceGoal | tap-through from Plan |
| ActivityDetail | tap a card in History/Feed |
| Inbox | bell icon in the topbar (present on every tab) |
| Profile | avatar in the topbar (present on every tab) |
| Settings | link from Profile |
| Legal documents | footer links on Login and Settings |
