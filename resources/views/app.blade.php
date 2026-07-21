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
    {{-- The app is light-mode only, so tell the UA and stop it rendering the
         surfaces it owns (form controls, scrollbars) in dark appearance on a
         device set to Dark Mode. This does NOT govern the iOS standalone
         status bar — that was tried in #396 and did not move it. --}}
    <meta name="color-scheme" content="light">

    {{-- Matches MobileTopBar so the two never seam. Android/Chrome uses this to
         tint its toolbar; iOS does not use it for the standalone status bar at
         all, which is why two rounds of retinting this value never touched the
         dark band around the notch. Fixed rather than following the dawn-shift,
         since the header it butts against is one colour at every hour. --}}
    <meta name="theme-color" content="#1F2747">

    {{-- PWA: installable + standalone; push works once added to the Home Screen via Safari. --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    {{-- `black-translucent` extends the web view up under the status bar, which
         is what finally makes env(safe-area-inset-top) resolve to a real value
         and hands us those pixels to paint. Under `default` the strip stayed
         iOS-owned and unreachable from CSS — the actual cause of the dark band,
         after theme-color (#395) and color-scheme (#396) both failed to explain
         it.

         The trade: iOS forces WHITE status glyphs in this mode, with no way to
         ask for dark ones. Everything that can reach the top of the display
         must therefore be dark, or the clock becomes unreadable — hence the
         navy MobileTopBar and the StatusBarScrim that backs it even while a
         modal is open. Do not revert this meta on its own. --}}
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
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
