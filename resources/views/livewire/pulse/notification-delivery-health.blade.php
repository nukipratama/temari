<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Notification Delivery">
        <x-slot:icon>
            <x-pulse::icons.cloud-arrow-up />
        </x-slot:icon>
        <x-slot:actions>
            @include('livewire.pulse.partials.status-badge', ['severity' => $severity])
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        <div class="grid grid-cols-3 gap-2 mb-4">
            @foreach ($statusBoxes as $box)
                @include('livewire.pulse.partials.stat-tile', [
                    'label' => $box['label'],
                    'value' => number_format($box['count']),
                    'tone' => $box['tone'],
                ])
            @endforeach
        </div>

        @if ($channels !== [])
            <div class="mb-4">
                <div class="text-label-micro text-ink-3 mb-1">Per channel</div>
                <div class="space-y-1">
                    @foreach ($channels as $channel)
                        <div class="flex items-center justify-between rounded-sm bg-surface-sunken px-2 py-1 text-xs">
                            <span class="truncate text-ink">{{ $channel['channel'] }}</span>
                            <span class="flex shrink-0 items-center gap-3 font-mono tabular-nums">
                                <span class="text-ink-3">{{ number_format($channel['sent']) }} sent</span>
                                <span class="text-ink-3">{{ number_format($channel['pending']) }} in flight</span>
                                <span class="{{ $channel['failed'] > 0 ? 'text-ember-deep font-bold' : 'text-ink-3' }}">{{ number_format($channel['failed']) }} failed</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($recentFailures->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Failed send</x-pulse::th>
                        <x-pulse::th class="text-right">When</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($recentFailures as $failure)
                        <tr wire:key="{{ $failure->analysis_id }}-{{ $failure->channel }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $failure->analysis_id }}-{{ $failure->channel }}-row">
                            <x-pulse::td class="max-w-[1px]">
                                <code class="block truncate text-xs text-ink">
                                    {{ $failure->channel }} · analysis #{{ $failure->analysis_id }}
                                </code>
                                <p class="mt-1 truncate text-xs text-ember-deep" title="{{ $failure->error }}">
                                    {{ \Illuminate\Support\Str::limit((string) $failure->error, 120) }}
                                </p>
                            </x-pulse::td>
                            <x-pulse::td numeric class="whitespace-nowrap font-bold text-ink-2">
                                {{ $failure->settled_at?->ago(syntax: Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) ?? '—' }}
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
