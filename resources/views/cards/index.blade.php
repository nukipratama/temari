@extends('layouts.app')

@section('content')
<div class="min-h-screen bg-[#FAFAF7] dark:bg-[#0a0a0a]">
    <x-app-header />

    <main class="mx-auto max-w-6xl px-6 py-10">
        <div class="mb-6">
            <h1 class="text-2xl font-semibold tracking-tight">Run Cards</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                Setiap run dapat satu kartu. Rarity naik untuk PR, negative split, atau run terjauh.
            </p>
        </div>

        <nav class="mb-6 flex flex-wrap gap-2 text-sm">
            <a href="{{ route('cards.index') }}"
               class="rounded-full border border-black/10 px-3 py-1 dark:border-white/10 {{ $selectedRarity === null ? 'bg-black/[0.05] dark:bg-white/[0.08]' : '' }}">
                Semua
            </a>
            @foreach (\App\Models\RunCard::RARITY_LABELS as $key => $label)
                <a href="{{ route('cards.index', ['rarity' => $key]) }}"
                   class="rounded-full border border-black/10 px-3 py-1 dark:border-white/10 {{ $selectedRarity === $key ? 'bg-black/[0.05] dark:bg-white/[0.08]' : '' }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        @if ($cards->isEmpty())
            <div class="rounded-2xl border border-dashed border-black/10 bg-white/40 p-10 text-center dark:border-white/10 dark:bg-[#161615]/40">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Belum ada kartu di rarity ini.
                </p>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($cards as $card)
                    @if ($card->activity?->detail)
                        <a href="{{ route('runs.show', $card->activity_id) }}" class="block transition hover:-translate-y-0.5">
                            <x-run-card :card="$card" :detail="$card->activity->detail" />
                        </a>
                    @endif
                @endforeach
            </div>
            <div class="mt-6">
                {{ $cards->links() }}
            </div>
        @endif
    </main>
</div>
@endsection
