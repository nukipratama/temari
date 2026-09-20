<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>back soon · Temari</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    @include('partials.theme-resolution')
    @vite(['resources/css/fonts.css', 'resources/css/app.css'])
</head>
<body class="bg-background font-sans text-foreground antialiased">
    <main class="pad-page flex min-h-svh flex-col items-center justify-center text-center">
        <svg class="size-20" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" role="img" aria-label="Temari">
            <g fill="none" stroke-width="9" stroke-linecap="round">
                <path class="stroke-horizon" d="M50 14 A36 36 0 1 1 32 18.82"/>
                <path class="stroke-foreground" d="M50 25 A25 25 0 1 1 28.35 62.5"/>
                <path class="stroke-foreground" d="M50 36 A14 14 0 0 1 62.12 57"/>
            </g>
        </svg>
        <p class="mt-8 font-mono text-xs font-semibold uppercase tracking-wider text-text-3">maintenance</p>
        <h1 class="mt-3 font-serif italic text-display-lg text-foreground">back in a bit</h1>
        <p class="mt-3 max-w-sm text-sm leading-relaxed text-text-2">
            temari's getting some work done. your runs are safe on Strava and will show up here once i'm back.
        </p>
        <button
            type="button"
            onclick="location.reload()"
            class="mt-8 rounded-full bg-horizon px-6 py-3 text-sm font-semibold text-sky hover:bg-horizon-deep"
        >
            try again
        </button>
    </main>
</body>
</html>
