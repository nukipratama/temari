@props([
    'label',
    'value',
    'sub' => null,
    'tone' => 'neutral', // neutral | positive | warning | alert
])

@php
    $toneClasses = match ($tone) {
        'positive' => 'text-lime-600 dark:text-lime-400',
        'warning' => 'text-amber-600 dark:text-amber-400',
        'alert' => 'text-rose-600 dark:text-rose-400',
        default => 'text-[#1b1b18] dark:text-white',
    };
@endphp

<div class="rounded-2xl border border-black/5 bg-white p-4 dark:border-white/5 dark:bg-[#161615]">
    <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
        {{ $label }}
    </div>
    <div class="mt-2 text-2xl font-black tabular-nums {{ $toneClasses }}">
        {{ $value }}
    </div>
    @if ($sub !== null)
        <div class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ $sub }}</div>
    @endif
</div>
