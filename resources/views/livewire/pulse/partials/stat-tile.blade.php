{{-- Dense KPI tile. $tone is one of: neutral | warn | alert. --}}
@props(['label' => '', 'value' => '', 'tone' => 'neutral'])
@php
    $tint = [
        'neutral' => 'bg-surface-sunken',
        'warn' => 'bg-horizon/25',
        'alert' => 'bg-ember/15',
    ][$tone] ?? 'bg-surface-sunken';
@endphp
<div class="rounded-sm p-2 text-center {{ $tint }}">
    <div class="font-mono text-lg font-bold tabular-nums text-ink">{{ $value }}</div>
    <div class="text-label-micro text-ink-3">{{ $label }}</div>
</div>
