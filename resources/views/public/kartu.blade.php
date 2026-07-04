@php
    $km = $distanceKm !== null ? rtrim(rtrim(number_format($distanceKm, 2, '.', ''), '0'), '.') : null;
    $dateLabel = $date?->translatedFormat('j M Y');
    $ogDescription = 'Kartu lari' . ($km !== null ? " {$km} km" : '') . ', rarity ' . $rarityLabel . ', dari teman-lari.';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $name }} · teman-lari</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">

    {{-- Brand faces this page actually uses: Fraunces (card name) + Oswald (stat number).
         Without these the standalone share page falls back to Georgia/system-ui. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,600;1,9..144,400&family=Oswald:wght@500;600;700&display=swap">

    <meta name="description" content="{{ $ogDescription }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $name }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:site_name" content="teman-lari">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $name }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    <style>
        :root {
            --sky: #1f2747;
            --cream: #f6f1e8;
            --cream-deep: #eee7d6;
            --surface-elev: #fbf7ee;
            --ink: #1a1812;
            --ink-2: #3d362a;
            --ink-3: #6e6452;
            --line: #d8ddd2;
            --horizon: #e8a076;
            --rarity: {{ $rarityColor }};
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: var(--cream-deep);
            color: var(--ink);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: var(--surface-elev);
            border: 1px solid var(--line);
            border-radius: 28px;
            box-shadow: 0 24px 60px rgba(31, 39, 71, 0.18);
            overflow: hidden;
        }
        .card__banner {
            background: var(--sky);
            color: var(--cream);
            padding: 22px 26px 18px;
        }
        .card__rarity {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--rarity);
        }
        .card__name {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 30px;
            line-height: 1.12;
            font-weight: 600;
            margin-top: 6px;
        }
        .card__route {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: var(--cream);
            border-bottom: 1px solid var(--line);
        }
        .card__route svg { display: block; width: 100%; height: auto; max-width: 320px; }
        .card__route--empty {
            font-size: 13px;
            color: var(--ink-3);
            padding: 40px 20px;
        }
        .card__stats {
            display: flex;
            gap: 8px;
            padding: 20px 26px;
        }
        .stat { flex: 1; }
        .stat__value {
            font-family: 'Oswald', sans-serif;
            font-size: 26px;
            font-weight: 600;
            line-height: 1;
        }
        .stat__label {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--ink-3);
            margin-top: 5px;
        }
        .card__cta { padding: 0 26px 26px; }
        .cta {
            display: block;
            text-align: center;
            background: var(--horizon);
            color: var(--sky);
            font-weight: 700;
            text-decoration: none;
            padding: 14px;
            border-radius: 999px;
            transition: filter 0.15s ease;
        }
        .cta:hover { filter: brightness(0.96); }
        .foot {
            text-align: center;
            font-size: 12px;
            color: var(--ink-3);
            margin-top: 16px;
        }
    </style>
</head>
<body>
    <div>
        <div class="card">
            <div class="card__banner">
                <span class="card__rarity">{{ $rarityLabel }}</span>
                <h1 class="card__name">{{ $name }}</h1>
            </div>

            <div class="card__route @if ($routePath === null) card__route--empty @endif">
                @if ($routePath !== null)
                    <svg viewBox="0 0 {{ $svgSize }} {{ $svgSize }}" role="img" aria-label="Rute lari {{ $name }}">
                        <polyline points="{{ $routePath }}" fill="none" stroke="{{ $rarityColor }}"
                            stroke-width="3" stroke-linejoin="round" stroke-linecap="round" />
                    </svg>
                @else
                    Rute tidak tersedia untuk lari ini.
                @endif
            </div>

            <div class="card__stats">
                <div class="stat">
                    <div class="stat__value">{{ $km ?? '—' }}</div>
                    <div class="stat__label">Kilometer</div>
                </div>
                @if ($dateLabel !== null)
                    <div class="stat">
                        <div class="stat__value" style="font-size:18px;padding-top:4px">{{ $dateLabel }}</div>
                        <div class="stat__label">{{ $location ?? 'Tanggal lari' }}</div>
                    </div>
                @endif
            </div>

            <div class="card__cta">
                <a class="cta" href="{{ $appUrl }}">Buka di teman-lari</a>
            </div>
        </div>
        <p class="foot">Ditenagai teman-lari · teman lari kamu di setiap langkah.</p>
    </div>
</body>
</html>
