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
    <meta name="description" content="Every run, measured against one you've already done. Temari reads your Strava runs and tells you which way you're going.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Temari">
    <meta property="og:description" content="Every run, measured against one you've already done. Temari reads your Strava runs and tells you which way you're going.">
    <meta property="og:image" content="{{ asset('og-default.png') }}">
    <meta property="og:site_name" content="Temari">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Temari">
    <meta name="twitter:description" content="Every run, measured against one you've already done.">
    <meta name="twitter:image" content="{{ asset('og-default.png') }}">

    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    @include('partials.theme-resolution')

    {{-- PWA: installable + standalone; push works once added to the Home Screen via Safari. --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    {{-- `default` is the last status-bar style that asks iOS for a plain system
         bar rather than a composited one, so iOS 26.1 stops blurring the strip.
         The cost is that the bar is no longer dark: on the dark ground it reads
         as a pale band above the app. The style cannot vary per colour scheme.
         The blur only reproduces on an iOS device, so do not "fix" this from
         the spec. --}}
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Temari">

    {{-- Launch images for a cold standalone start, so iOS does not hold a white
         screen until first paint. Keyed by CSS device size + DPR and by
         prefers-color-scheme, which follows the OS rather than the
         'temari-theme' key, so a user who overrode the ground in Settings still
         sees the OS-matching image. Regenerate the PNGs with
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

    {{-- Fonts are self-hosted (resources/css/fonts.css), so a cold standalone
         launch never blocks on a third-party origin. First in the @vite list so
         the faces are known before app.css asks for them. --}}
    @viteReactRefresh
    @vite(['resources/css/fonts.css', 'resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
    @inertiaHead
</head>
<body class="bg-background text-foreground antialiased">
    {{-- iOS 26/27 draws a progressive blur over the top edge of a standalone PWA
         regardless of apple-mobile-web-app-status-bar-style; only a real,
         opaque bar tall enough to cover the blurred strip clears it.

         navigator.standalone (Safari-only) rather than a `display-mode:
         standalone` media query, since that also matches non-iOS installed PWAs.
         `bg-background` keeps it in lockstep with the `data-theme` the theme
         script resolved, rather than prefers-color-scheme, which freezes for the
         life of the process in standalone mode. Its own view-transition name
         keeps it pinned above a push slide, since a gap at the top edge
         mid-slide leaves the blur on after it ends. --}}
    <div id="ios-status-bar-tint" aria-hidden="true" class="fixed inset-x-0 top-0 z-50 hidden h-4 bg-background [view-transition-name:ios-status-bar-tint]"></div>
    <script>
        if (navigator.standalone === true) {
            document.getElementById('ios-status-bar-tint').classList.remove('hidden');
        }
    </script>

    @auth
        @include('partials.boot-shell')
    @endauth
    @inertia
</body>
</html>
