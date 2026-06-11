<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Strava"
        details="webhook {{ $this->periodForHumans() }}: {{ number_format($trends['webhook']) }}"
    >
        <x-slot:icon>
            <x-pulse::icons.arrows-left-right />
        </x-slot:icon>
        <x-slot:actions>
            @include('livewire.pulse.partials.status-badge', ['severity' => $severity])
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        <div class="space-y-4">
            <div>
                <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Connections</div>
                <div class="grid grid-cols-3 gap-2">
                    <div class="rounded-lg p-2 text-center ring-1 ring-gray-900/5 dark:ring-gray-100/10">
                        <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($connections['active']) }}</div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">active</div>
                    </div>
                    <div class="rounded-lg p-2 text-center ring-1 ring-gray-900/5 dark:ring-gray-100/10">
                        <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($connections['token_expired']) }}</div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">expired</div>
                    </div>
                    <div @class([
                        'rounded-lg p-2 text-center ring-1',
                        'ring-rose-500/30 bg-rose-500/5' => $connections['revoked'] > 0,
                        'ring-gray-900/5 dark:ring-gray-100/10' => $connections['revoked'] === 0,
                    ])>
                        <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($connections['revoked']) }}</div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">revoked</div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-4 gap-2 text-center">
                <div @class([
                    'rounded-lg p-1 ring-1',
                    'ring-amber-500/30 bg-amber-500/5' => $stranded > 0,
                    'ring-transparent' => $stranded === 0,
                ])>
                    <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($stranded) }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">stranded</div>
                </div>
                <div class="p-1">
                    <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($trends['synced']) }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">synced</div>
                </div>
                <div @class([
                    'rounded-lg p-1 ring-1',
                    'ring-amber-500/30 bg-amber-500/5' => $trends['rate_limited'] > 0,
                    'ring-transparent' => $trends['rate_limited'] === 0,
                ])>
                    <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($trends['rate_limited']) }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">rate limited</div>
                </div>
                <div @class([
                    'rounded-lg p-1 ring-1',
                    'ring-rose-500/30 bg-rose-500/5' => $trends['revoked'] > 0,
                    'ring-transparent' => $trends['revoked'] === 0,
                ])>
                    <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($trends['revoked']) }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">revoked</div>
                </div>
            </div>

            @if ($perUser !== [])
                <div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Per-User Sync Status</div>
                    <div class="space-y-1">
                        @foreach ($perUser as $row)
                            <div @class([
                                'flex items-center justify-between text-xs px-2 py-1 rounded',
                                'bg-rose-500/5 ring-1 ring-rose-500/30' => $row['is_failed'],
                                'ring-1 ring-gray-900/5 dark:ring-gray-100/10' => ! $row['is_failed'],
                            ])>
                                <div class="flex items-center gap-2 min-w-0">
                                    <span @class([
                                        'inline-block h-1.5 w-1.5 rounded-full shrink-0',
                                        'bg-rose-500' => $row['is_failed'],
                                        'bg-emerald-500' => ! $row['is_failed'],
                                    ])></span>
                                    <span class="truncate text-gray-900 dark:text-gray-100">{{ $row['user_name'] }}</span>
                                </div>
                                <div class="flex items-center gap-3 shrink-0 tabular-nums text-gray-500 dark:text-gray-400">
                                    <span title="15 min remaining">{{ $row['15min_remaining'] }}/200</span>
                                    <span title="Daily remaining">{{ $row['daily_remaining'] }}/2k</span>
                                    @if ($row['last_sync'])
                                        <span>{{ \Illuminate\Support\Carbon::parse($row['last_sync'])->diffForHumans(short: true) }}</span>
                                    @else
                                        <span class="text-gray-400">never</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                @if ($webhookStatus['configured'])
                    <span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
                    <span>Webhook subscribed</span>
                @else
                    <span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span>
                    <span>Webhook not configured</span>
                @endif
            </div>
        </div>
    </x-pulse::scroll>
</x-pulse::card>
