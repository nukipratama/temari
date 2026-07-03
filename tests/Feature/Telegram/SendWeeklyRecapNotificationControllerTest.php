<?php

declare(strict_types=1);

use App\Jobs\Telegram\SendTelegramNotificationJob;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function doneWeeklyRecapFor(WeeklySnapshot $snapshot, bool $done = true): Analysis
{
    $factory = Analysis::factory();
    $factory = $done ? $factory->done('Minggu ini 28 km.') : $factory;

    return $factory->create([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snapshot->id,
        'discriminator' => null,
    ]);
}

it('requires authentication', function (): void {
    $snapshot = WeeklySnapshot::factory()->create();

    $this->post(route('rekap.mingguan.telegram', $snapshot))->assertRedirect(route('login'));
});

it('force-dispatches the push when the weekly recap is done', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($user)->create();
    $analysis = doneWeeklyRecapFor($snapshot);

    $this->actingAs($user)
        ->post(route('rekap.mingguan.telegram', $snapshot))
        ->assertRedirect()
        ->assertSessionHas('success');

    Bus::assertDispatched(
        SendTelegramNotificationJob::class,
        fn (SendTelegramNotificationJob $job): bool => $job->analysisId === $analysis->id && $job->force === true,
    );
});

it('does not dispatch and flashes info when the recap is not ready', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($user)->create();
    doneWeeklyRecapFor($snapshot, done: false);

    $this->actingAs($user)
        ->post(route('rekap.mingguan.telegram', $snapshot))
        ->assertRedirect()
        ->assertSessionHas('info');

    Bus::assertNotDispatched(SendTelegramNotificationJob::class);
});

it('404s when the snapshot belongs to another user', function (): void {
    Bus::fake();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($owner)->create();

    $this->actingAs($other)
        ->post(route('rekap.mingguan.telegram', $snapshot))
        ->assertNotFound();

    Bus::assertNotDispatched(SendTelegramNotificationJob::class);
});
