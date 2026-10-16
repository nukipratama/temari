<?php

declare(strict_types=1);

use App\Enums\IntervalsIcuSyncStatus;
use App\Livewire\Pulse\IntervalsIcuHealth;
use App\Models\IntervalsIcuConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders with no connections and an ok badge', function (): void {
    Livewire::test(IntervalsIcuHealth::class)
        ->assertOk()
        ->assertSee('intervals.icu')
        ->assertSee('health: ok');
});

it("counts connections and today's statuses, alerting on a broken key", function (): void {
    Carbon::setTestNow('2026-10-14 09:00:00');
    IntervalsIcuConnection::factory()->count(2)->create(['synced_on' => '2026-10-14', 'sync_status' => IntervalsIcuSyncStatus::Sent]);
    IntervalsIcuConnection::factory()->create(['synced_on' => '2026-10-13', 'sync_status' => IntervalsIcuSyncStatus::Unreachable]);
    IntervalsIcuConnection::factory()->broken()->create();

    Livewire::test(IntervalsIcuHealth::class)
        ->assertViewHas('states', ['working' => 3, 'broken' => 1, 'sent' => 2, 'not_sent' => 0, 'failing' => 0])
        ->assertSee('health: alert');
});

it('warns when a day could not be sent for want of a threshold heart rate', function (): void {
    Carbon::setTestNow('2026-10-14 09:00:00');
    IntervalsIcuConnection::factory()->create(['synced_on' => '2026-10-14', 'sync_status' => IntervalsIcuSyncStatus::NoThresholdHeartRate]);

    Livewire::test(IntervalsIcuHealth::class)
        ->assertViewHas('states', fn (array $states): bool => $states['not_sent'] === 1)
        ->assertSee('health: warn');
});
