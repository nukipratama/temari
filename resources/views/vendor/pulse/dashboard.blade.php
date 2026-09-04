@php
    // Pulse's layout loads only the packaged pulse.css; app.css is what makes the
    // --color-* tokens resolve in the first-party cards below.
    // Pulse's own Tailwind v3 build is unlayered and so outranks every layered
    // rule app.css can write; the doubled class raises specificity above it
    // without depending on stylesheet order. Its own dark steps are too dark to
    // read on its gray-900 card, so the override is per ground.
    // Pulse themes itself off `localStorage.theme` and a `dark` class, while the
    // first-party cards below read the app's own --color-* tokens off
    // `data-theme`. Both are driven from the app's stored ground so the console
    // is one product rather than a light page hosting dark cards.
    $theme = <<<'HTML'
        <script>
            (() => {
                let ground = 'dark';
                try { ground = localStorage.getItem('temari-theme') ?? 'dark'; } catch {}
                if (ground === 'system') {
                    ground = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                try { localStorage.theme = ground; } catch {}
                document.documentElement.dataset.theme = ground;
                document.documentElement.classList.toggle('dark', ground === 'dark');
            })();
        </script>
        HTML;

    $contrast = <<<'CSS'
        <style>
            .text-gray-300.text-gray-300,
            .text-gray-400.text-gray-400 { color: #6b7280; }
            .hover\:text-gray-400.hover\:text-gray-400:hover { color: #4b5563; }

            .dark .text-gray-300.text-gray-300,
            .dark .text-gray-400.text-gray-400,
            .dark .dark\:text-gray-600.dark\:text-gray-600 { color: #9ca3af; }
            .dark .hover\:text-gray-400.hover\:text-gray-400:hover { color: #d1d5db; }
        </style>
        CSS;

    \Laravel\Pulse\Facades\Pulse::css([
        new \Illuminate\Support\HtmlString($theme),
        app(\Illuminate\Foundation\Vite::class)(['resources/css/app.css']),
        new \Illuminate\Support\HtmlString($contrast),
    ]);
@endphp
<x-pulse>
    {{-- Pulse carries none of the app's chrome, so this is the only way back. --}}
    <div class="col-span-full -mb-2">
        <a href="/devtools" class="text-label-micro font-semibold text-text-3 transition hover:text-foreground">
            &larr; Temari &middot; Devtools
        </a>
    </div>

    {{-- Host vitals (CPU/memory/disk) lead: is the box healthy? --}}
    <livewire:pulse.servers cols="full" />

    {{-- Domain-specific health cards. --}}
    <livewire:pulse.ai-pipeline-health cols="6" rows="2" />

    <livewire:pulse.strava-health cols="6" rows="2" />

    {{-- Delivery + retry-budget detail behind the pipeline rollup. --}}
    <livewire:pulse.notification-delivery-health cols="6" rows="2" />

    <livewire:pulse.self-heal-attempts cols="6" rows="2" />

    {{-- Did the scheduled commands actually run? --}}
    <livewire:pulse.scheduler-health cols="full" />

    {{-- Emergency controls — demoted below the health overview. --}}
    <livewire:pulse.system-control cols="full" rows="2" />

    {{-- Stock performance cards. --}}
    <livewire:pulse.queues cols="6" />

    <livewire:pulse.exceptions cols="6" />

    <livewire:pulse.slow-requests cols="full" />
</x-pulse>
