@props([
    /** App\Models\ActivityDetail */
    'detail',
    /** Carbon date format passed to translatedFormat() */
    'dateFormat' => 'D, d M Y',
])

@php
    use App\Services\Run\Metrics\PaceFormatter;

    $paceSec = $detail?->moving_time && $detail->distance
        ? $detail->moving_time / ($detail->distance / 1000)
        : null;
    $paceLabel = $paceSec !== null ? PaceFormatter::format($paceSec) : '—';
@endphp

<a href="{{ route('runs.show', $detail->activity_id) }}"
   class="flex items-center gap-4 border-b border-black/5 px-5 py-4 text-sm transition hover:bg-black/[0.02] last:border-b-0 dark:border-white/5 dark:hover:bg-white/[0.03]">
    <div class="flex-1 min-w-0">
        <div class="truncate font-medium">{{ $detail->name ?? 'Run' }}</div>
        <div class="text-xs text-gray-500 dark:text-gray-400">
            {{ optional($detail->start_date_local)->translatedFormat($dateFormat) }}
        </div>
    </div>
    <div class="flex items-center gap-5 tabular-nums">
        <div class="text-center">
            <div class="font-bold">{{ $detail->distance ? number_format($detail->distance / 1000, 2) : '—' }}</div>
            <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">km</div>
        </div>
        <div class="hidden text-center sm:block">
            <div>{{ $paceLabel }}</div>
            <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">/km</div>
        </div>
        <div class="hidden text-center md:block">
            <div class="text-rose-600 dark:text-rose-400">{{ $detail->average_heartrate ? (int) round($detail->average_heartrate) : '—' }}</div>
            <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">bpm</div>
        </div>
        <div class="hidden text-center md:block">
            <div>{{ $detail->trimp_edwards !== null ? (int) round($detail->trimp_edwards) : '—' }}</div>
            <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">TRIMP</div>
        </div>
    </div>
</a>
