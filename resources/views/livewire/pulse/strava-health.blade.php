<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Strava"
        details="webhook last {{ $this->periodForHumans() }}: {{ number_format($trends['webhook']) }}"
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
                <div class="text-label-micro text-ink-3 mb-1">Now</div>
                <div class="grid grid-cols-4 gap-2">
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'active',
                        'value' => number_format($connections['active']),
                        'tone' => 'neutral',
                    ])
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'expired',
                        'value' => number_format($connections['token_expired']),
                        'tone' => 'neutral',
                    ])
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'revoked',
                        'value' => number_format($connections['revoked']),
                        'tone' => $connections['revoked'] > 0 ? 'alert' : 'neutral',
                    ])
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'stranded',
                        'value' => number_format($stranded),
                        'tone' => $stranded > 0 ? 'warn' : 'neutral',
                    ])
                </div>
            </div>

            <div>
                <div class="text-label-micro text-ink-3 mb-1">Last {{ $this->periodForHumans() }}</div>
                <div class="grid grid-cols-3 gap-2">
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'synced',
                        'value' => number_format($trends['synced']),
                        'tone' => 'neutral',
                    ])
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'rate limited',
                        'value' => number_format($trends['rate_limited']),
                        'tone' => $trends['rate_limited'] > 0 ? 'warn' : 'neutral',
                    ])
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'revoked',
                        'value' => number_format($trends['revoked']),
                        'tone' => $trends['revoked'] > 0 ? 'alert' : 'neutral',
                    ])
                </div>
            </div>

            <div>
                <div class="text-label-micro text-ink-3 mb-1">Shared API Budget (whole app)</div>
                <div class="grid grid-cols-2 gap-2 text-center">
                    <div class="rounded-sm bg-surface-sunken p-1">
                        <div class="font-mono text-lg font-bold tabular-nums text-ink">{{ $rateLimit['15min'] ?? '-' }}<span class="text-xs font-normal text-ink-3">/200</span></div>
                        <div class="text-label-micro text-ink-3">15 min left</div>
                    </div>
                    <div class="rounded-sm bg-surface-sunken p-1">
                        <div class="font-mono text-lg font-bold tabular-nums text-ink">{{ $rateLimit['daily'] ?? '-' }}<span class="text-xs font-normal text-ink-3">/2k</span></div>
                        <div class="text-label-micro text-ink-3">daily left</div>
                    </div>
                </div>
            </div>

            @if ($perUser !== [])
                <div>
                    <div class="text-label-micro text-ink-3 mb-1">Per-User Sync Status</div>
                    <div class="space-y-1">
                        @foreach ($perUser as $row)
                            <div @class([
                                'flex items-center justify-between text-xs px-2 py-1 rounded-sm',
                                'bg-ember/15' => $row['is_failed'],
                                'bg-surface-sunken' => ! $row['is_failed'],
                            ])>
                                <div class="flex items-center gap-2 min-w-0">
                                    <span @class([
                                        'inline-block h-1.5 w-1.5 rounded-full shrink-0',
                                        'bg-ember' => $row['is_failed'],
                                        'bg-leaf' => ! $row['is_failed'],
                                    ])></span>
                                    <span class="truncate text-ink">{{ $row['user_name'] }}</span>
                                </div>
                                <div class="flex items-center gap-3 shrink-0 tabular-nums text-ink-3">
                                    @if ($row['last_sync'])
                                        <span>{{ \Illuminate\Support\Carbon::parse($row['last_sync'])->diffForHumans(short: true) }}</span>
                                    @else
                                        <span>never</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex items-center gap-2 text-xs text-ink-3">
                @if ($webhookStatus['configured'])
                    <span class="inline-block h-2 w-2 rounded-full bg-leaf"></span>
                    <span>Webhook subscribed</span>
                @else
                    <span class="inline-block h-2 w-2 rounded-full bg-horizon"></span>
                    <span>Webhook not configured</span>
                @endif
            </div>
        </div>
    </x-pulse::scroll>
</x-pulse::card>
