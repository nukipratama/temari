<?php

declare(strict_types=1);

use App\Enums\RecoveryConcernLevel;
use App\Enums\SleepQuality;
use App\Models\RecoveryFeedback;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts its feedback values and belongs to a user', function (): void {
    $user = User::factory()->create();
    $feedback = RecoveryFeedback::query()->create([
        'user_id' => (string) $user->id,
        'date' => '2026-09-12',
        'sleep_quality' => SleepQuality::Fair->value,
        'fatigue' => RecoveryConcernLevel::Mild->value,
        'soreness' => RecoveryConcernLevel::Severe->value,
        'concerning_pain' => 1,
        'illness' => 0,
    ]);
    $feedback->refresh();

    expect($feedback->user_id)->toBeInt()
        ->and($feedback->date)->toBeInstanceOf(Carbon::class)
        ->and($feedback->sleep_quality)->toBe(SleepQuality::Fair)
        ->and($feedback->fatigue)->toBe(RecoveryConcernLevel::Mild)
        ->and($feedback->soreness)->toBe(RecoveryConcernLevel::Severe)
        ->and($feedback->concerning_pain)->toBeTrue()
        ->and($feedback->illness)->toBeFalse()
        ->and($feedback->user->is($user))->toBeTrue();
});

it('enforces one row per user and date and cascades on user deletion', function (): void {
    $user = User::factory()->create();
    $feedback = RecoveryFeedback::query()->create(['user_id' => $user->id, 'date' => '2026-09-12']);
    $otherUserFeedback = RecoveryFeedback::query()->create([
        'user_id' => User::factory()->create()->id,
        'date' => '2026-09-12',
    ]);

    expect(fn () => RecoveryFeedback::query()->create(['user_id' => $user->id, 'date' => '2026-09-12']))
        ->toThrow(UniqueConstraintViolationException::class);

    $user->delete();

    expect(RecoveryFeedback::query()->whereKey($feedback->id)->exists())->toBeFalse()
        ->and(RecoveryFeedback::query()->whereKey($otherUserFeedback->id)->exists())->toBeTrue();
});
