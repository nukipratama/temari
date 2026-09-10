<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Burn-down" details="today's budgets, app-wide">
        <x-slot:icon>
            <x-pulse::icons.scale />
        </x-slot:icon>
        <x-slot:actions>
            @include('livewire.pulse.partials.status-badge', ['severity' => $severity])
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        <div class="space-y-3">
            @foreach ($bars as $bar)
                <div>
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-label-micro text-text-3">{{ $bar['label'] }}</span>
                        <span class="font-mono text-xs tabular-nums text-foreground">
                            {{ $bar['used'] }}@if ($bar['ceiling'])<span class="text-text-3"> / {{ $bar['ceiling'] }}</span>@endif
                        </span>
                    </div>
                    <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-muted">
                        <div @class([
                            'h-full rounded-full',
                            'bg-ember' => $bar['tone'] === 'alert',
                            'bg-horizon' => $bar['tone'] === 'warn',
                            'bg-leaf' => $bar['tone'] === 'neutral',
                        ]) style="width: {{ $bar['width'] }}%"></div>
                    </div>
                    <div class="mt-0.5 text-label-micro text-text-3">
                        @if ($bar['pct'] === null)
                            no ceiling configured
                        @else
                            {{ $bar['pct'] }}% spent
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-pulse::scroll>
</x-pulse::card>
