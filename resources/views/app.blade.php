<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover is what makes env(safe-area-inset-*) resolve to real
         values on a notched iOS device; without it they are all 0 and the
         safe-area padding on the bottom nav / top bar is silently inert. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'Temari') }}</title>

    {{-- Default social preview for the app (e.g. a shared /login link). The
         public card page (public/kartu.blade.php) ships its own per-card tags. --}}
    <meta name="description" content="Temari, menemani larimu di setiap langkah. Ubah lari dari Strava jadi kartu koleksi dan cerita ringan.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Temari">
    <meta property="og:description" content="Temari, menemani larimu di setiap langkah. Ubah lari dari Strava jadi kartu koleksi dan cerita ringan.">
    <meta property="og:image" content="{{ asset('og-default.png') }}">
    <meta property="og:site_name" content="Temari">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Temari">
    <meta name="twitter:description" content="Temari, menemani larimu di setiap langkah.">
    <meta name="twitter:image" content="{{ asset('og-default.png') }}">

    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    {{-- The app is light-mode only. Declared here as well as on `html` in
         app.css so it lands before the stylesheet does: on a device set to Dark
         Mode the UA otherwise renders its own surfaces dark — including, in an
         installed iOS PWA, the status-bar strip around the notch. That dark
         slab above the cream header was NOT a theme-color problem, which is why
         pinning theme-color to cream alone did not fix it. --}}
    <meta name="color-scheme" content="light">

    {{-- Kept matching MobileTopBar's cream-deep so the two never seam. iOS is
         inconsistent about honouring this for the standalone status bar (hence
         the color-scheme declaration above, which is what actually governs that
         strip); Android/Chrome does use it for the toolbar tint. Fixed cream
         rather than following the dawn-shift, since the header it butts against
         is cream-deep at every hour. --}}
    <meta name="theme-color" content="#EEE7D6">

    {{-- PWA: installable + standalone; push works once added to the Home Screen via Safari. --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Temari">

    {{-- Launch images for a cold standalone start. Without these iOS holds a
         white screen until first paint, which on a cream app reads as a flash.
         Keyed by CSS device size + DPR; regenerate the PNGs with
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
        <link
            rel="apple-touch-startup-image"
            media="screen and (device-width: {{ $s['w'] }}px) and (device-height: {{ $s['h'] }}px) and (-webkit-device-pixel-ratio: {{ $s['dpr'] }}) and (orientation: portrait)"
            href="{{ asset('splash/splash-'.($s['w'] * $s['dpr']).'x'.($s['h'] * $s['dpr']).'.png') }}"
        >
    @endforeach

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;1,9..144,400;1,9..144,500&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Oswald:wght@500;600;700&display=swap"
        rel="stylesheet"
    >
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="bg-surface text-ink antialiased">
    @inertia
</body>
</html>
