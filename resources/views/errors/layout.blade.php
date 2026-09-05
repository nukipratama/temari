<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Temari</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <style>
        :root {
            --sky: #171f28;
            --cream: #f1f5f8;
            --cream-deep: #e2e8ee;
            --ink: #16181b;
            --ink-2: #34373c;
            --ink-3: #60666d;
            --horizon: #ade047;
            --horizon-ink: #546d23;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
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
            font-family: Georgia, 'Times New Roman', serif;
            font-style: italic;
            font-size: 88px;
            line-height: 1;
            font-weight: 500;
            color: var(--horizon-ink);
        }
        .title {
            font-family: Georgia, 'Times New Roman', serif;
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
        <a class="cta" href="{{ url('/') }}">@yield('cta', 'Back to Today')</a>
        <p class="foot">Temari · your running companion, every step.</p>
    </div>
</body>
</html>
