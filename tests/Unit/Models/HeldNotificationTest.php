<?php

declare(strict_types=1);

use App\Models\HeldNotification;
use App\Models\User;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\StreakReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function holdFor(User $user): HeldNotification
{
    return HeldNotification::query()->create([
        'user_id' => (string) $user->id,
        'channel' => InAppChannel::class,
        'notification' => serialize(new StreakReminderNotification(4)),
        'held_at' => '2026-10-05 23:00:00',
    ]);
}

it('casts its columns', function (): void {
    $user = User::factory()->create();
    holdFor($user);

    $row = HeldNotification::query()->firstOrFail();

    expect($row->user_id)->toBe($user->id)
        ->and($row->held_at)->toBeInstanceOf(Carbon::class)
        ->and($row->held_at->toDateTimeString())->toBe('2026-10-05 23:00:00');
});

it('cascades away with its athlete', function (): void {
    $user = User::factory()->create();
    holdFor($user);

    $user->delete();

    expect(HeldNotification::query()->count())->toBe(0);
});
