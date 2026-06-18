<x-pulse>
    {{-- Host vitals (CPU/memory/disk) lead: is the box healthy? --}}
    <livewire:pulse.servers cols="full" />

    {{-- Domain-specific health cards. --}}
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
</x-pulse>
