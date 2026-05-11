@extends('layouts.app')

@section('content')
<div class="min-h-screen bg-[#FAFAF7] dark:bg-[#0a0a0a]">
    <x-app-header />

    <main class="mx-auto max-w-6xl px-6 py-10">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Halo, {{ explode(' ', auth()->user()->name)[0] }}.</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Berikut ringkasan lari kamu.</p>
            </div>
        </div>

        {{-- Vibe Check headline + Temari greeting --}}
        <section class="mt-8">
            <x-vibe-card :state="$vibeState" :greeting="$greeting->speech" />
        </section>

        {{-- 8 KPI tiles --}}
        @if ($load !== null)
            <section class="mt-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                @php
                    $formTone = match ($load['form_status']) {
                        'fresh' => 'positive',
                        'optimal' => 'neutral',
                        'fatigued' => 'warning',
                        'overreaching' => 'alert',
                        default => 'neutral',
                    };
                @endphp
                <x-kpi-tile label="Form" :value="number_format($load['form'], 1)" :sub="ucfirst($load['form_status'])" :tone="$formTone" />
                <x-kpi-tile label="Fitness (CTL)" :value="number_format($load['ctl_42d'], 1)" sub="42-day chronic load" />
                <x-kpi-tile label="Fatigue (ATL)" :value="number_format($load['atl_7d'], 1)" sub="7-day acute load" tone="warning" />
                <x-kpi-tile label="Weekly TRIMP" :value="number_format($load['weekly_trimp'], 0)" sub="Edwards, last 7 days" />
                @php
                    $monotonyTone = $load['monotony'] >= 2.0 ? 'alert' : ($load['monotony'] >= 1.5 ? 'warning' : 'neutral');
                @endphp
                <x-kpi-tile label="Monotony" :value="number_format($load['monotony'], 2)" sub="<2.0 ideal" :tone="$monotonyTone" />
                <x-kpi-tile label="Strain" :value="number_format($load['strain'], 0)" sub="TRIMP × monotony" />
                <x-kpi-tile label="Avg decoupling" :value="$snapshot?->avg_decoupling !== null ? sprintf('%+.1f%%', $snapshot->avg_decoupling) : '—'" sub="aerobic drift, last week" />
                <x-kpi-tile label="Runs this week" :value="$snapshot?->runs ?? '—'" :sub="$snapshot?->distance_km !== null ? number_format($snapshot->distance_km, 1) . ' km' : null" />
            </section>
        @else
            <section class="mt-6 rounded-2xl border border-dashed border-black/10 bg-white/40 p-10 text-center dark:border-white/10 dark:bg-[#161615]/40">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-lime-500/15 text-lime-600 dark:text-lime-400">
                    <iconify-icon icon="mdi:run-fast" width="28" height="28" aria-hidden="true"></iconify-icon>
                </div>
                <h2 class="mt-4 text-base font-semibold">Belum ada aktivitas tersinkron</h2>
                <p class="mx-auto mt-1 max-w-sm text-sm text-gray-600 dark:text-gray-300">
                    Jalankan <code>php artisan strava:sync</code> atau tunggu sync terjadwal untuk mengisi dashboard.
                </p>
            </section>
        @endif

        {{-- Recent runs --}}
        @if ($recentRuns->isNotEmpty())
            <section class="mt-10">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold tracking-tight">Aktivitas Terakhir</h2>
                    <a href="{{ route('runs.index') }}" class="text-sm text-gray-600 hover:text-lime-600 dark:text-gray-300">Lihat semua &rarr;</a>
                </div>
                <div class="mt-3 overflow-hidden rounded-2xl border border-black/5 bg-white dark:border-white/5 dark:bg-[#161615]">
                    @foreach ($recentRuns as $detail)
                        <x-run-list-row :detail="$detail" />
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Fitness / Volume charts --}}
        @if (count($chartData['labels']) > 1)
            <section class="mt-10 grid gap-4 md:grid-cols-2">
                <div class="rounded-2xl border border-black/5 bg-white p-5 dark:border-white/5 dark:bg-[#161615]">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Fitness &amp; Form</h3>
                    <div class="relative mt-4 h-56">
                        <canvas id="fitnessChart"></canvas>
                    </div>
                </div>
                <div class="rounded-2xl border border-black/5 bg-white p-5 dark:border-white/5 dark:bg-[#161615]">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Weekly Volume</h3>
                    <div class="relative mt-4 h-56">
                        <canvas id="volumeChart"></canvas>
                    </div>
                </div>
            </section>

            <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const labels = @json($chartData['labels']);
                    const ctl = @json($chartData['ctl']);
                    const atl = @json($chartData['atl']);
                    const form = @json($chartData['form']);
                    const volume = @json($chartData['volume']);

                    const baseOpts = {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { labels: { usePointStyle: true, pointStyle: 'circle', padding: 12, font: { size: 11 } } } },
                        scales: {
                            x: { ticks: { font: { size: 10 } } },
                            y: { ticks: { font: { size: 10 } } }
                        }
                    };

                    new Chart(document.getElementById('fitnessChart'), {
                        type: 'line',
                        data: { labels, datasets: [
                            { label: 'CTL', data: ctl, borderColor: '#84cc16', backgroundColor: 'rgba(132,204,22,0.1)', fill: true, tension: 0.4, pointRadius: 0, borderWidth: 2 },
                            { label: 'ATL', data: atl, borderColor: '#f43f5e', tension: 0.4, pointRadius: 0, borderWidth: 2 },
                            { label: 'Form', data: form, borderColor: '#0ea5e9', tension: 0.4, pointRadius: 0, borderWidth: 1.5, borderDash: [4,4] }
                        ] },
                        options: baseOpts
                    });

                    new Chart(document.getElementById('volumeChart'), {
                        type: 'bar',
                        data: { labels, datasets: [{ label: 'km', data: volume, backgroundColor: 'rgba(132,204,22,0.35)', borderColor: '#84cc16', borderWidth: 1, borderRadius: 4 }] },
                        options: { ...baseOpts, plugins: { legend: { display: false } } }
                    });
                });
            </script>
        @endif
    </main>
</div>
@endsection
