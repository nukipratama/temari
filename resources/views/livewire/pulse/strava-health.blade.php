<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Strava"
        details="webhook {{ $this->periodForHumans() }}: {{ number_format($trends['webhook']) }}"
    >
        <x-slot:icon>
            <x-pulse::icons.arrows-left-right />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
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

            <div>
                <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">API Rate Limits</div>
                <div class="space-y-2">
                    @foreach ($rateLimits as $label => $bucket)
                        @php($pct = $bucket['max'] > 0 ? (int) round($bucket['remaining'] / $bucket['max'] * 100) : 0)
                        <div>
                            <div class="flex justify-between text-xs text-gray-700 dark:text-gray-300">
                                <span>{{ $label }}</span>
                                <span class="tabular-nums">{{ number_format($bucket['remaining']) }} / {{ number_format($bucket['max']) }}</span>
                            </div>
                            <div class="mt-1 h-1.5 rounded-full bg-gray-200 dark:bg-gray-800 overflow-hidden">
                                <div @class([
                                    'h-full rounded-full',
                                    'bg-rose-500' => $pct <= 15,
                                    'bg-amber-500' => $pct > 15 && $pct <= 40,
                                    'bg-emerald-500' => $pct > 40,
                                ]) style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-4 gap-2 text-center">
                <div>
                    <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($stranded) }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">stranded</div>
                </div>
                <div>
                    <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($trends['synced']) }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">synced</div>
                </div>
                <div>
                    <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($trends['rate_limited']) }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">rate limited</div>
                </div>
                <div>
                    <div class="text-lg font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($trends['revoked']) }}</div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">revoked</div>
                </div>
            </div>

            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                @if ($webhookStatus['configured'])
                    <span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
                    <span>Webhook subscribed (ID: {{ $webhookStatus['subscription_id'] }})</span>
                @else
                    <span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span>
                    <span>Webhook not configured</span>
                @endif
            </div>
        </div>
    </x-pulse::scroll>
</x-pulse::card>
