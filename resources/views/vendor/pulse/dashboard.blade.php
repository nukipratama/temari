<x-pulse>
    {{-- Domain-specific health cards (custom) lead — this is what the operator checks first. --}}
    <livewire:pulse.system-control cols="full" rows="2" />

    <livewire:pulse.ai-pipeline-health cols="6" rows="2" />

    <livewire:pulse.strava-health cols="6" rows="2" />

    {{-- Did the scheduled commands actually run? --}}
    <livewire:pulse.scheduler-health cols="full" />

    {{-- Stock performance cards. --}}
    <livewire:pulse.queues cols="4" />

    <livewire:pulse.slow-jobs cols="8" />

    <livewire:pulse.slow-outgoing-requests cols="6" />

    <livewire:pulse.slow-queries cols="6" />

    <livewire:pulse.exceptions cols="6" />

    <livewire:pulse.slow-requests cols="6" />

    {{-- Host vitals (CPU/memory/disk) anchor the bottom. --}}
    <livewire:pulse.servers cols="full" />
</x-pulse>
