---
title: Frontend Architecture (Inertia 2 + React 19)
description: How the SPA is wired — the controller→page→component flow, shared props, the middleware/route gate, the React entry, layout, and frontend conventions
tags: [architecture, frontend]
status: living
reviewed: 2026-07-29
code_refs:
  - resources/js/app.tsx
  - resources/views/app.blade.php
  - app/Http/Middleware/HandleInertiaRequests.php
  - app/Http/Middleware/SetInertiaEtag.php
  - app/Services/Inertia/SharedProps.php
  - app/Support/SharedPropCacheKey.php
  - resources/js/layouts/AppShell.tsx
  - resources/js/components/FlashNotice.tsx
  - resources/js/types/inertia.ts
  - routes/web.php
  - bootstrap/app.php
---

# Frontend Architecture (Inertia 2 + React 19)

There is no client-side router and no REST/JSON API for pages. Every screen is a Laravel controller that returns [`Inertia::render('PageName', $props)`](app/Http/Controllers/DashboardController.php#L69); Inertia ships those props to a React page component, and `<Link>` navigation re-runs the controller and swaps the page in place. This note is the wiring overview — individual screens have their own notes ([[dashboard]], [[run-history]], [[run-detail]], [[profile]], [[cards-collection]], [[targets-accessories]], [[records]]).

## The request lifecycle

1. **Blade root.** [app.blade.php](resources/views/app.blade.php) is the single server-rendered shell: `lang="id"`, the CSRF meta tag, Google Fonts, `@vite(['resources/css/app.css', 'resources/js/app.tsx'])`, and `@inertia` (the mount point). `$rootView = 'app'` is set in [HandleInertiaRequests](app/Http/Middleware/HandleInertiaRequests.php#L18).
2. **Controller.** Renders a page name + page props (see the calls in [routes/web.php](routes/web.php#L50)'s controllers, e.g. [DashboardController](app/Http/Controllers/DashboardController.php#L69)).
3. **Middleware merges shared props** (below) into every response.
4. **React resolves + mounts** the page (below).

## The React entry

[app.tsx](resources/js/app.tsx#L11) is the whole client bootstrap. `createInertiaApp`:

- **Resolves page names to files** by glob over [`./pages/**/*.tsx`](resources/js/app.tsx#L13) (test files excluded), so a render name maps directly to a file path: `'Today'` → `pages/Today.tsx`, `'Activities/Feed'` → `pages/Activities/Feed.tsx`, `'Auth/Login'` → `pages/Auth/Login.tsx`. **A render name with no matching file throws** ([app.tsx:19](resources/js/app.tsx#L19)). This is the page-naming convention: nested names are real subdirectories under `resources/js/pages/`.
- **Mounts under a shared [ErrorBoundary](resources/js/app.tsx#L27)** and installs the global client-error reporter ([app.tsx:9](resources/js/app.tsx#L9)) that POSTs to the CSRF-exempt `/client-errors` sink. The Inertia progress bar uses the Daybreak leaf green ([app.tsx:32](resources/js/app.tsx#L32)); tokens live in [[design-tokens]].
- **Sets the document title** template `"{title} · Temari"` ([app.tsx:12](resources/js/app.tsx#L12)); pages set their own title via `<Head>` (see [Login.tsx](resources/js/pages/Auth/Login.tsx#L49)).

**Code splitting.** The page glob is lazy, so every page is its own chunk, and heavy vendors are lifted into shared `react-vendor` / `charts` / `maps` / `motion` chunks by Rolldown's `advancedChunks` groups ([vite.config.ts:28](vite.config.ts#L28)). React core carries the highest `priority` deliberately, and the reason is **mechanical, not a version bug** — it reproduces on the installed Rolldown 1.1.5 / Vite 8.1.5, and no upstream release fixes it. Two documented behaviours combine:

1. A group captures its modules' **transitive dependencies** (`includeDependenciesRecursively`, default `true`), so `charts` absorbs `react` through react-chartjs-2 and `maps` absorbs `react-dom` through react-leaflet.
2. The older `manualChunks` **function** is desugared into a *single* group whose `name()` is the whole callback, with no `test` and no `priority`. Every name the callback returns therefore shares one group at priority `0`.

Together those mean a `manualChunks` callback **cannot** fix the problem from inside itself: adding a `react-vendor` branch does not outrank `charts`, because `priority` is per-*group*, not per-returned-name. Rebuilding this repo with exactly that (a `react-vendor` branch listed first) still leaves `charts` and `maps` in the entry's static closure, with `react-vendor` 11.3KB smaller and `charts` + `maps` 11.3KB larger between them — React leaking, measurably. Only explicit groups expose `priority`, which is documented to remove a claimed module from every lower-priority group. That is the entire fix.

With React pinned, the entry's static closure is the Rolldown runtime + `app` + `react-vendor` only — **371.5KB raw / 118.7KB gzipped, down from 693.8 / 222.0**. `charts` and `maps` are additionally behind `lazy()` boundaries ([ProgressionChart.tsx:9](resources/js/components/koleksi/ProgressionChart.tsx#L9), [Show.tsx:54](resources/js/pages/Runs/Show.tsx#L54)), so neither sits in *any* route's static closure. The four bottom-nav chunks are warmed after idle ([app.tsx:44](resources/js/app.tsx#L44)).

> **Naming caveat.** Rolldown 1.1.5 renamed `output.advancedChunks` to `output.codeSplitting` (identical schema) and logs a deprecation warning for the old name; `manualChunks` is deprecated as well. The rename is safe to do, but never migrate *back* to `manualChunks` — it silently reintroduces the leak above.

## Shared props vs page props

Two prop channels reach a React page, both via `usePage().props`:

- **Page props** — the second arg to `Inertia::render`, scoped to that one controller. A page declares them as its component props interface and destructures them (e.g. [Today](resources/js/pages/Today.tsx#L33)).
- **Shared props** — merged into *every* response by [`HandleInertiaRequests::share`](app/Http/Middleware/HandleInertiaRequests.php#L52), which is pure wiring: it merges Inertia's own share block with whatever [`SharedProps::forRequest`](app/Services/Inertia/SharedProps.php#L40) builds. That service owns the request-shaped remainder — the auth user, flash messages, `demoLoginEnabled`, `webPushPublicKey` — and composes one builder per domain family: [`GamificationProps`](app/Services/Inertia/GamificationProps.php), [`StravaProps`](app/Services/Inertia/StravaProps.php), [`NotificationProps`](app/Services/Inertia/NotificationProps.php) and [`AiProps`](app/Services/Inertia/AiProps.php). See `forRequest` for the authoritative key list — do not hand-copy it. The TS contract is [`SharedProps`](resources/js/types/inertia.ts#L72).

Every derived shared prop is a **lazy closure** (`fn () => ...`), so Inertia only evaluates it when a partial reload actually requests that key — and nothing at all is pre-loaded for a request that asks for none, which is what makes the analysis poll free of shared-prop cost. **Page props on the polled screens use the same idiom** — a partial reload filters the *response*, but a controller that computes its props in the method body still runs every one of them, and [`useAnalysisTrigger`](resources/js/hooks/useAnalysisTrigger.ts#L91) reloads a narrow `only` set every 3-15s for up to 30 ticks while narration generates. [`RunController::index`](app/Http/Controllers/RunController.php#L198), [`RunController::show`](app/Http/Controllers/RunController.php#L578) and [`DashboardController`](app/Http/Controllers/DashboardController.php#L70) therefore hand Inertia closures, each memoizing the one fetch several props share. Plain closures, not `Inertia::optional()` — they must still resolve on a full page load, which is what pages like `Today` branch on at first paint. All eight shared props are additionally **cached**, because their queries would otherwise run on every page load. Key, TTL and bust live together in [`SharedPropCacheKey`](app/Support/SharedPropCacheKey.php#L20), which is also what write paths call to invalidate — a model or a Pulse card must never import a cache key from the HTTP layer. The per-key TTL rationale is documented on that enum's constants ([SharedPropCacheKey.php:37](app/Support/SharedPropCacheKey.php#L37) onward); the four settings-shaped signals (`equippedAccessories`, `telegramConnected`, `webPushSubscribed`, `stravaZoneScopeMissing`) are bust-on-write with the TTL only as a safety net. Pages read shared props by typing `usePage<SharedProps & PageProps>()` and reaching for the shared key directly — e.g. `GoalsCard` pulls `goalsSummary` straight from shared props rather than a page prop (see [[dashboard]]).

## Route protection (the gate)

The middleware stack is assembled in [bootstrap/app.php](bootstrap/app.php#L14): all proxies trusted (Cloudflare Tunnel terminates TLS), `HandleInertiaRequests` appended to the `web` group, and two paths CSRF-exempted (`strava/webhook`, `client-errors`). Routes themselves split into three groups in [routes/web.php](routes/web.php#L43):

- **`guest`** — `/login` and the Strava OAuth redirect/callback + demo login.
- **`auth`** — every app page (`/`, `/activities`, `/cards`, `/profile`, …) plus the small `/api/*` JSON endpoints the SPA fetches outside Inertia.
- **Public, unauthenticated** — the Strava webhook and the `/client-errors` sink.
- **`devtools`** — `/devtools`, `/ai-usage` and its POST actions, gated by HTTP Basic Auth against a shared password ([[ai-usage]] Access section); the same gate, throttled, covers Horizon and Pulse. Independent of the Strava session — Cloudflare Access fronts the edge in prod as well.

Auth is **Strava OAuth via Socialite**, not password login — see [[strava-connect]]. The unauthenticated `/api/*` flows (analysis poll/trigger, card seen/replay) are deliberately small JSON endpoints, not Inertia pages.

## Conditional GET on the history pages

Three routes carry the `inertia-etag` alias ([bootstrap/app.php](bootstrap/app.php), [routes/web.php](routes/web.php)): `/activities`, `/activities/{activity}` and `/calendar`. [`SetInertiaEtag`](app/Http/Middleware/SetInertiaEtag.php) tags their full Inertia page object with `ETag` + `Cache-Control: private, no-cache`, so a revisit revalidates and gets an empty `304` when nothing moved. It is applied **per-route, never globally** — it saves wire bytes, not server work (the controller has already run and Inertia has already serialized the page by the time the middleware sees the response), so it only pays off where the same URL is genuinely re-fetched: filter and tab toggling on `/activities`, month paging on `/calendar`, and deep links back into a past run from a Telegram notification or a share URL. These are also the three largest payloads in the app (up to 365 runs; a run detail carries `stream_summary` + `summary_polyline` + the card).

Three properties make it safe, and all three follow from the ETag being a hash of the **exact response bytes** rather than a synthetic key:

- **It cannot leak across users.** `auth.user.id` is inside the hashed bytes, so two users can never collide on an ETag for the same URL, and `private` keeps the body out of any shared cache while `no-cache` forces revalidation before every use. Even a misbehaving intermediary cannot turn a stored body into a wrong `304`: the server recomputes the body and compares, so a mismatch is always answered with a full `200`.
- **It cannot serve a stale shared prop or a stale flash.** Every closure prop and the whole `flash` bag are in the hashed bytes. A `304` therefore asserts only that the copy the browser holds is byte-identical to the one this request just built — a flash that has since aged out, a shared prop that has ticked, or a narration block that has settled all change the bytes and miss.
- **It costs no extra prop evaluation.** The hash is taken from the already-serialized `JsonResponse` content, so the lazy-closure discipline above is untouched — nothing is force-evaluated just to compute an ETag.

Partial reloads are deliberately excluded and marked `no-store`: the browser cache is keyed by URL, so letting a poll tick's narrow prop subset be stored would evict the full page object the next visit wants to revalidate against. The initial HTML document is untagged too (it carries the per-session CSRF meta tag, and the service worker already intercepts navigations only — [public/sw.js](public/sw.js)).

## Layout

There are two layouts. [AppShell](resources/js/layouts/AppShell.tsx#L25) is the full one; pages opt into it as an Inertia **persistent layout** (`Page.layout = appLayout`, [appLayout.tsx:21](resources/js/layouts/appLayout.tsx#L21)), which keeps the shell mounted across navigations instead of remounting it every visit. It renders the desktop [TopNav](resources/js/components/TopNav.tsx#L1), the mobile top bar + bottom nav, a skip-link, and the app-wide overlays that fire off shared props: the [CardReveal](resources/js/layouts/AppShell.tsx#L19) when `pendingReveal` is set and the accessory-unlock modal driven by `flash.unlock`. Animation is gated globally through `MotionConfig reducedMotion="user"`.

**Server-said-something surfaces.** Two banner components sit at the top of the shell and are the *only* app-wide renderers for a per-response message. [ErrorBanner](resources/js/components/ErrorBanner.tsx#L12) takes the `withErrors()` bag; [FlashNotice](resources/js/components/FlashNotice.tsx#L38) takes `flash.error` / `flash.info` / `flash.success`, in that priority, one at a time. Both are mounted once, dismissable, and re-show only when the message *changes* — so a partial reload, which keeps unrequested props client-side, cannot resurrect a dismissed message or stack a duplicate. Before `FlashNotice` existed, every `->with('success', …)` in the app rendered nowhere and `flash.info` rendered only on the two pages that read it themselves, so honest server refusals were invisible. A new controller message needs no wiring beyond the `->with()`.

Two renderers stay deliberately local rather than folding into `FlashNotice`: [AiUsage](resources/js/pages/AiUsage.tsx#L34) has no layout at all (an operator screen with its own chrome and plainer tone), and Login is on `BareShell` with the message placed inside the form column rather than as chrome. `FlashNotice` is therefore **not** mounted in `BareShell` — that would double-render Login's own info block.

[BareShell](resources/js/layouts/BareShell.tsx#L12) is the standalone one — banners, children, no chrome — and only Login uses it ([Login.tsx:4](resources/js/pages/Auth/Login.tsx#L4)). It is a **separate module rather than an `AppShell` prop on purpose**: Login renders nothing animated, but while `bareLayout` lived beside `appLayout` it dragged `AppShell` — and through it `MotionConfig`, `MobileBottomNav`, `UnlockToast` and `AksesoriUnlockModal` — into Login's static import closure, putting all ~127KB of framer-motion on the login first paint. Splitting the module removed it: **Login's closure fell 599.5 → 455.6KB raw, 193.4 → 147.1KB gzipped (-23.9%)**. Keep BareShell free of framer-motion.

**The bundle is gated.** [check-entry-chunks.mjs](scripts/check-entry-chunks.mjs#L29) runs in CI after `npm run build` and is the only check that reads the built output. It asserts (1) the entry's static closure contains *nothing* but `rolldown-runtime` + `app` + `react-vendor`, and (2) a cold visit to each budgeted route stays under a gzipped ceiling — Login, plus `Today`, `Runs/Show` and `Profile` as representative authenticated routes. Rule 1 catches a vendor chunk capturing React; rule 2 catches framer-motion returning to Login, and on the authenticated routes a lazy-only engine (`charts` ~59KB gzipped, `maps` ~45KB) becoming a static import. Those budgets sit above the framer-motion the authenticated shell carries legitimately, so they catch a step change rather than slow creep — a ceiling tight enough to catch creep would fire on ordinary feature growth and get raised on sight. It matches on chunk *membership and bytes*, never on chunk names — Rolldown names auto-generated shared chunks after a member module, and `lib/motion.ts` has already produced a second, unrelated `motion-*.js` next to the framer-motion vendor chunk ([check-entry-chunks.mjs:20](scripts/check-entry-chunks.mjs#L20)).

## Component & lib organization

- **`resources/js/pages/`** — one file (or subdir) per Inertia render name; the only files Inertia mounts.
- **`resources/js/components/`** — shared chrome at the root (nav, avatars, brand mark, error boundary), then **per-domain subfolders** (`dashboard/`, `riwayat/`, `run/`, `card/`, `koleksi/`, `aktivitas/`, `temari/`, `celebrations/`) plus a generic `ui/` kit. Reuse before adding.
- **`resources/js/lib/`** — pure helpers (pace/format, polyline projection, mood, chart theme, the `postJson` fetch helper for non-Inertia JSON POSTs at [http.ts](resources/js/lib/http.ts#L13), and typed link builders in [routes.ts](resources/js/lib/routes.ts#L1) that guard the card-id/activity-id mixup and, via [`analysisTriggerUrl`](resources/js/lib/routes.ts#L14), the analysis subject-id/row-id mixup).
- **`resources/js/hooks/`** — reusable hooks (`useAnalysisTrigger`, `useDawnShift`, `useFocusTrap`, …).
- **`resources/js/types/`** — `inertia.ts` is the hand-written shared contract; [`generated.ts`](resources/js/types/generated.ts#L1) is auto-generated from the backend PHP enums by `php artisan typescript:enums` (CI fails if stale), re-exported through `inertia.ts`.

The 1:1 `*.test.tsx` convention (every component/lib file has a sibling test) is a project rule — see the `temari` skill.

## Conventions worth knowing

- **Light-mode only, Tailwind v4.** `.dark` is never applied; there are no `*-dark` tokens. The theme is defined in `resources/css/app.css`'s `@theme` block; see [[design-tokens]].
- **Indonesian voice, English running terms** — applies to all UI copy; details in the `temari` skill and [[voice-and-tone]].
- **Two POST channels.** Inertia's `router`/`<Form>` for anything that returns a page or redirect; plain `fetch` via [`postJson`](resources/js/lib/http.ts#L13) for the small JSON endpoints (Inertia's router rejects non-Inertia responses). `postJson` owns the plumbing only — CSRF header, `same-origin` credentials, empty JSON body — and resolves with the raw `Response`, so each caller keeps its own error policy: the card "seen" marker swallows, the card replay checks `.ok`, and the analysis trigger ([`triggerAnalysis`](resources/js/hooks/useAnalysisTrigger.ts#L166)) handles 429/403 itself.
- The data shapes these pages render (`Analysis`, `WeeklySnapshot`, `RunCard`, `StoryLine`, …) are documented in [[data-model]]; the AI voice blocks flow through the [[ai-pipeline]].
