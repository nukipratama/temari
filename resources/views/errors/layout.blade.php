<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · teman-lari</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@1,9..144,400;1,9..144,500&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap">
    <style>
        :root {
            --sky: #1f2747;
            --cream: #f6f1e8;
            --cream-deep: #eee7d6;
            --ink: #1a1812;
            --ink-2: #3d362a;
            --ink-3: #7a6f5c;
            --horizon: #e8a076;
            --horizon-deep: #d08a60;
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
        .panel {
            width: 100%;
            max-width: 420px;
            text-align: center;
        }
        .code {
            font-family: 'Fraunces', Georgia, serif;
            font-style: italic;
            font-size: 88px;
            line-height: 1;
            font-weight: 500;
            color: var(--horizon-deep);
        }
        .title {
            font-family: 'Fraunces', Georgia, serif;
            font-style: italic;
            font-size: 28px;
            font-weight: 500;
            color: var(--ink);
            margin-top: 12px;
        }
        .message {
            font-size: 15px;
            line-height: 1.6;
            color: var(--ink-2);
            margin-top: 12px;
        }
        .cta {
            display: inline-block;
            margin-top: 24px;
            background: var(--horizon);
            color: var(--sky);
            font-weight: 700;
            text-decoration: none;
            padding: 13px 28px;
            border-radius: 999px;
            transition: filter 0.15s ease;
        }
        .cta:hover { filter: brightness(0.96); }
        .foot {
            font-size: 12px;
            color: var(--ink-3);
            margin-top: 28px;
        }
    </style>
</head>
<body>
    <div class="panel">
        <div class="code">@yield('code')</div>
        <h1 class="title">@yield('title')</h1>
        <p class="message">@yield('message')</p>
        <a class="cta" href="{{ url('/') }}">@yield('cta', 'Kembali ke Hari Ini')</a>
        <p class="foot">teman-lari · teman lari kamu di setiap langkah.</p>
    </div>
</body>
</html>
