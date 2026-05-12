@php
    $isDemoUser = config('demo.login_enabled')
        && auth()->check()
        && auth()->user()->email === \Database\Seeders\Demo\DemoRunSeeder::DEMO_USER_EMAIL;
@endphp

@if ($isDemoUser)
    <div class="border-b border-amber-300/60 bg-amber-100 px-4 py-2 text-center text-xs font-medium text-amber-900 dark:border-amber-700/60 dark:bg-amber-900/40 dark:text-amber-100">
        <iconify-icon icon="mdi:flask-outline" width="14" height="14" class="-mt-0.5 mr-1 inline-block align-middle" aria-hidden="true"></iconify-icon>
        Mode demo aktif — semua data di halaman ini adalah dummy.
    </div>
@endif
