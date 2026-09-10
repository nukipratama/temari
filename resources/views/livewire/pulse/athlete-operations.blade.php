<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Athletes" details="last sync and last delivery, per athlete">
        <x-slot:icon>
            <x-pulse::icons.clipboard />
        </x-slot:icon>
        <x-slot:actions>
            @include('livewire.pulse.partials.status-badge', ['severity' => $severity])
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        @if ($athletes->isEmpty())
            <x-pulse::no-results />
        @else
            <div class="space-y-2">
                @foreach ($athletes as $athlete)
                    <div class="rounded-sm bg-muted p-2">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                <span @class([
                                    'inline-block h-2 w-2 rounded-full shrink-0',
                                    'bg-ember' => in_array($athlete['syncStatus'], ['error', 'revoked'], true),
                                    'bg-stone' => $athlete['syncStatus'] === 'never',
                                    'bg-leaf' => ! in_array($athlete['syncStatus'], ['error', 'revoked', 'never'], true),
                                ])></span>
                                <span class="truncate text-sm font-bold text-foreground">{{ $athlete['name'] }}</span>
                                @if ($athlete['isDemo'])
                                    <span class="shrink-0 rounded-full bg-stone/15 px-2 py-0.5 text-label-micro text-text-2">demo</span>
                                @endif
                            </div>
                            <div class="shrink-0 text-label-micro tabular-nums text-text-3">
                                @if ($athlete['syncedAt'])
                                    synced {{ $athlete['syncedAt']->diffForHumans(short: true) }}
                                    @if ($athlete['syncPath'])
                                        · {{ $athlete['syncPath'] }}
                                    @endif
                                @else
                                    never synced
                                @endif
                            </div>
                        </div>

                        <div class="mt-1 flex flex-wrap items-center gap-1 text-label-micro">
                            @if ($athlete['syncStatus'] !== 'success' && $athlete['syncStatus'] !== 'never')
                                <span class="rounded-full bg-ember/15 px-2 py-0.5 text-ember-ink">sync {{ $athlete['syncStatus'] }}</span>
                            @endif

                            @forelse ($athlete['channels'] as $channel)
                                <span @class([
                                    'rounded-full px-2 py-0.5',
                                    'bg-ember/15 text-ember-ink' => $channel['status'] === 'failed',
                                    'bg-horizon/25 text-foreground' => $channel['status'] === 'pending',
                                    'bg-leaf/10 text-leaf-ink' => $channel['status'] === 'sent',
                                ])>
                                    {{ $channel['channel'] }} {{ $channel['status'] }}
                                    @if ($channel['at'])
                                        · {{ $channel['at']->diffForHumans(short: true) }}
                                    @endif
                                </span>
                            @empty
                                <span class="rounded-full bg-stone/15 px-2 py-0.5 text-text-2">no notification yet</span>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
