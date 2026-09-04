{{-- Glanceable card-level health pill. $severity is one of: ok | warn | alert. --}}
@props(['severity' => 'ok'])
@php
    $palette = [
        'ok' => ['pill' => 'bg-leaf/10 text-leaf-ink', 'dot' => 'bg-leaf'],
        'warn' => ['pill' => 'bg-horizon/15 text-horizon-ink', 'dot' => 'bg-horizon'],
        'alert' => ['pill' => 'bg-ember/10 text-ember-ink', 'dot' => 'bg-ember'],
    ][$severity] ?? ['pill' => 'bg-stone/15 text-text-2', 'dot' => 'bg-stone'];
@endphp
<span title="health: {{ $severity }}" class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-label-micro {{ $palette['pill'] }}">
    <span class="inline-block h-1.5 w-1.5 rounded-full {{ $palette['dot'] }}"></span>
    {{ $severity }}
</span>
