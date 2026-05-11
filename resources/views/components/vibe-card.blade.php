@props([
    /** Internal vibe key: bouncy, steady, worn_down, cooked, fresh, stretched_thin, pumped, hibernating */
    'state',
    /** Optional Temari greeting line to render under the headline. */
    'greeting' => null,
])

@php
    use App\Services\Run\Story\Vibe;

    $label = Vibe::label($state);
    $emoji = Vibe::emoji($state);

    $tone = match ($state) {
        Vibe::PUMPED, Vibe::FRESH, Vibe::BOUNCY => 'bg-gradient-to-br from-lime-100 to-lime-200 dark:from-lime-900/40 dark:to-lime-800/40',
        Vibe::COOKED, Vibe::STRETCHED_THIN => 'bg-gradient-to-br from-rose-100 to-rose-200 dark:from-rose-900/40 dark:to-rose-800/40',
        Vibe::WORN_DOWN => 'bg-gradient-to-br from-amber-100 to-amber-200 dark:from-amber-900/40 dark:to-amber-800/40',
        Vibe::HIBERNATING => 'bg-gradient-to-br from-slate-100 to-slate-200 dark:from-slate-800/60 dark:to-slate-700/60',
        default => 'bg-gradient-to-br from-sky-50 to-sky-100 dark:from-sky-900/30 dark:to-sky-800/30',
    };
@endphp

<div {{ $attributes->merge(['class' => "rounded-2xl border border-black/5 p-6 dark:border-white/5 {$tone}"]) }}>
    <div class="flex items-baseline justify-between gap-4">
        <div>
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">
                Vibe hari ini
            </div>
            <div class="mt-1 flex items-baseline gap-3">
                <span class="text-4xl">{{ $emoji }}</span>
                <span class="text-3xl font-black tracking-tight">{{ $label }}</span>
            </div>
        </div>
    </div>
    @if ($greeting !== null)
        <p class="mt-4 text-sm leading-relaxed text-gray-700 dark:text-gray-200">
            {{ $greeting }}
        </p>
    @endif
</div>
