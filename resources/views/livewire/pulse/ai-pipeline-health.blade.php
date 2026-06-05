<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="AI Pipeline"
        details="failed {{ $this->periodForHumans() }}: {{ number_format($failuresInPeriod) }}"
    >
        <x-slot:icon>
            <x-pulse::icons.sparkles />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        <div class="grid grid-cols-5 gap-2 mb-4">
            @foreach ($statuses as $status => $count)
                <div @class([
                    'rounded-lg p-2 text-center ring-1',
                    'ring-rose-500/30 bg-rose-500/5' => $status === 'failed' && $count > 0,
                    'ring-gray-900/5 dark:ring-gray-100/10' => ! ($status === 'failed' && $count > 0),
                ])>
                    <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($count) }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $status }}</div>
                </div>
            @endforeach
        </div>

        @if ($recentFailures->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Subject</x-pulse::th>
                        <x-pulse::th class="text-right">Attempts</x-pulse::th>
                        <x-pulse::th class="text-right">Last</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($recentFailures as $failure)
                        <tr wire:key="{{ $failure->subject_type }}-{{ $failure->subject_id }}-{{ $failure->analysis_type }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $failure->subject_type }}-{{ $failure->subject_id }}-{{ $failure->analysis_type }}-row">
                            <x-pulse::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ class_basename($failure->subject_type) }} #{{ $failure->subject_id }} · {{ $failure->analysis_type }}">
                                    {{ class_basename($failure->subject_type) }} #{{ $failure->subject_id }} · {{ $failure->analysis_type }}
                                </code>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $failure->error }}">
                                    {{ \Illuminate\Support\Str::limit((string) $failure->error, 120) }}
                                </p>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ number_format($failure->attempts) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold whitespace-nowrap">
                                {{ \Illuminate\Support\Carbon::parse($failure->updated_at)->ago(syntax: Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }}
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
