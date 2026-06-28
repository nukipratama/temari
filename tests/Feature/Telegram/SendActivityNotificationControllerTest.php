<?php

declare(strict_types=1);

use App\Jobs\Telegram\SendTelegramNotificationJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function doneActivitySpeechFor(Activity $activity, bool $done = true): Analysis
{
    $factory = Analysis::factory();
    $factory = $done ? $factory->done('Mantap!') : $factory;

    return $factory->create([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'discriminator' => null,
    ]);
}

it('requires authentication', function (): void {
    $activity = Activity::factory()->create();

    $this->post(route('aktivitas.telegram', $activity))->assertRedirect(route('login'));
});

it('force-dispatches the push when the post-run speech is done', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $analysis = doneActivitySpeechFor($activity);

    $this->actingAs($user)
        ->post(route('aktivitas.telegram', $activity))
        ->assertRedirect()
        ->assertSessionHas('success');

    Bus::assertDispatched(
        SendTelegramNotificationJob::class,
        fn (SendTelegramNotificationJob $job): bool => $job->analysisId === $analysis->id && $job->force === true,
    );
});

it('does not dispatch and flashes info when the narration is not ready', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    doneActivitySpeechFor($activity, done: false);

    $this->actingAs($user)
        ->post(route('aktivitas.telegram', $activity))
        ->assertRedirect()
        ->assertSessionHas('info');

    Bus::assertNotDispatched(SendTelegramNotificationJob::class);
});

it('404s when the activity belongs to another user', function (): void {
    Bus::fake();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $activity = Activity::factory()->for($owner)->create();

    $this->actingAs($other)
        ->post(route('aktivitas.telegram', $activity))
        ->assertNotFound();

    Bus::assertNotDispatched(SendTelegramNotificationJob::class);
});
