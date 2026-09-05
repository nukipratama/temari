<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Scheduler">
        <x-slot:icon>
            <x-pulse::icons.clock />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        @if ($tasks->isEmpty())
            <x-pulse::no-results />
        @else
            <div class="space-y-2">
                @foreach ($tasks as $task)
                    <div class="rounded-sm bg-muted p-2">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                <span @class([
                                    'inline-block h-2 w-2 rounded-full shrink-0',
                                    'bg-ember' => $task['status'] === 'failed',
                                    'bg-horizon' => $task['status'] === 'late',
                                    'bg-leaf' => $task['status'] === 'ok',
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
                                            · {{ $task['runtimeMs'] >= 1000 ? round($task['runtimeMs'] / 1000, 1).'s' : $task['runtimeMs'].'ms' }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div @class([
                                'shrink-0 rounded-full px-2 py-0.5 text-label-micro',
                                'bg-ember/15 text-ember-ink' => $task['status'] === 'failed',
                                'bg-horizon/25 text-foreground' => $task['status'] === 'late',
                                'bg-leaf/10 text-leaf-ink' => $task['status'] === 'ok',
                            ])>
                                {{ $task['status'] }}
                            </div>
                        </div>

                        @if ($task['status'] === 'failed' && $task['failureMessage'])
                            <div class="mt-1 truncate text-xs text-ember-ink" title="{{ $task['failureMessage'] }}">
                                {{ $task['failureMessage'] }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
