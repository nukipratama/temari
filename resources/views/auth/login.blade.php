@extends('layouts.app')

@section('content')
<div class="relative min-h-screen overflow-hidden bg-[#FAFAF7] dark:bg-[#0a0a0a]">
    <div class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute -top-40 -right-32 h-[28rem] w-[28rem] rounded-full bg-lime-400 opacity-15 blur-3xl"></div>
        <div class="absolute -bottom-40 -left-32 h-[28rem] w-[28rem] rounded-full bg-lime-500 opacity-10 blur-3xl"></div>
    </div>

    <main class="relative mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center px-6 py-12">
        <div class="mb-10 flex flex-col items-center text-center">
            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-lime-500 text-[#0a0a0a]">
                <iconify-icon icon="mdi:run-fast" width="28" height="28" aria-hidden="true"></iconify-icon>
            </span>
            <h1 class="mt-4 text-3xl font-semibold tracking-tight">TemanLari</h1>
            <p class="mt-2 text-base text-gray-600 dark:text-gray-300">
                Setiap Langkah Berarti
            </p>
        </div>

        <section class="mb-10 grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach ([
                ['icon' => 'mdi:cloud-download-outline', 'label' => 'Catat', 'desc' => 'Setiap lari, otomatis dari Strava'],
                ['icon' => 'mdi:chart-line', 'label' => 'Pantau', 'desc' => 'Lihat progress mingguan'],
                ['icon' => 'mdi:calendar-check', 'label' => 'Konsisten', 'desc' => 'Bangun kebiasaan, bukan target'],
            ] as $feature)
                <div class="rounded-2xl border border-black/5 bg-white/60 p-5 backdrop-blur dark:border-white/5 dark:bg-[#161615]/60">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-lime-500/15 text-lime-600 dark:text-lime-400">
                        <iconify-icon icon="{{ $feature['icon'] }}" width="22" height="22" aria-hidden="true"></iconify-icon>
                    </div>
                    <h2 class="mt-4 text-sm font-semibold">{{ $feature['label'] }}</h2>
                    <p class="mt-1 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                        {{ $feature['desc'] }}
                    </p>
                </div>
            @endforeach
        </section>

        <div class="mx-auto w-full max-w-md rounded-3xl border border-black/5 bg-white p-8 shadow-[0_1px_3px_rgba(0,0,0,0.04),0_8px_24px_-12px_rgba(0,0,0,0.08)] dark:border-white/5 dark:bg-[#161615] dark:shadow-none">
            <h2 class="text-2xl font-semibold tracking-tight">Selamat datang</h2>
            <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-200">
                Masuk pakai Strava untuk mulai catat lari kamu
            </p>

            @if ($errors->any())
                <div class="mt-6 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
                    {{ $errors->first() }}
                </div>
            @endif

            <a
                href="{{ route('auth.strava.redirect') }}"
                class="mt-8 inline-flex w-full items-center justify-center gap-2.5 rounded-xl bg-[#FC4C02] px-5 py-3.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#e34402] focus:outline-none focus:ring-4 focus:ring-[#FC4C02]/30"
            >
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor" aria-hidden="true">
                    <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169" />
                </svg>
                Connect with Strava
            </a>

            @if (config('demo.login_enabled'))
                <form method="POST" action="{{ route('auth.demo') }}" class="mt-3">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-black/10 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:bg-black/[0.03] focus:outline-none focus:ring-4 focus:ring-black/10 dark:border-white/10 dark:bg-[#161615] dark:text-gray-200 dark:hover:bg-white/[0.04]"
                    >
                        <iconify-icon icon="mdi:play-circle-outline" width="18" height="18" aria-hidden="true"></iconify-icon>
                        Coba versi demo
                    </button>
                </form>
            @endif

            <p class="mt-5 text-center text-xs leading-relaxed text-gray-500 dark:text-gray-300">
                Kami hanya pakai Strava untuk login dan baca aktivitas lari kamu
            </p>
        </div>

        <p class="mt-6 text-center text-xs text-gray-400 dark:text-gray-400">
            Made with <span class="text-lime-500">&#x2665;</span> by a runner, for runners
        </p>
    </main>
</div>
@endsection
