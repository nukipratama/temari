<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Scheduler">
        <x-slot:icon>
            <x-pulse::icons.clock />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($tasks->isEmpty())
            <x-pulse::no-results />
        @else
            <div class="space-y-2">
                @foreach ($tasks as $task)
                    <div @class([
                        'rounded-lg p-2 ring-1',
                        'ring-rose-500/30 bg-rose-500/5' => $task['status'] === 'failed',
                        'ring-amber-500/30 bg-amber-500/5' => $task['status'] === 'late',
                        'ring-gray-900/5 dark:ring-gray-100/10' => $task['status'] === 'ok',
                    ])>
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-bold text-gray-900 dark:text-gray-100">{{ $task['command'] }}</div>
                                <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    @if ($task['lastRunAt'])
                                        ran {{ $task['lastRunAt']->diffForHumans() }}
                                    @else
                                        never run
                                    @endif
                                    @if ($task['runtimeMs'] !== null)
                                        · {{ $task['runtimeMs'] >= 1000 ? round($task['runtimeMs'] / 1000, 1).'s' : $task['runtimeMs'].'ms' }}
                                    @endif
                                </div>
                            </div>
                            <div @class([
                                'shrink-0 rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                'bg-rose-500/10 text-rose-600 dark:text-rose-400' => $task['status'] === 'failed',
                                'bg-amber-500/10 text-amber-600 dark:text-amber-400' => $task['status'] === 'late',
                                'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' => $task['status'] === 'ok',
                            ])>
                                {{ $task['status'] }}
                            </div>
                        </div>

                        @if ($task['status'] === 'failed' && $task['failureMessage'])
                            <div class="mt-1 truncate text-[11px] text-rose-600 dark:text-rose-400" title="{{ $task['failureMessage'] }}">
                                {{ $task['failureMessage'] }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
