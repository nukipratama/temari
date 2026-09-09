<?php

declare(strict_types=1);

use App\Enums\FeedbackReason;
use App\Enums\FeedbackSubject;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $feedback = Feedback::query()->create([
        'user_id' => $user->id,
        'subject_type' => FeedbackSubject::PlanDay,
        'subject_id' => 7,
        'note' => 'too long for a tuesday',
    ]);

    expect($feedback->user)->toBeInstanceOf(User::class)
        ->and($feedback->user->is($user))->toBeTrue();
});

it('casts the subject columns and the timestamp', function (): void {
    $feedback = new Feedback([
        'user_id' => '3',
        'subject_type' => 'narration',
        'subject_id' => '42',
        'reason' => 'tone_off',
    ]);

    expect($feedback->user_id)->toBeInt()->toBe(3)
        ->and($feedback->subject_type)->toBe(FeedbackSubject::Narration)
        ->and($feedback->subject_id)->toBeInt()->toBe(42)
        ->and($feedback->reason)->toBe(FeedbackReason::ToneOff);
});

it('records when it was written and never an updated_at', function (): void {
    $user = User::factory()->create();
    $feedback = Feedback::query()->create([
        'user_id' => $user->id,
        'subject_type' => FeedbackSubject::Narration,
        'subject_id' => 1,
    ]);

    expect($feedback->created_at)->toBeInstanceOf(Carbon::class)
        ->and(array_key_exists('updated_at', $feedback->getAttributes()))->toBeFalse();
});

it('keeps the reason null on a row written before reasons existed', function (): void {
    $user = User::factory()->create();
    $feedback = Feedback::query()->create([
        'user_id' => $user->id,
        'subject_type' => FeedbackSubject::Narration,
        'subject_id' => 1,
    ]);

    expect($feedback->reason)->toBeNull();
});

it('holds one row per subject per athlete', function (): void {
    $user = User::factory()->create();
    $row = [
        'user_id' => $user->id,
        'subject_type' => FeedbackSubject::Narration,
        'subject_id' => 9,
        'reason' => FeedbackReason::ToneOff,
    ];
    Feedback::query()->create($row);

    expect(fn () => Feedback::query()->create($row))
        ->toThrow(QueryException::class);
});
