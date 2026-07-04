<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'TemanLari') }}</title>

    {{-- Default social preview for the app (e.g. a shared /login link). The
         public card page (public/kartu.blade.php) ships its own per-card tags. --}}
    <meta name="description" content="teman-lari, teman lari kamu di setiap langkah. Ubah lari dari Strava jadi kartu koleksi dan cerita ringan.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="teman-lari">
    <meta property="og:description" content="teman-lari, teman lari kamu di setiap langkah. Ubah lari dari Strava jadi kartu koleksi dan cerita ringan.">
    <meta property="og:image" content="{{ asset('og-default.png') }}">
    <meta property="og:site_name" content="teman-lari">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="teman-lari">
    <meta name="twitter:description" content="teman-lari, teman lari kamu di setiap langkah.">
    <meta name="twitter:image" content="{{ asset('og-default.png') }}">

    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('favicon.svg') }}">
    <meta name="theme-color" content="#1F2747">
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
