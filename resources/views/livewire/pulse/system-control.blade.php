<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="System Control">
        <x-slot:icon>
            <x-pulse::icons.command-line />
        </x-slot:icon>
        <x-slot:actions>
            @include('livewire.pulse.partials.status-badge', ['severity' => $severity])
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.15s="">
        <div class="space-y-4">
            <div>
                <div class="text-label-micro text-ink-3 mb-1">Kill-switches</div>
                <div class="grid grid-cols-2 gap-2">
                    <div @class([
                        'flex items-center justify-between rounded-sm p-2',
                        'bg-leaf/10' => $aiEnabled,
                        'bg-ember/15' => ! $aiEnabled,
                    ])>
                        <div>
                            <div class="text-sm font-bold text-ink">AI</div>
                            <div class="text-label-micro {{ $aiEnabled ? 'text-leaf-deep' : 'text-ember-deep' }}">
                                {{ $aiEnabled ? 'enabled' : 'disabled' }}
                            </div>
                        </div>
                        <button
                            wire:click="toggleAi"
                            class="rounded-sm bg-sky px-2 py-1 text-xs font-semibold text-cream hover:bg-sky-deep"
                        >
                            {{ $aiEnabled ? 'Disable' : 'Enable' }}
                        </button>
                    </div>

                    <div @class([
                        'flex items-center justify-between rounded-sm p-2',
                        'bg-leaf/10' => $stravaEnabled,
                        'bg-ember/15' => ! $stravaEnabled,
                    ])>
                        <div>
                            <div class="text-sm font-bold text-ink">Strava</div>
                            <div class="text-label-micro {{ $stravaEnabled ? 'text-leaf-deep' : 'text-ember-deep' }}">
                                {{ $stravaEnabled ? 'enabled' : 'disabled' }}
                            </div>
                        </div>
                        <button
                            wire:click="toggleStrava"
                            class="rounded-sm bg-sky px-2 py-1 text-xs font-semibold text-cream hover:bg-sky-deep"
                        >
                            {{ $stravaEnabled ? 'Disable' : 'Enable' }}
                        </button>
                    </div>
                </div>
            </div>

            <div>
                <div class="text-label-micro text-ink-3 mb-1">Strava circuit breaker</div>
                <div @class([
                    'flex items-center justify-between rounded-sm p-2',
                    'bg-leaf/10' => $breaker['state'] === 'closed',
                    'bg-horizon/25' => $breaker['state'] === 'half_open',
                    'bg-ember/15' => $breaker['state'] === 'open',
                ])>
                    <div>
                        <div class="text-sm font-bold text-ink">{{ str_replace('_', '-', $breaker['state']) }}</div>
                        <div class="text-label-micro text-ink-3">
                            {{ $breaker['failures'] }} failures
                            @if ($breaker['opened_at'])
                                · opened {{ \Illuminate\Support\Carbon::parse($breaker['opened_at'])->diffForHumans() }}
                            @endif
                        </div>
                    </div>
                    <button
                        wire:click="resetBreaker"
                        @disabled($breaker['state'] === 'closed' && $breaker['failures'] === 0)
                        class="rounded-sm bg-sky px-2 py-1 text-xs font-semibold text-cream hover:bg-sky-deep disabled:opacity-40"
                    >
                        Reset
                    </button>
                </div>
            </div>

            <div>
                <div class="text-label-micro text-ink-3 mb-1">Ingest backlog</div>
                <div class="grid grid-cols-2 gap-2">
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'pending',
                        'value' => number_format($pending),
                        'tone' => 'neutral',
                    ])
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'stranded',
                        'value' => number_format($stranded),
                        'tone' => $stranded > 0 ? 'alert' : 'neutral',
                    ])
                </div>
            </div>
        </div>
    </x-pulse::scroll>
</x-pulse::card>
