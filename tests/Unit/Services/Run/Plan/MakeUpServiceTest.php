<?php

declare(strict_types=1);

use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Jobs\Run\ReconcilePlanJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\MakeUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    Bus::fake();
});
afterEach(fn () => Carbon::setTestNow());

const MAKE_UP_CLAMP = [
    'clamped_km' => 3.0,
    'rest_clamped_at' => '2026-08-11 06:00:00',
    'eased_pace_sec_per_km' => 420,
    'readiness_assessment' => ['ceiling' => 'easy_only', 'reasons' => [], 'inputs' => []],
];

/**
 * A missed Easy already swapped off `$vacatedDate` onto `$targetDate`, where
 * a 5 km run landed.
 *
 * @return array{User, PlannedSession, PlannedSession, Activity}
 */
function swappedMakeUp(string $vacatedDate, string $targetDate, array $userAttributes = []): array
{
    $user = User::factory()->create($userAttributes);
    $vacated = PlannedSession::factory()->for($user)->rest()->create([
        'date' => $vacatedDate,
        'status' => PlannedSessionStatus::Missed,
        'compliance_score' => 0,
        'distance_score' => 0,
        ...MAKE_UP_CLAMP,
    ]);
    $target = PlannedSession::factory()->for($user)->create([
        'date' => $targetDate,
        'session_type' => SessionType::Easy,
        ...MAKE_UP_CLAMP,
    ]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse("{$targetDate} 06:00:00"),
        'distance' => 5000,
        'moving_time' => 1800,
        'elapsed_time' => 1800,
    ]);

    return [$user, $vacated, $target, $activity];
}

function applyMakeUp(User $user, PlannedSession $vacated, PlannedSession $target): void
{
    DB::transaction(fn () => app(MakeUpService::class)->apply($user, $vacated, $target, Carbon::today()));
    app(MakeUpService::class)->notify($user, $vacated->date, $target->date);
}

it('links the two days and clears the clamp state on both', function (): void {
    [$user, $vacated, $target] = swappedMakeUp('2026-08-11', '2026-08-12');

    applyMakeUp($user, $vacated, $target);

    foreach ([$vacated->fresh(), $target->fresh()] as $row) {
        expect($row->clamped_km)->toBeNull()
            ->and($row->rest_clamped_at)->toBeNull()
            ->and($row->eased_pace_sec_per_km)->toBeNull()
            ->and($row->readiness_assessment)->toBeNull();
    }
    expect($vacated->fresh()->made_up_on->toDateString())->toBe('2026-08-12')
        ->and($target->fresh()->made_up_from_id)->toBe($vacated->id);
});

it('regrades both days in full: the made-up day on the moved session, the emptied one as rest', function (): void {
    [$user, $vacated, $target] = swappedMakeUp('2026-08-11', '2026-08-12');

    applyMakeUp($user, $vacated, $target);

    expect($vacated->fresh()->status)->toBe(PlannedSessionStatus::Done)
        ->and($vacated->fresh()->distance_score)->toBeNull()
        ->and($vacated->fresh()->compliance_score)->toBeNull()
        ->and($target->fresh()->prescribed_km)->toBeGreaterThan(0.0)
        ->and($target->fresh()->distance_score)->toBeGreaterThan(0)
        ->and($target->fresh()->intent_evidence['advice_history'])->toBe('declared_after_run');
});

it('marks the plan for reconciliation from the earlier of the two days', function (): void {
    [$user, $vacated, $target] = swappedMakeUp('2026-08-11', '2026-08-12');

    applyMakeUp($user, $vacated, $target);

    expect($user->fresh()->plan_reconciliation_pending_from->toDateString())->toBe('2026-08-11');
    Bus::assertDispatched(ReconcilePlanJob::class);
});

it('keeps the demo athlete rule-based, with no LLM call and no reconciliation', function (): void {
    [$user, $vacated, $target] = swappedMakeUp('2026-08-11', '2026-08-12', ['is_demo' => true]);

    applyMakeUp($user, $vacated, $target);

    Bus::assertNothingDispatched();
    expect($target->fresh()->intent_evidence['advice_history'])->toBe('declared_after_run');
});
