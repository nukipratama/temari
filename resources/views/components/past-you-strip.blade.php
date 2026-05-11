@props([
    /** array|null — output of PastYouMatcher::findMatch */
    'match' => null,
    /** float|null — current run's distance in metres, used for labels */
    'currentDistance' => null,
])

@php
    $distanceLabel = $currentDistance ? number_format($currentDistance / 1000, 1) . ' km' : 'jarak ini';
@endphp

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-black/5 bg-white p-5 dark:border-white/5 dark:bg-[#161615]']) }}>
    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
        Kamu vs Kamu Dulu
    </h3>

    @if ($match === null)
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            Pertama kali di {{ $distanceLabel }}!
        </p>
    @else
        @php
            $past = $match['past'];
            $paceDiff = $match['pace_diff_sec'];
            $hrDiff = $match['hr_diff_bpm'];
            $daysAgo = $match['days_ago'];

            $paceLabel = abs((int) round($paceDiff)) . ' detik/km '
                . ($paceDiff > 0 ? 'lebih cepat' : 'lebih lambat');
            $hrLabel = $hrDiff === null ? null
                : sprintf('%d bpm %s', (int) abs(round($hrDiff)), $hrDiff < 0 ? 'lebih rendah' : 'lebih tinggi');
            $paceTone = $paceDiff > 0 ? 'text-lime-600 dark:text-lime-400' : 'text-rose-600 dark:text-rose-400';
            $hrTone = ($hrDiff !== null && $hrDiff < 0) ? 'text-lime-600 dark:text-lime-400'
                : ($hrDiff === null ? 'text-gray-500' : 'text-rose-600 dark:text-rose-400');
        @endphp

        <p class="mt-2 text-sm leading-relaxed text-gray-700 dark:text-gray-200">
            vs kamu <span class="font-semibold">{{ $daysAgo }} hari lalu</span> di {{ $distanceLabel }}
        </p>
        <div class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
            <div>
                <span class="font-bold tabular-nums {{ $paceTone }}">{{ $paceLabel }}</span>
            </div>
            @if ($hrLabel !== null)
                <div>
                    <span class="font-bold tabular-nums {{ $hrTone }}">{{ $hrLabel }}</span>
                </div>
            @endif
        </div>
        <p class="mt-3 text-[11px] text-gray-500 dark:text-gray-400">
            {{ optional($past->start_date_local)->translatedFormat('D, d M Y') }}
        </p>
    @endif
</div>
