<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Scheduler" details="next due first">
        <x-slot:icon>
            <x-pulse::icons.clock />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" class="temari-card-body" wire:poll.30s="">
        @if ($tasks->isEmpty())
            <x-pulse::no-results />
        @else
            @php($duration = static fn (int $ms): string => $ms >= 1000 ? round($ms / 1000, 1).'s' : $ms.'ms')
            <ol class="space-y-2">
                @foreach ($tasks as $task)
                    <li class="rounded-sm bg-muted p-2">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                <span @class([
                                    'inline-block h-2 w-2 rounded-full shrink-0',
                                    'bg-ember' => in_array($task['status'], ['failed', 'killed'], true),
                                    'bg-horizon' => $task['status'] === 'late',
                                    'bg-leaf' => $task['status'] === 'ok',
                                    'bg-stone' => $task['status'] === 'never run',
                                ])></span>
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-bold text-foreground">{{ $task['command'] }}</div>
                                    <div class="text-label-micro text-text-3">
                                        @if ($task['lastRunAt'])
                                            ran {{ $task['lastRunAt']->diffForHumans() }}
                                        @else
                                            never run
                                        @endif
                                        @if ($task['runtimeMs'] !== null)
                                            · {{ $duration($task['runtimeMs']) }}
                                        @endif
                                        @if ($task['nextDue'])
                                            · next {{ $task['nextDue']->diffForHumans() }}
                                        @else
                                            · off the schedule
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div @class([
                                'shrink-0 rounded-full px-2 py-0.5 text-label-micro',
                                'bg-ember/15 text-ember-ink' => in_array($task['status'], ['failed', 'killed'], true),
                                'bg-horizon/25 text-foreground' => $task['status'] === 'late',
                                'bg-leaf/10 text-leaf-ink' => $task['status'] === 'ok',
                                'bg-stone/15 text-text-2' => $task['status'] === 'never run',
                            ])>
                                {{ $task['status'] }}
                            </div>
                        </div>

                        @if ($task['history'] !== null)
                            @php($history = $task['history'])
                            <div class="mt-1 text-label-micro text-text-3">
                                30d · {{ $history['runs'] }} {{ $history['runs'] === 1 ? 'run' : 'runs' }}
                                · {{ $history['failures'] }} failed
                                · {{ $history['skips'] }} skipped
                                @if ($history['killed'] > 0)
                                    · <span class="text-ember-ink">{{ $history['killed'] }} killed</span>
                                @endif
                                @if ($history['p50'] !== null)
                                    · p50 {{ $duration($history['p50']) }} · p95 {{ $duration($history['p95']) }} · max {{ $duration($history['max']) }}
                                @endif
                            </div>
                        @endif

                        @if ($task['prerequisites'] !== [])
                            <div class="mt-1 flex flex-wrap items-center gap-1 text-label-micro text-text-3">
                                <span>waits for</span>
                                @foreach ($task['prerequisites'] as $prerequisite)
                                    <span @class([
                                        'rounded-full px-2 py-0.5',
                                        'bg-leaf/10 text-leaf-ink' => $prerequisite['met'],
                                        'bg-stone/15 text-text-2' => ! $prerequisite['met'],
                                    ])>
                                        {{ $prerequisite['command'] }} {{ $prerequisite['met'] ? 'done' : 'pending' }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        @if ($task['status'] === 'failed' && $task['failureMessage'])
                            <div class="mt-1 truncate text-xs text-ember-ink" title="{{ $task['failureMessage'] }}">
                                {{ $task['failureMessage'] }}
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
