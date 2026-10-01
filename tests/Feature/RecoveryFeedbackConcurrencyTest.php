<?php

declare(strict_types=1);

use App\Models\RecoveryFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class);

it('updates the row created by a competing same-date submission', function (): void {
    $user = User::factory()->create();
    $date = today()->subDay()->toDateString();
    $competitorInserted = false;

    RecoveryFeedback::creating(function (RecoveryFeedback $feedback) use (&$competitorInserted): void {
        if ($competitorInserted) {
            return;
        }

        $competitorInserted = true;
        DB::connection('analytics')->table('recovery_feedback')->insert([
            'user_id' => $feedback->user_id,
            'date' => $feedback->date->toDateString(),
            'fatigue' => 'severe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $this->actingAs($user)
            ->postJson(route('recovery.feedback.store'), [
                'date' => $date,
                'sleep_quality' => 'good',
            ])
            ->assertOk()
            ->assertJsonPath('sleep_quality', 'good')
            ->assertJsonPath('fatigue', 'severe');

        expect($competitorInserted)->toBeTrue()
            ->and(RecoveryFeedback::query()->where('user_id', $user->id)->whereDate('date', $date)->count())->toBe(1);
    } finally {
        RecoveryFeedback::flushEventListeners();
        DB::table('recovery_feedback')->where('user_id', $user->id)->delete();
        DB::table('users')->where('id', $user->id)->delete();
    }

    expect(DB::table('users')->where('id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('recovery_feedback')->where('user_id', $user->id)->exists())->toBeFalse();
});
