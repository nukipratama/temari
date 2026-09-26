<?php

declare(strict_types=1);

use App\Enums\StravaGrantEventType;
use App\Models\StravaGrantEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts the event, ids, credential version, and timestamp', function (): void {
    $user = User::factory()->create();
    $event = StravaGrantEvent::query()->create([
        'strava_athlete_id' => '987654',
        'user_id' => (string) $user->id,
        'credential_version' => '7',
        'event' => StravaGrantEventType::Granted,
        'created_at' => now(),
    ]);

    expect($event->strava_athlete_id)->toBe(987654)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->credential_version)->toBe(7)
        ->and($event->event)->toBe(StravaGrantEventType::Granted)
        ->and($event->created_at)->toBeInstanceOf(Carbon::class);
});

it('keeps the plain user id after account deletion', function (): void {
    $user = User::factory()->create();
    $event = StravaGrantEvent::query()->create([
        'strava_athlete_id' => 987654,
        'user_id' => $user->id,
        'credential_version' => 0,
        'event' => StravaGrantEventType::Granted,
        'created_at' => now(),
    ]);

    $user->delete();

    expect($event->fresh()->user_id)->toBe($user->id);
});
