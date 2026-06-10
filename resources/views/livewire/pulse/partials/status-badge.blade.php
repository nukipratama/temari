{{-- Glanceable card-level health pill. $severity is one of: ok | warn | alert. --}}
@props(['severity' => 'ok'])
@php
    $palette = [
        'ok' => ['pill' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400', 'dot' => 'bg-emerald-500'],
        'warn' => ['pill' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400', 'dot' => 'bg-amber-500'],
        'alert' => ['pill' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400', 'dot' => 'bg-rose-500'],
    ][$severity] ?? ['pill' => 'bg-gray-500/10 text-gray-600 dark:text-gray-400', 'dot' => 'bg-gray-500'];
@endphp
<span title="health: {{ $severity }}" class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $palette['pill'] }}">
    <span class="inline-block h-1.5 w-1.5 rounded-full {{ $palette['dot'] }}"></span>
    {{ $severity }}
</span>
