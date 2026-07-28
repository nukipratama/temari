---
title: Frontend Architecture (Inertia 2 + React 19)
description: How the SPA is wired — the controller→page→component flow, shared props, the middleware/route gate, the React entry, layout, and frontend conventions
tags: [architecture, frontend]
status: living
reviewed: 2026-07-28
code_refs:
  - resources/js/app.tsx
  - resources/views/app.blade.php
  - app/Http/Middleware/HandleInertiaRequests.php
  - resources/js/layouts/AppShell.tsx
  - resources/js/types/inertia.ts
  - routes/web.php
  - bootstrap/app.php
---

# Frontend Architecture (Inertia 2 + React 19)

There is no client-side router and no REST/JSON API for pages. Every screen is a Laravel controller that returns [`Inertia::render('PageName', $props)`](app/Http/Controllers/DashboardController.php#L69); Inertia ships those props to a React page component, and `<Link>` navigation re-runs the controller and swaps the page in place. This note is the wiring overview — individual screens have their own notes ([[dashboard]], [[run-history]], [[run-detail]], [[profile]], [[cards-collection]], [[targets-accessories]], [[records]]).

## The request lifecycle

1. **Blade root.** [app.blade.php](resources/views/app.blade.php) is the single server-rendered shell: `lang="id"`, the CSRF meta tag, Google Fonts, `@vite(['resources/css/app.css', 'resources/js/app.tsx'])`, and `@inertia` (the mount point). `$rootView = 'app'` is set in [HandleInertiaRequests](app/Http/Middleware/HandleInertiaRequests.php#L20).
2. **Controller.** Renders a page name + page props (see the calls in [routes/web.php](routes/web.php#L50)'s controllers, e.g. [DashboardController](app/Http/Controllers/DashboardController.php#L69)).
3. **Middleware merges shared props** (below) into every response.
4. **React resolves + mounts** the page (below).

## The React entry

[app.tsx](resources/js/app.tsx#L11) is the whole client bootstrap. `createInertiaApp`:

- **Resolves page names to files** by glob over [`./pages/**/*.tsx`](resources/js/app.tsx#L13) (test files excluded), so a render name maps directly to a file path: `'HariIni'` → `pages/HariIni.tsx`, `'Riwayat/Jejak'` → `pages/Riwayat/Jejak.tsx`, `'Auth/Login'` → `pages/Auth/Login.tsx`. **A render name with no matching file throws** ([app.tsx:19](resources/js/app.tsx#L19)). This is the page-naming convention: nested names are real subdirectories under `resources/js/pages/`.
- **Mounts under a shared [ErrorBoundary](resources/js/app.tsx#L27)** and installs the global client-error reporter ([app.tsx:9](resources/js/app.tsx#L9)) that POSTs to the CSRF-exempt `/client-errors` sink. The Inertia progress bar uses the Daybreak leaf green ([app.tsx:32](resources/js/app.tsx#L32)); tokens live in [[design-tokens]].
- **Sets the document title** template `"{title} · Temari"` ([app.tsx:12](resources/js/app.tsx#L12)); pages set their own title via `<Head>` (see [Login.tsx](resources/js/pages/Auth/Login.tsx#L49)).

**Code splitting.** The page glob is lazy, so every page is its own chunk, and heavy vendors are lifted into shared `react-vendor` / `charts` / `maps` / `motion` chunks by Rolldown's `advancedChunks` groups ([vite.config.ts:28](vite.config.ts#L28)). React core carries the highest `priority` deliberately: Rolldown otherwise parks a shared module in whichever vendor group reaches it first, which put `react` inside `charts` and `react-dom` inside `maps`, dragging both — ~325KB raw — onto the first-paint path of every route, Login included. With React pinned, the entry's static closure is the Rolldown runtime + `app` + `react-vendor` only; `charts` and `maps` now load only when a page that renders one does. The four bottom-nav chunks are additionally warmed after idle ([app.tsx:44](resources/js/app.tsx#L44)).

## Shared props vs page props

Two prop channels reach a React page, both via `usePage().props`:

- **Page props** — the second arg to `Inertia::render`, scoped to that one controller. A page declares them as its component props interface and destructures them (e.g. [HariIni](resources/js/pages/HariIni.tsx#L33)).
- **Shared props** — merged into *every* response by [`HandleInertiaRequests::share`](app/Http/Middleware/HandleInertiaRequests.php#L48). This is where cross-cutting state lives: the auth user, flash messages, `demoLoginEnabled`, equipped accessories, `pendingReveal`, `stravaSync`, `goalsSummary`, `hrZonesChangedAt`. See the method for the authoritative list — do not hand-copy it. The TS contract is [`SharedProps`](resources/js/types/inertia.ts#L69).

Most shared props are **lazy closures** (`fn () => ...`), so Inertia only evaluates them when a partial reload actually requests that key. **Page props on the polled screens use the same idiom** — a partial reload filters the *response*, but a controller that computes its props in the method body still runs every one of them, and [`useAnalysisTrigger`](resources/js/hooks/useAnalysisTrigger.ts#L91) reloads a narrow `only` set every 3-15s for up to 30 ticks while narration generates. [`RunController::index`](app/Http/Controllers/RunController.php#L198), [`RunController::show`](app/Http/Controllers/RunController.php#L603) and [`DashboardController`](app/Http/Controllers/DashboardController.php#L51) therefore hand Inertia closures, each memoizing the one fetch several props share. Plain closures, not `Inertia::optional()` — they must still resolve on a full page load, which is what pages like `HariIni` branch on at first paint. The expensive ones (`stravaSync`, `goalsSummary`, `hrZonesChangedAt`) are additionally **cached with short TTLs** because their two-or-more queries would otherwise run on every page load; the rationale is documented inline ([HandleInertiaRequests.php:22](app/Http/Middleware/HandleInertiaRequests.php#L22) onward). Pages read shared props by typing `usePage<SharedProps & PageProps>()` and reaching for the shared key directly — e.g. `GoalsCard` pulls `goalsSummary` straight from shared props rather than a page prop (see [[dashboard]]).

## Route protection (the gate)

The middleware stack is assembled in [bootstrap/app.php](bootstrap/app.php#L14): all proxies trusted (Cloudflare Tunnel terminates TLS), `HandleInertiaRequests` appended to the `web` group, and two paths CSRF-exempted (`strava/webhook`, `client-errors`). Routes themselves split into three groups in [routes/web.php](routes/web.php#L43):

- **`guest`** — `/login` and the Strava OAuth redirect/callback + demo login.
- **`auth`** — every app page (`/`, `/aktivitas`, `/kartu`, `/profil`, …) plus the small `/api/*` JSON endpoints the SPA fetches outside Inertia.
- **Public, unauthenticated** — the Strava webhook, the `/client-errors` sink, and `/ai-usage` (edge basicauth in prod).

Auth is **Strava OAuth via Socialite**, not password login — see [[strava-connect]]. The unauthenticated `/api/*` flows (analysis poll/trigger, card seen/replay) are deliberately small JSON endpoints, not Inertia pages.

## Layout

There is one layout, [AppShell](resources/js/layouts/AppShell.tsx#L19), and pages opt in by wrapping their content in it (it is not auto-applied as a persistent layout). It renders the desktop [TopNav](resources/js/components/TopNav.tsx#L1), the mobile top bar + bottom nav, a skip-link, and the app-wide overlays that fire off shared props: the [CardReveal](resources/js/layouts/AppShell.tsx#L61) when `pendingReveal` is set and the accessory-unlock modal driven by `flash.unlock` ([AppShell.tsx:26](resources/js/layouts/AppShell.tsx#L26)). Standalone screens pass `withNav={false}` to drop the chrome — only Login does ([Login.tsx:48](resources/js/pages/Auth/Login.tsx#L48)). Animation is gated globally through `MotionConfig reducedMotion="user"`.

## Component & lib organization

- **`resources/js/pages/`** — one file (or subdir) per Inertia render name; the only files Inertia mounts.
- **`resources/js/components/`** — shared chrome at the root (nav, avatars, brand mark, error boundary), then **per-domain subfolders** (`dashboard/`, `riwayat/`, `run/`, `card/`, `koleksi/`, `aktivitas/`, `temari/`, `celebrations/`) plus a generic `ui/` kit. Reuse before adding.
- **`resources/js/lib/`** — pure helpers (pace/format, polyline projection, mood, chart theme, the `postJson` fetch helper for non-Inertia JSON POSTs at [http.ts](resources/js/lib/http.ts#L12), and typed link builders in [routes.ts](resources/js/lib/routes.ts#L1) that guard the card-id/activity-id mixup).
- **`resources/js/hooks/`** — reusable hooks (`useAnalysisTrigger`, `useDawnShift`, `useFocusTrap`, …).
- **`resources/js/types/`** — `inertia.ts` is the hand-written shared contract; [`generated.ts`](resources/js/types/generated.ts#L1) is auto-generated from the backend PHP enums by `php artisan typescript:enums` (CI fails if stale), re-exported through `inertia.ts`.

The 1:1 `*.test.tsx` convention (every component/lib file has a sibling test) is a project rule — see the `temari` skill.

## Conventions worth knowing

- **Light-mode only, Tailwind v4.** `.dark` is never applied; there are no `*-dark` tokens. The theme is defined in `resources/css/app.css`'s `@theme` block; see [[design-tokens]].
- **Indonesian voice, English running terms** — applies to all UI copy; details in the `temari` skill and [[voice-and-tone]].
- **Two POST channels.** Inertia's `router`/`<Form>` for anything that returns a page or redirect; plain `fetch` via [`postJson`](resources/js/lib/http.ts#L12) for fire-and-forget JSON endpoints (Inertia's router rejects non-Inertia responses).
- The data shapes these pages render (`Analysis`, `WeeklySnapshot`, `RunCard`, `StoryLine`, …) are documented in [[data-model]]; the AI voice blocks flow through the [[ai-pipeline]].
