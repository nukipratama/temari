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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** The readiness state that bottoms the ceiling out, same shape RestClampRecorderTest uses. */
function tiredUser(): User
{
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'form_status' => 'overreaching',
        'monotony' => 1.0,
    ]);

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
        ->and($context['clamped_to'])->toBe(SessionType::Rest)
        ->and($context['ceiling'])->toBe(ReadinessCeiling::Rest)
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

/** The renderer exempts a pinned row from the clamp, so there is nothing to explain. */
it('resolves nothing for a pinned day', function (): void {
    $user = tiredUser();
    clampDay($user, pinned: true);

    expect(resolveClamp($user))->toBeNull();
});

it('resolves nothing when there is no session, or no user at all', function (): void {
    $user = tiredUser();

    expect(resolveClamp($user))->toBeNull()
        ->and(app(ClampNarrationContext::class)->forUserOn(404, Carbon::today()))->toBeNull();
});
