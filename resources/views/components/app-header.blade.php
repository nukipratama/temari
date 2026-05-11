@php
    $links = [
        ['route' => 'dashboard', 'icon' => 'mdi:home-outline', 'label' => 'Dashboard'],
        ['route' => 'runs.index', 'icon' => 'mdi:run-fast', 'label' => 'Runs'],
        ['route' => 'cards.index', 'icon' => 'mdi:cards-outline', 'label' => 'Cards'],
        ['route' => 'progress', 'icon' => 'mdi:chart-line', 'label' => 'Progress'],
    ];
    $current = request()->route()?->getName();
@endphp

<header class="border-b border-black/5 bg-white/60 backdrop-blur dark:border-white/5 dark:bg-[#0a0a0a]/60">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-4">
        <div class="flex items-center gap-6">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-lime-500 text-[#0a0a0a]">
                    <iconify-icon icon="mdi:run-fast" width="20" height="20" aria-hidden="true"></iconify-icon>
                </span>
                <span class="font-semibold tracking-tight">Teman Lari</span>
            </a>
            <nav class="hidden gap-1 sm:flex">
                @foreach ($links as $link)
                    @php
                        $active = $current === $link['route'];
                        $linkClass = $active
                            ? 'bg-lime-500/15 text-lime-700 dark:text-lime-300'
                            : 'text-gray-600 hover:text-[#1b1b18] hover:bg-black/[0.04] dark:text-gray-300 dark:hover:text-white dark:hover:bg-white/[0.06]';
                    @endphp
                    <a href="{{ route($link['route']) }}"
                       class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $linkClass }}">
                        <iconify-icon icon="{{ $link['icon'] }}" width="16" height="16" aria-hidden="true"></iconify-icon>
                        <span>{{ $link['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        </div>

        <div class="flex items-center gap-3">
            <div class="hidden text-right sm:block">
                <div class="text-sm font-medium leading-tight">{{ auth()->user()->name }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Strava ID {{ auth()->user()->stravaConnection?->strava_athlete_id }}</div>
            </div>
            @if (auth()->user()->avatar_url)
                <img src="{{ auth()->user()->avatar_url }}" alt="" class="h-9 w-9 rounded-full ring-2 ring-black/5 dark:ring-white/10">
            @else
                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-lime-500/15 text-sm font-semibold text-lime-600 dark:text-lime-400">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                </div>
            @endif
            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button type="submit" class="rounded-lg border border-black/10 px-3 py-1.5 text-sm transition hover:border-black/40 dark:border-white/10 dark:hover:border-white/40">
                    Log out
                </button>
            </form>
        </div>
    </div>
</header>
