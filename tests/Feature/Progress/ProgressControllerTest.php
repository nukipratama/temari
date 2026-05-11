<?php

declare(strict_types=1);

use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('renders weekly snapshots + PR ledger', function (): void {
    $user = User::factory()->create();

    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'distance_km' => 35.0,
        'runs' => 4,
        'form' => -7.4,
        'form_status' => 'optimal',
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1500.0,
    ]);

    $this->actingAs($user)->get('/progress')
        ->assertSuccessful()
        ->assertSeeText('Riwayat Mingguan')
        ->assertSeeText('Personal Records')
        ->assertSeeText('5km')
        // 1500 elapsed seconds for 5K → 25:00 (no hours)
        ->assertSeeText('25:00');
});

it('shows empty states when the user has no data', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/progress')
        ->assertSuccessful()
        ->assertSeeText('Belum ada PR');
});
