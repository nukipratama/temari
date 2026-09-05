<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover is what makes env(safe-area-inset-*) resolve to real
         values on a notched iOS device; without it they are all 0 and the
         safe-area padding on the bottom nav / top bar is silently inert. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'Temari') }}</title>

    {{-- Default social preview for the app (e.g. a shared /login link). There is
         no public per-card page, so these tags are the only ones the app ships. --}}
    <meta name="description" content="Temari, running alongside you every step. Turns your Strava runs into collectible cards and easygoing stories.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Temari">
    <meta property="og:description" content="Temari, running alongside you every step. Turns your Strava runs into collectible cards and easygoing stories.">
    <meta property="og:image" content="{{ asset('og-default.png') }}">
    <meta property="og:site_name" content="Temari">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Temari">
    <meta name="twitter:description" content="Temari, running alongside you every step.">
    <meta name="twitter:image" content="{{ asset('og-default.png') }}">

    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    {{-- Two-ground theme persistence (F2). Blocking and inline, ahead of every
         other resource in <head>, so `data-theme` is on <html> before the
         stylesheet applies — a deferred/external script here would let the
         light default paint for one frame before flipping to the resolved
         ground, which is the flash decision 6's toggle explicitly must not
         have. Resolution order: an explicit stored 'light'/'dark' wins; a
         stored 'system' follows the OS; anything else (first visit, storage
         unavailable, a stale value) falls back to 'dark' — decision 6's
         default ground, not the OS preference. F4 wires the live
         prefers-color-scheme listener for an open tab in 'system' mode and
         S11 builds the Settings control; both read/write the same
         'temari-theme' localStorage key this script reads.

         Sets `style.colorScheme` directly rather than a <meta
         name="color-scheme">: the meta tag can only ever hold one static
         value, where this needs to vary per resolved theme. The bare
         `html { color-scheme: dark }` rule in app.css is the fallback for
         the (here, purely theoretical — this is an Inertia/React app with no
         no-JS render path) case where this script cannot run at all. --}}
    <script>
        (function () {
            var STORAGE_KEY = 'temari-theme';
            var stored = null;
            try {
                stored = localStorage.getItem(STORAGE_KEY);
            } catch (e) {
                // Storage can throw in a locked-down/private context; fall
                // through to the hardcoded default below.
            }
            var resolved;
            if (stored === 'light' || stored === 'dark') {
                resolved = stored;
            } else if (stored === 'system') {
                resolved = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            } else {
                resolved = 'dark';
            }
            document.documentElement.dataset.theme = resolved;
            document.documentElement.style.colorScheme = resolved;
        })();
    </script>

    {{-- Android/Chrome uses this to tint its toolbar. iOS does not use it for
         the standalone status bar at all, which is why two rounds of retinting
         it never touched the dark band around the notch. One per ground, so the
         toolbar matches the surface AppShell paints under the whole app;
         public/manifest.webmanifest pins the dark value, having no way to vary.
         Fixed rather than following the dawn-shift. --}}
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0b1017">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#f1f5f8">

    {{-- PWA: installable + standalone; push works once added to the Home Screen via Safari. --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    {{-- Walking this value down as iOS 26.1 takes the strip away. Under
         `black-translucent` iOS stopped honouring the translucency and painted
         its own material over the region; `black` made that lighter but did not
         opt out of it. `default` is the last value that asks iOS for a plain
         system bar rather than a composited one.

         The cost is that the bar is no longer dark: it is the system's own,
         which on the dark ground reads as a pale band above the app. That is
         the trade for it not being blurred, and it is deliberate. The style
         cannot vary per colour scheme, so it is a single choice for both.

         Verified only by looking at a device — the blur does not reproduce in
         a desktop browser at any viewport, because it is iOS's own art rather
         than anything the page draws. Do not "fix" this from the spec. --}}
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Temari">

    {{-- Launch images for a cold standalone start. Without these iOS holds a
         white screen until first paint, which reads as a flash on either
         ground. Keyed by CSS device size + DPR, and by prefers-color-scheme so
         the image matches the ground the app is about to paint. That follows
         the OS, not the 'temari-theme' key the head script reads, so a user who
         overrode the ground in Settings still sees the OS-matching image —
         accepted, since the alternative is one fixed ground that is wrong for
         everyone on the other one. Regenerate the PNGs with
         `scripts/build-splash-screens.php`. --}}
    @foreach ([
        ['w' => 390, 'h' => 844, 'dpr' => 3],
        ['w' => 393, 'h' => 852, 'dpr' => 3],
        ['w' => 430, 'h' => 932, 'dpr' => 3],
        ['w' => 428, 'h' => 926, 'dpr' => 3],
        ['w' => 375, 'h' => 812, 'dpr' => 3],
        ['w' => 414, 'h' => 896, 'dpr' => 2],
        ['w' => 375, 'h' => 667, 'dpr' => 2],
    ] as $s)
        @foreach (['dark', 'light'] as $ground)
            <link
                rel="apple-touch-startup-image"
                media="screen and (device-width: {{ $s['w'] }}px) and (device-height: {{ $s['h'] }}px) and (-webkit-device-pixel-ratio: {{ $s['dpr'] }}) and (orientation: portrait) and (prefers-color-scheme: {{ $ground }})"
                href="{{ asset('splash/splash-'.$ground.'-'.($s['w'] * $s['dpr']).'x'.($s['h'] * $s['dpr']).'.png') }}"
            >
        @endforeach
    @endforeach

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;1,9..144,400;1,9..144,500&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="bg-background text-foreground antialiased">
    @inertia
</body>
</html>
