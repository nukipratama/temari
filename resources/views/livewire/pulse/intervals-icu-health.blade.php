<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="intervals.icu"
        details="today's workout, synced every 15 min"
    >
        <x-slot:icon>
            <x-pulse::icons.arrows-left-right />
        </x-slot:icon>
        <x-slot:actions>
            @include('livewire.pulse.partials.status-badge', ['severity' => $severity])
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" class="temari-card-body" wire:poll.30s="">
        <div class="space-y-4">
            <div>
                <div class="text-label-micro text-text-3 mb-1">Connections</div>
                <div class="grid grid-cols-2 gap-2">
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'working',
                        'value' => number_format($states['working']),
                        'tone' => 'neutral',
                    ])
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'broken key',
                        'value' => number_format($states['broken']),
                        'tone' => $states['broken'] > 0 ? 'alert' : 'neutral',
                    ])
                </div>
            </div>

            <div>
                <div class="text-label-micro text-text-3 mb-1">Today</div>
                <div class="grid grid-cols-3 gap-2">
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'sent',
                        'value' => number_format($states['sent']),
                        'tone' => 'neutral',
                    ])
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'no threshold hr',
                        'value' => number_format($states['not_sent']),
                        'tone' => $states['not_sent'] > 0 ? 'warn' : 'neutral',
                    ])
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'failing',
                        'value' => number_format($states['failing']),
                        'tone' => $states['failing'] > 0 ? 'alert' : 'neutral',
                    ])
                </div>
            </div>

            <div>
                <div class="text-label-micro text-text-3 mb-1">Last {{ $this->periodForHumans() }}</div>
                <div class="grid grid-cols-5 gap-2">
                    @include('livewire.pulse.partials.stat-tile', ['label' => 'sent', 'value' => number_format($trends['created']), 'tone' => 'neutral'])
                    @include('livewire.pulse.partials.stat-tile', ['label' => 'updated', 'value' => number_format($trends['updated']), 'tone' => 'neutral'])
                    @include('livewire.pulse.partials.stat-tile', ['label' => 'removed', 'value' => number_format($trends['deleted']), 'tone' => 'neutral'])
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'failed',
                        'value' => number_format($trends['failed']),
                        'tone' => $trends['failed'] > 0 ? 'warn' : 'neutral',
                    ])
                    @include('livewire.pulse.partials.stat-tile', [
                        'label' => 'broke',
                        'value' => number_format($trends['broken']),
                        'tone' => $trends['broken'] > 0 ? 'alert' : 'neutral',
                    ])
                </div>
            </div>
        </div>
    </x-pulse::scroll>
</x-pulse::card>
