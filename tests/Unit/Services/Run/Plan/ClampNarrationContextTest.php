<?php

declare(strict_types=1);

use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Plan\ClampNarrationContext;
use App\Services\Run\Plan\RestClampRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function tiredUser(): User
{
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);

    return $user;
}

function clampDay(User $user, string $type = 'interval', bool $pinned = false): PlannedSession
{
    return PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => $type,
        'pinned' => $pinned,
    ]);
}

function resolveClamp(User $user): ?array
{
    return app(ClampNarrationContext::class)->forUserOn($user->id, Carbon::today());
}

it('resolves the facts a clamp explanation is written from', function (): void {
    $user = tiredUser();
    clampDay($user);

    $context = resolveClamp($user);

    expect($context)->not->toBeNull()
        ->and($context['original'])->toBe(SessionType::Interval)
        ->and($context['clamped_to'])->toBe(SessionType::Easy)
        ->and($context['ceiling'])->toBe(ReadinessCeiling::ModerateOk)
        ->and($context['has_run_today'])->toBeFalse();
});

it('reports a run already logged today, which is usually the reason', function (): void {
    $user = tiredUser();
    clampDay($user);
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::today()->setHour(7),
    ]);

    expect(resolveClamp($user)['has_run_today'])->toBeTrue();
});

it('resolves nothing when the day already fits under the ceiling', function (): void {
    $user = User::factory()->create();
    clampDay($user, 'easy');

    expect(resolveClamp($user))->toBeNull();
});

/**
 * The pace-only ease is rule-based only (see `RestClampRecorder`) and never
 * requests `plan_clamp_voice`: an Easy day at EasyOnly already fits under the
 * ceiling as far as this resolver is concerned, the same as the untired case
 * above.
 */
it('resolves nothing for the pace-ease boundary — an Easy day at an EasyOnly ceiling', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'form_status' => 'fatigued',
        'monotony' => 1.0,
    ]);
    clampDay($user, 'easy');

    expect(resolveClamp($user))->toBeNull();
});

it('explains the step-down on a pinned day too, since the renderer advises it there', function (): void {
    $user = tiredUser();
    clampDay($user, pinned: true);

    expect(resolveClamp($user))->not->toBeNull();
});

it('keeps a pinned race prescription while carrying the advice facts', function (): void {
    $user = User::factory()->create();
    $session = clampDay($user, 'race', pinned: true);
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::today()->setHour(7),
    ]);

    $context = resolveClamp($user);

    expect($session->fresh()->session_type)->toBe(SessionType::Race)
        ->and($context['clamped_to'])->toBe(SessionType::Easy)
        ->and($context['readiness_reasons'])->toContain('already_ran_today');
});

it('uses the recorded reason after a run', function (): void {
    $user = tiredUser();
    $session = clampDay($user);

    app(RestClampRecorder::class)->record($user, Carbon::today());
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::today()->setHour(7),
    ]);

    $context = resolveClamp($user);

    expect($session->fresh()->readiness_assessment['reasons'])->toContain('demanding_session_within_24h')
        ->and($context['decision_source'])->toBe('recorded')
        ->and($context['clamped_to'])->toBe(SessionType::Easy)
        ->and($context['readiness_reasons'])->toContain('demanding_session_within_24h')
        ->and($context['readiness_reasons'])->not->toContain('already_ran_today');
});

it('reads a recorded snapshot that carries a retired feedback input and reason codes', function (): void {
    $user = User::factory()->create();
    $session = clampDay($user);
    $session->forceFill([
        'clamped_km' => 3.6,
        'readiness_assessment' => [
            'ceiling' => 'easy_only',
            'reasons' => ['severe_fatigue_or_soreness_reported', 'stale_recovery_feedback_not_applied'],
            'inputs' => ['recovery_feedback' => ['freshness' => 'current', 'fatigue' => 'severe']],
        ],
    ])->save();

    $context = resolveClamp($user);

    expect($context['decision_source'])->toBe('recorded')
        ->and($context['ceiling'])->toBe(ReadinessCeiling::EasyOnly)
        ->and($context['clamped_to'])->toBe(SessionType::Easy)
        ->and($context['readiness_reasons'])->toBe(['severe_fatigue_or_soreness_reported', 'stale_recovery_feedback_not_applied'])
        ->and($context['readiness_inputs'])->toHaveKey('recovery_feedback');
});

it('reads a row left with a rest snapshot from before the rest clamp was removed live', function (): void {
    $user = tiredUser();
    $session = clampDay($user);
    $session->forceFill([
        'readiness_assessment' => [
            'ceiling' => 'rest',
            'reasons' => ['illness_reported'],
            'inputs' => ['recovery_feedback' => ['freshness' => 'current', 'illness' => true]],
        ],
    ])->save();

    $context = resolveClamp($user);

    expect($context['decision_source'])->toBe('live')
        ->and($context['ceiling'])->not->toBe(ReadinessCeiling::Rest)
        ->and($context['clamped_to'])->toBe(SessionType::Easy)
        ->and($context['readiness_reasons'])->not->toContain('illness_reported');
});

it('resolves nothing when there is no session, or no user at all', function (): void {
    $user = User::factory()->create();

    expect(resolveClamp($user))->toBeNull()
        ->and(app(ClampNarrationContext::class)->forUserOn(404, Carbon::today()))->toBeNull();
});
