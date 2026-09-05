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
        <div class="text-label-micro text-text-3 mb-1">Now</div>
        <div class="grid grid-cols-4 gap-2 mb-4">
            @foreach ($statusBoxes as $box)
                @include('livewire.pulse.partials.stat-tile', [
                    'label' => $box['label'],
                    'value' => number_format($box['count']),
                    'tone' => $box['alert'] ? 'alert' : 'neutral',
                ])
            @endforeach
        </div>

        <div class="grid grid-cols-2 gap-2 mb-4">
            <div>
                <div class="text-label-micro text-text-3 mb-1">Dead-letter</div>
                <div @class([
                    'rounded-sm p-2 text-center',
                    'bg-ember/15' => $deadLettered > 0,
                    'bg-muted' => $deadLettered === 0,
                ])>
                    <div class="font-mono text-lg font-bold tabular-nums text-foreground">{{ number_format($deadLettered) }}</div>
                    @if ($deadLettered > 0)
                        <a href="{{ url('/devtools/ai-usage') }}" class="block text-label-micro text-ember-ink underline">
                            /ai-usage
                        </a>
                    @else
                        <div class="text-label-micro text-text-3">gave up</div>
                    @endif
                </div>
            </div>
            <div>
                <div class="text-label-micro text-text-3 mb-1">Failed jobs</div>
                @include('livewire.pulse.partials.stat-tile', [
                    'label' => 'in failed_jobs',
                    'value' => number_format($failedJobs),
                    'tone' => $failedJobs > 0 ? 'alert' : 'neutral',
                ])
            </div>
        </div>

        <div class="mb-4">
            <div class="text-label-micro text-text-3 mb-1">AI generation</div>
            <div @class([
                'rounded-sm p-2 text-xs font-semibold',
                'bg-leaf/10 text-leaf-ink' => $pauseReason === null,
                'bg-horizon/25 text-foreground' => $pauseReason !== null,
            ])>
                {{-- Only a null reason is healthy. A @default that fell through to
                     "healthy" is why an unrecognised pause read green while the box
                     styled itself warn, and it is why nothing dispatching went
                     unnoticed. An unmapped reason prints itself instead. --}}
                @if ($pauseReason === null)
                    healthy
                @else
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
                        @case('config')
                            paused: check API key / base URL
                            @break
                        @default
                            paused: {{ $pauseReason }}
                    @endswitch
                @endif
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
                                <code class="block text-xs text-foreground truncate" title="{{ class_basename($failure->subject_type) }} #{{ $failure->subject_id }} · {{ $failure->analysis_type }}">
                                    {{ class_basename($failure->subject_type) }} #{{ $failure->subject_id }} · {{ $failure->analysis_type }}
                                </code>
                                <p class="mt-1 text-xs text-text-3 truncate" title="{{ $failure->error }}">
                                    {{ \Illuminate\Support\Str::limit((string) $failure->error, 120) }}
                                </p>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-text-2 font-bold whitespace-nowrap">
                                {{ \Illuminate\Support\Carbon::parse($failure->updated_at)->ago(syntax: Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }}
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
