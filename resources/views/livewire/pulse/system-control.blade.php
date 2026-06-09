<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="System Control">
        <x-slot:icon>
            <x-pulse::icons.command-line />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        <div class="space-y-4">
            {{-- Kill-switches --}}
            <div>
                <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Kill-switches</div>
                <div class="grid grid-cols-2 gap-2">
                    <div @class([
                        'flex items-center justify-between rounded-lg p-2 ring-1',
                        'ring-emerald-500/30 bg-emerald-500/5' => $aiEnabled,
                        'ring-rose-500/30 bg-rose-500/5' => ! $aiEnabled,
                    ])>
                        <div>
                            <div class="text-sm font-bold text-gray-900 dark:text-gray-100">AI</div>
                            <div class="text-[10px] uppercase tracking-wide {{ $aiEnabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                {{ $aiEnabled ? 'enabled' : 'disabled' }}
                            </div>
                        </div>
                        <button
                            wire:click="toggleAi"
                            class="rounded-md px-2 py-1 text-xs font-semibold ring-1 ring-gray-900/10 dark:ring-gray-100/20 hover:bg-gray-900/5 dark:hover:bg-gray-100/10"
                        >
                            {{ $aiEnabled ? 'Disable' : 'Enable' }}
                        </button>
                    </div>

                    <div @class([
                        'flex items-center justify-between rounded-lg p-2 ring-1',
                        'ring-emerald-500/30 bg-emerald-500/5' => $stravaEnabled,
                        'ring-rose-500/30 bg-rose-500/5' => ! $stravaEnabled,
                    ])>
                        <div>
                            <div class="text-sm font-bold text-gray-900 dark:text-gray-100">Strava</div>
                            <div class="text-[10px] uppercase tracking-wide {{ $stravaEnabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                {{ $stravaEnabled ? 'enabled' : 'disabled' }}
                            </div>
                        </div>
                        <button
                            wire:click="toggleStrava"
                            class="rounded-md px-2 py-1 text-xs font-semibold ring-1 ring-gray-900/10 dark:ring-gray-100/20 hover:bg-gray-900/5 dark:hover:bg-gray-100/10"
                        >
                            {{ $stravaEnabled ? 'Disable' : 'Enable' }}
                        </button>
                    </div>
                </div>
            </div>

            {{-- Strava circuit breaker --}}
            <div>
                <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Strava circuit breaker</div>
                <div @class([
                    'flex items-center justify-between rounded-lg p-2 ring-1',
                    'ring-emerald-500/30 bg-emerald-500/5' => $breaker['state'] === 'closed',
                    'ring-amber-500/30 bg-amber-500/5' => $breaker['state'] === 'half_open',
                    'ring-rose-500/30 bg-rose-500/5' => $breaker['state'] === 'open',
                ])>
                    <div>
                        <div class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ str_replace('_', '-', $breaker['state']) }}</div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $breaker['failures'] }} failures
                            @if ($breaker['opened_at'])
                                · opened {{ \Illuminate\Support\Carbon::parse($breaker['opened_at'])->diffForHumans() }}
                            @endif
                        </div>
                    </div>
                    <button
                        wire:click="resetBreaker"
                        @disabled($breaker['state'] === 'closed' && $breaker['failures'] === 0)
                        class="rounded-md px-2 py-1 text-xs font-semibold ring-1 ring-gray-900/10 dark:ring-gray-100/20 hover:bg-gray-900/5 dark:hover:bg-gray-100/10 disabled:opacity-40"
                    >
                        Reset
                    </button>
                </div>
            </div>

            {{-- Ingest backlog --}}
            <div>
                <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Ingest backlog</div>
                <div class="grid grid-cols-2 gap-2 text-center">
                    <div class="rounded-lg p-2 ring-1 ring-gray-900/5 dark:ring-gray-100/10">
                        <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($pending) }}</div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">pending</div>
                    </div>
                    <div @class([
                        'rounded-lg p-2 ring-1',
                        'ring-rose-500/30 bg-rose-500/5' => $stranded > 0,
                        'ring-gray-900/5 dark:ring-gray-100/10' => $stranded === 0,
                    ])>
                        <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($stranded) }}</div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">stranded</div>
                    </div>
                </div>
            </div>
        </div>
    </x-pulse::scroll>
</x-pulse::card>
