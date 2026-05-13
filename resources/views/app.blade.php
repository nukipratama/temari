<!DOCTYPE html>
<html lang="id" class="{{ session('theme', 'light') === 'dark' ? 'dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'TemanLari') }}</title>
    <link rel="icon" type="image/svg+xml" href="https://api.iconify.design/mdi/run-fast.svg?color=%232E7D5C">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="bg-surface text-ink antialiased dark:bg-surface-dark dark:text-ink-dark">
    @inertia
</body>
</html>
