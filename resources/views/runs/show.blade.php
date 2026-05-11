@extends('layouts.app')

@php
    use App\Services\Run\Metrics\PaceFormatter;
    use App\Services\Run\Metrics\StreamSummary;

    $summary = is_array($detail->stream_summary) ? $detail->stream_summary : [];
    $zonePct = StreamSummary::zonePct($summary);
    $perKm = is_array($summary['per_km'] ?? null) ? $summary['per_km'] : [];

    $paceSec = $detail->moving_time && $detail->distance
        ? $detail->moving_time / ($detail->distance / 1000)
        : null;
    $paceLabel = $paceSec !== null ? PaceFormatter::format($paceSec) : '—';

    $durationLabel = $detail->moving_time !== null
        ? sprintf('%d:%02d:%02d',
            intdiv($detail->moving_time, 3600),
            intdiv($detail->moving_time % 3600, 60),
            $detail->moving_time % 60,
        )
        : '—';
@endphp

@section('content')
<div class="min-h-screen bg-[#FAFAF7] dark:bg-[#0a0a0a]">
    <x-app-header />

    <main class="mx-auto max-w-4xl px-6 py-10">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <a href="{{ route('runs.index') }}"
                   class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-lime-600 dark:text-gray-400">
                    <iconify-icon icon="mdi:arrow-left" width="14" height="14" aria-hidden="true"></iconify-icon>
                    Semua run
                </a>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight">{{ $detail->name ?? 'Run' }}</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ optional($detail->start_date_local)->translatedFormat('l, d F Y · H:i') }}
                </p>
            </div>
        </div>

        {{-- Temari speech bubble FIRST — the headline of the page --}}
        <x-temari-bubble :line="$storyLine" size="lg" class="mb-6" />

        {{-- Headline stats --}}
        <section class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
            <x-kpi-tile label="Jarak" :value="$detail->distance ? number_format($detail->distance / 1000, 2) : '—'" sub="km" />
            <x-kpi-tile label="Pace" :value="$paceLabel" sub="per km" />
            <x-kpi-tile label="Durasi" :value="$durationLabel" sub="moving" />
            <x-kpi-tile label="TRIMP" :value="$detail->trimp_edwards !== null ? (int) round($detail->trimp_edwards) : '—'" sub="Edwards" />
        </section>

        {{-- Run card + You vs Past You side by side --}}
        <section class="mb-6 grid gap-4 md:grid-cols-2">
            @if ($card !== null)
                <x-run-card :card="$card" :detail="$detail" />
            @endif
            <x-past-you-strip :match="$pastYou" :currentDistance="$detail->distance" />
        </section>

        {{-- Technical detail — collapsed by default, Temari speech is the page --}}
        <details class="rounded-2xl border border-black/5 bg-white dark:border-white/5 dark:bg-[#161615]">
            <summary class="flex cursor-pointer items-center justify-between gap-2 px-5 py-4 text-sm font-semibold">
                <span>Detail teknis</span>
                <iconify-icon icon="mdi:chevron-down" width="20" height="20" aria-hidden="true"></iconify-icon>
            </summary>
            <div class="space-y-6 border-t border-black/5 px-5 py-5 dark:border-white/5">
                @if (! empty($zonePct))
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">HR Zones</h3>
                        <div class="mt-3 flex h-3 overflow-hidden rounded-full">
                            @foreach (['Z1' => '#a3e635', 'Z2' => '#84cc16', 'Z3' => '#facc15', 'Z4' => '#f97316', 'Z5' => '#f43f5e'] as $zone => $color)
                                @php $width = (float) ($zonePct[$zone] ?? 0); @endphp
                                @if ($width > 0)
                                    <div style="width: {{ $width }}%; background: {{ $color }};" title="{{ $zone }}: {{ $width }}%"></div>
                                @endif
                            @endforeach
                        </div>
                        <dl class="mt-3 grid grid-cols-5 gap-2 text-xs tabular-nums">
                            @foreach (['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as $zone)
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">{{ $zone }}</dt>
                                    <dd class="font-semibold">{{ $zonePct[$zone] ?? 0 }}%</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @if ($detail->average_heartrate !== null)
                        <x-kpi-tile label="Avg HR" :value="(int) round($detail->average_heartrate)" sub="bpm" tone="alert" />
                    @endif
                    @if ($detail->max_heartrate !== null)
                        <x-kpi-tile label="Max HR" :value="$detail->max_heartrate" sub="bpm" tone="alert" />
                    @endif
                    @if ($detail->average_cadence !== null)
                        <x-kpi-tile label="Cadence" :value="(int) round($detail->average_cadence * 2)" sub="spm avg" />
                    @endif
                    @if (isset($summary['decoupling_pct']))
                        <x-kpi-tile label="Decoupling" :value="sprintf('%+.1f%%', (float) $summary['decoupling_pct'])" sub="aerobic drift" />
                    @endif
                    @if (isset($summary['ascent_m']))
                        <x-kpi-tile label="Ascent" :value="$summary['ascent_m']" sub="m" />
                    @endif
                    @if (isset($summary['stopped_time_sec']))
                        <x-kpi-tile label="Stopped" :value="$summary['stopped_time_sec'] . 's'" :sub="($summary['stop_count'] ?? 0) . 'x'" />
                    @endif
                    @if (isset($detail->weather_temp_c))
                        <x-kpi-tile label="Cuaca" :value="$detail->weather_temp_c . '°C'" :sub="($detail->weather_humidity_pct ?? '—') . '% humidity'" />
                    @endif
                </div>

                @if (! empty($perKm))
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Splits per KM</h3>
                        <table class="mt-3 w-full text-sm tabular-nums">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                    <th class="py-2 pr-3 font-semibold">KM</th>
                                    <th class="py-2 pr-3 font-semibold">Pace</th>
                                    <th class="py-2 pr-3 font-semibold">HR</th>
                                    <th class="py-2 pr-3 font-semibold">Cadence</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($perKm as $row)
                                    <tr class="border-t border-black/5 dark:border-white/5">
                                        <td class="py-1.5 pr-3 font-medium">{{ $row['km'] ?? '—' }}</td>
                                        <td class="py-1.5 pr-3">{{ $row['pace'] ?? '—' }}</td>
                                        <td class="py-1.5 pr-3">{{ $row['avg_hr'] ?? '—' }}</td>
                                        <td class="py-1.5 pr-3">{{ $row['avg_cadence_spm'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <p class="text-[11px] text-gray-400 dark:text-gray-500">
                    Strava activity ID {{ $activity->strava_external_id }} · ingested {{ optional($activity->analyzed_at)->translatedFormat('d M Y H:i') }}
                </p>
            </div>
        </details>
    </main>
</div>
@endsection
