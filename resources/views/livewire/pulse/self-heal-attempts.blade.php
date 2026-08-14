<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Self-Heal Budget"
        details="max {{ $max }} attempts per block"
    >
        <x-slot:icon>
            <x-pulse::icons.scale />
        </x-slot:icon>
        <x-slot:actions>
            @include('livewire.pulse.partials.status-badge', ['severity' => $severity])
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        <div class="text-label-micro text-ink-3 mb-1">Failed blocks by attempts used</div>
        <div class="mb-4 flex gap-2 [&>*]:flex-1">
            @foreach ($buckets as $bucket)
                @include('livewire.pulse.partials.stat-tile', [
                    'label' => $bucket['label'],
                    'value' => number_format($bucket['count']),
                    'tone' => $bucket['count'] > 0 ? $bucket['tone'] : 'neutral',
                ])
            @endforeach
        </div>

        @if ($blocks->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Block</x-pulse::th>
                        <x-pulse::th class="text-right">Attempts</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($blocks as $block)
                        <tr wire:key="{{ $block->subject_type }}-{{ $block->subject_id }}-{{ $block->analysis_type }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $block->subject_type }}-{{ $block->subject_id }}-{{ $block->analysis_type }}-row">
                            <x-pulse::td class="max-w-[1px]">
                                <code class="block truncate text-xs text-ink" title="{{ class_basename($block->subject_type) }} #{{ $block->subject_id }} · {{ $block->analysis_type }}">
                                    {{ class_basename($block->subject_type) }} #{{ $block->subject_id }} · {{ $block->analysis_type }}
                                </code>
                                <p class="mt-1 truncate text-xs text-ink-3" title="{{ $block->error }}">
                                    {{ \Illuminate\Support\Str::limit((string) $block->error, 120) }}
                                </p>
                            </x-pulse::td>
                            <x-pulse::td numeric class="whitespace-nowrap">
                                <span @class([
                                    'rounded-full px-2 py-0.5 font-mono text-xs font-bold tabular-nums',
                                    'bg-ember/15 text-ember-ink' => $block->attempts >= $max,
                                    'bg-horizon/25 text-ink' => $block->attempts === $max - 1,
                                    'text-ink-2' => $block->attempts < $max - 1,
                                ])>
                                    {{ $block->attempts }}/{{ $max }}
                                </span>
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
