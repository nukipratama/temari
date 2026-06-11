<x-pulse>
    {{-- Domain-specific health cards lead — this is what the operator checks first. --}}
    <livewire:pulse.ai-pipeline-health cols="6" rows="2" />

    <livewire:pulse.strava-health cols="6" rows="2" />

    {{-- Did the scheduled commands actually run? --}}
    <livewire:pulse.scheduler-health cols="full" />

    {{-- Emergency controls — demoted below the health overview. --}}
    <livewire:pulse.system-control cols="full" rows="2" />

    {{-- Stock performance cards. --}}
    <livewire:pulse.queues cols="6" />

    <livewire:pulse.exceptions cols="6" />

    <livewire:pulse.slow-requests cols="full" />

    {{-- Host vitals (CPU/memory/disk) anchor the bottom. --}}
    <livewire:pulse.servers cols="full" />
</x-pulse>
