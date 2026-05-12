<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'Teman Lari') }}</title>

    <link rel="icon" type="image/svg+xml" href="https://api.iconify.design/mdi/run-fast.svg?color=%2384CC16">

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    <script src="https://code.iconify.design/iconify-icon/3.0.1/iconify-icon.min.js" defer></script>
</head>
<body class="min-h-screen bg-[#FDFDFC] text-[#1b1b18] antialiased dark:bg-[#0a0a0a] dark:text-white">
    <x-demo-banner />
    {{ $slot ?? '' }}
    @yield('content')
</body>
</html>
