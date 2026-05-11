@extends('layouts.app')

@section('content')
<div class="min-h-screen bg-[#FAFAF7] dark:bg-[#0a0a0a]">
    <x-app-header />

    <main class="mx-auto max-w-5xl px-6 py-10">
        <div class="mb-6">
            <h1 class="text-2xl font-semibold tracking-tight">Progress</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                Tren mingguan + ledger PR.
            </p>
        </div>

        {{-- Weekly snapshot history --}}
        @if ($snapshots->isNotEmpty())
            <section class="mb-10">
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Riwayat Mingguan
                </h2>
                <div class="overflow-hidden rounded-2xl border border-black/5 bg-white dark:border-white/5 dark:bg-[#161615]">
                    <table class="w-full text-sm tabular-nums">
                        <thead>
                            <tr class="border-b border-black/5 text-left text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                                <th class="px-5 py-3 font-semibold">Week ending</th>
                                <th class="px-5 py-3 font-semibold">Volume</th>
                                <th class="px-5 py-3 font-semibold">Runs</th>
                                <th class="px-5 py-3 font-semibold">TRIMP</th>
                                <th class="px-5 py-3 font-semibold">CTL</th>
                                <th class="px-5 py-3 font-semibold">ATL</th>
                                <th class="px-5 py-3 font-semibold">Form</th>
                                <th class="px-5 py-3 font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($snapshots as $snap)
                                @php
                                    $statusTone = match ($snap->form_status) {
                                        'fresh' => 'text-lime-600 dark:text-lime-400',
                                        'optimal' => '',
                                        'fatigued' => 'text-amber-600 dark:text-amber-400',
                                        'overreaching' => 'text-rose-600 dark:text-rose-400',
                                        default => '',
                                    };
                                @endphp
                                <tr class="border-b border-black/5 last:border-b-0 dark:border-white/5">
                                    <td class="px-5 py-2.5 font-medium">{{ $snap->week_ending->translatedFormat('d M Y') }}</td>
                                    <td class="px-5 py-2.5">{{ $snap->distance_km !== null ? number_format($snap->distance_km, 1) . ' km' : '—' }}</td>
                                    <td class="px-5 py-2.5">{{ $snap->runs ?? '—' }}</td>
                                    <td class="px-5 py-2.5">{{ $snap->weekly_trimp !== null ? number_format($snap->weekly_trimp, 0) : '—' }}</td>
                                    <td class="px-5 py-2.5">{{ $snap->ctl_42d !== null ? number_format($snap->ctl_42d, 1) : '—' }}</td>
                                    <td class="px-5 py-2.5">{{ $snap->atl_7d !== null ? number_format($snap->atl_7d, 1) : '—' }}</td>
                                    <td class="px-5 py-2.5">{{ $snap->form !== null ? number_format($snap->form, 1) : '—' }}</td>
                                    <td class="px-5 py-2.5 {{ $statusTone }}">{{ ucfirst($snap->form_status ?? '—') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- Personal records ledger --}}
        <section>
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                Personal Records
            </h2>
            @if ($personalRecords->isEmpty())
                <div class="rounded-2xl border border-dashed border-black/10 bg-white/40 p-10 text-center dark:border-white/10 dark:bg-[#161615]/40">
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        Belum ada PR. Run yang dianalisis dengan splits + best-effort paces akan mengisi ledger di sini.
                    </p>
                </div>
            @else
                <div class="overflow-hidden rounded-2xl border border-black/5 bg-white dark:border-white/5 dark:bg-[#161615]">
                    <table class="w-full text-sm tabular-nums">
                        <thead>
                            <tr class="border-b border-black/5 text-left text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                                <th class="px-5 py-3 font-semibold">Category</th>
                                <th class="px-5 py-3 font-semibold">Value</th>
                                <th class="px-5 py-3 font-semibold">Activity</th>
                                <th class="px-5 py-3 text-right font-semibold">Set on</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($personalRecords as $pr)
                                @php
                                    $isDistance = in_array($pr->category, ['1km', '5km', '10km', '15km', 'half_marathon', 'marathon'], true);
                                    $secs = (int) $pr->value_sec;
                                    if ($isDistance) {
                                        $h = intdiv($secs, 3600);
                                        $m = intdiv($secs % 3600, 60);
                                        $s = $secs % 60;
                                        $value = $h > 0
                                            ? sprintf('%d:%02d:%02d', $h, $m, $s)
                                            : sprintf('%d:%02d', $m, $s);
                                    } else {
                                        $value = sprintf('%d:%02d/km', intdiv($secs, 60), $secs % 60);
                                    }
                                @endphp
                                <tr class="border-b border-black/5 last:border-b-0 dark:border-white/5">
                                    <td class="px-5 py-2.5 font-medium">{{ $pr->category }}</td>
                                    <td class="px-5 py-2.5 font-bold">{{ $value }}</td>
                                    <td class="px-5 py-2.5">
                                        @if ($pr->activity_id !== null)
                                            <a href="{{ route('runs.show', $pr->activity_id) }}" class="text-lime-600 hover:underline dark:text-lime-400">
                                                {{ $pr->activity?->detail?->name ?? 'Run' }}
                                            </a>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2.5 text-right text-xs text-gray-500 dark:text-gray-400">
                                        {{ $pr->set_at->translatedFormat('d M Y') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </main>
</div>
@endsection
