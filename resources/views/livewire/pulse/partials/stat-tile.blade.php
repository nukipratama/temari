{{-- Dense KPI tile. $tone is one of: neutral | warn | alert. --}}
@props(['label' => '', 'value' => '', 'tone' => 'neutral'])
@php
    $tint = [
        'neutral' => 'bg-muted',
        'warn' => 'bg-horizon/25',
        'alert' => 'bg-ember/15',
    ][$tone] ?? 'bg-muted';
@endphp
<div class="rounded-sm p-2 text-center {{ $tint }}">
    <div class="font-mono text-lg font-bold tabular-nums text-foreground">{{ $value }}</div>
    <div class="text-label-micro text-text-3">{{ $label }}</div>
</div>
