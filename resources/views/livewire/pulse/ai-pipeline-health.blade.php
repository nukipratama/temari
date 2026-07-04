<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="AI Pipeline"
        details="failed last {{ $this->periodForHumans() }}: {{ number_format($trend['failures']) }}"
    >
        <x-slot:icon>
            <x-pulse::icons.sparkles />
        </x-slot:icon>
        <x-slot:actions>
            @include('livewire.pulse.partials.status-badge', ['severity' => $severity])
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Now</div>
        <div class="grid grid-cols-4 gap-2 mb-4">
            @foreach ($statusBoxes as $box)
                <div @class([
                    'rounded-lg p-2 text-center ring-1',
                    'ring-rose-500/30 bg-rose-500/5' => $box['alert'],
                    'ring-gray-900/5 dark:ring-gray-100/10' => ! $box['alert'],
                ])>
                    <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($box['count']) }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $box['label'] }}</div>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-2 gap-2 mb-4">
            <div>
                <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Dead-letter</div>
                <div @class([
                    'flex items-center justify-between rounded-lg p-2 ring-1',
                    'ring-rose-500/30 bg-rose-500/5' => $deadLettered > 0,
                    'ring-gray-900/5 dark:ring-gray-100/10' => $deadLettered === 0,
                ])>
                    <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($deadLettered) }}</div>
                    @if ($deadLettered > 0)
                        <a href="{{ url('/ai-usage') }}" class="text-[10px] font-semibold uppercase tracking-wide text-rose-600 dark:text-rose-400 underline">
                            /ai-usage
                        </a>
                    @endif
                </div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Failed jobs</div>
                <div @class([
                    'rounded-lg p-2 ring-1',
                    'ring-rose-500/30 bg-rose-500/5' => $failedJobs > 0,
                    'ring-gray-900/5 dark:ring-gray-100/10' => $failedJobs === 0,
                ])>
                    <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($failedJobs) }}</div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">AI generation</div>
            <div @class([
                'rounded-lg p-2 ring-1 text-xs font-semibold',
                'ring-emerald-500/30 bg-emerald-500/5 text-emerald-600 dark:text-emerald-400' => $pauseReason === null,
                'ring-amber-500/30 bg-amber-500/5 text-amber-600 dark:text-amber-400' => $pauseReason !== null,
            ])>
                @switch($pauseReason)
                    @case('kill_switch')
                        paused: kill switch off
                        @break
                    @case('unconfigured')
                        paused: Azure unconfigured
                        @break
                    @case('cost_ceiling')
                        paused: cost ceiling hit today
                        @break
                    @default
                        healthy
                @endswitch
            </div>
        </div>

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
                        <x-pulse::th>Subject</x-pulse::th>
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
