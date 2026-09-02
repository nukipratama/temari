<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\Analytics\StravaSyncLog;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('deletes the account, revokes Strava, logs the user out and redirects to login', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $this->actingAs($user)->delete('/account')
        ->assertRedirect(route('login'))
        ->assertSessionHas('info');

    // The connection row cascade-deletes with the user; the `deleting` hook's
    // revoke + sync-log write is the observable proof it ran (see UserTest).
    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(StravaSyncLog::query()->where('user_id', $user->id)->where('status', 'deleted')->exists())->toBeTrue();

    $this->assertGuest();
});

it('rejects a guest', function (): void {
    $this->delete('/account')->assertRedirect('/login');
});

it('refuses to delete the demo account', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);

    $this->actingAs($demo)->delete('/account')
        ->assertSessionHasErrors('account');

    expect(User::query()->whereKey($demo->id)->exists())->toBeTrue();
    $this->assertAuthenticatedAs($demo);
});

it('leaves no orphaned narration, deliveries or push endpoints behind', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();

    // Every shape of ai_analyses subject: an activity, and a synthetic per-user
    // string. Neither has a foreign key back to users.
    $activityAnalysis = Analysis::factory()->done('A tidy run.')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);
    Analysis::factory()->done('Halo.')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
    ]);
    DB::table('notification_deliveries')->insert([
        'analysis_id' => $activityAnalysis->id,
        'channel' => 'telegram',
        'created_at' => now(),
    ]);
    DB::table('push_subscriptions')->insert([
        'subscribable_type' => $user->getMorphClass(),
        'subscribable_id' => $user->id,
        'endpoint' => 'https://push.example.test/endpoint-1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)->delete('/account')->assertRedirect(route('login'));

    expect(Analysis::query()->count())->toBe(0)
        // notification_deliveries has no user column either: it rides out on the
        // ai_analyses cascade, which only fires because we delete those by hand.
        ->and(DB::table('notification_deliveries')->count())->toBe(0)
        ->and(DB::table('push_subscriptions')->count())->toBe(0);
});

it('keeps token usage as cost history, orphaned under the old id', function (): void {
    $user = User::factory()->create();
    TokenUsage::query()->create([
        'user_id' => $user->id,
        'kind' => 'briefing',
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
        'total_tokens' => 15,
    ]);

    $this->actingAs($user)->delete('/account')->assertRedirect(route('login'));

    expect(TokenUsage::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('does not touch another user data', function (): void {
    $user = User::factory()->create();
    $bystander = User::factory()->create();
    Analysis::factory()->done('Punya orang lain.')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $bystander->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
    ]);
    DB::table('push_subscriptions')->insert([
        'subscribable_type' => $bystander->getMorphClass(),
        'subscribable_id' => $bystander->id,
        'endpoint' => 'https://push.example.test/bystander',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)->delete('/account')->assertRedirect(route('login'));

    expect(Analysis::query()->count())->toBe(1)
        ->and(DB::table('push_subscriptions')->count())->toBe(1);
});
