@extends('layouts.app')

@section('content')
<div class="min-h-screen bg-[#FAFAF7] dark:bg-[#0a0a0a]">
    <x-app-header />

    <main class="mx-auto max-w-5xl px-6 py-10">
        <div class="mb-6 flex items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Semua Run</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $runs->total() }} aktivitas tersinkron dari Strava.
                </p>
            </div>
        </div>

        @if ($runs->isEmpty())
            <div class="rounded-2xl border border-dashed border-black/10 bg-white/40 p-10 text-center dark:border-white/10 dark:bg-[#161615]/40">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Belum ada run yang dianalisis. Sinkronisasi Strava akan mengisi halaman ini.
                </p>
            </div>
        @else
            <div class="overflow-hidden rounded-2xl border border-black/5 bg-white dark:border-white/5 dark:bg-[#161615]">
                @foreach ($runs as $activity)
                    @if ($activity->detail !== null)
                        <x-run-list-row :detail="$activity->detail" dateFormat="D, d M Y · H:i" />
                    @endif
                @endforeach
            </div>
            <div class="mt-6">
                {{ $runs->links() }}
            </div>
        @endif
    </main>
</div>
@endsection
