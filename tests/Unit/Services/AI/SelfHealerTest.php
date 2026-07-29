<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChainResolver;
use App\Services\AI\SelfHealer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// The resume sweep caps every chain at the latest fully-closed period
// (week_ending <= last-closed Sunday / discriminator <= last-closed month), so
// these tests are wall-clock dependent. Pin "now" to a fixed Wednesday whose
// last-closed week is 2026-06-14 and last-closed month is 2026-05.
beforeEach(function (): void {
    Carbon::setTestNow('2026-06-17');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<int, array<string, mixed>>  $captured
 */
function captureResumeRequests(array &$captured): AnalysisService
{
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null, ?int $delaySeconds = null, bool $invalidate = false) use (&$captured): Analysis {
            $captured[] = compact('subjectOrType', 'subjectId', 'type', 'discriminator', 'invalidate');

            return new Analysis();
        });
    // Per-activity chains advance through the group helper, not request().
    $service->shouldReceive('requestActivityGroup')
        ->andReturnUsing(function (Activity $activity, bool $invalidate = false, ?int $delaySeconds = null) use (&$captured): void {
            $captured[] = ['subjectOrType' => Activity::class, 'subjectId' => $activity->id, 'type' => AnalysisType::PostRunSpeech, 'discriminator' => null, 'invalidate' => $invalidate];
        });

    return $service;
}

/** A service mock that must never dispatch. */
function nonDispatchingResumeService(): AnalysisService
{
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $service->shouldNotReceive('requestActivityGroup');

    return $service;
}

function selfHealer(AnalysisService $service): SelfHealer
{
    return new SelfHealer($service, new ChainResolver());
}

/** Seed an activity for $user dated $startDate whose post-run speech is Pending. */
function pendingActivityChainLink(User $user, string $startDate): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($startDate)]);
    Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    return $activity;
}

it('re-kicks the earliest Pending weekly link per user with invalidate:false', function (): void {
    $user = User::factory()->create();
    $earliest = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-04-05', 'runs' => 3]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $earliest->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);
    // A later Pending week must NOT be the one resumed (earliest wins).
    $later = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 4]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $later->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];

    expect(selfHealer(captureResumeRequests($captured))->run())->toBe(1);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(WeeklySnapshot::class)
        ->and($captured[0]['subjectId'])->toBe($earliest->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::WeeklyRecap)
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('skips a demo user so the resume net never auto-bills its weekly LLM', function (): void {
    $demo = User::factory()->demo()->create();
    $snap = WeeklySnapshot::factory()->for($demo)->create(['week_ending' => '2026-05-03', 'runs' => 3]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    expect(selfHealer(nonDispatchingResumeService())->run())->toBe(0);
});

it('re-kicks the earliest Pending monthly link per user with invalidate:false', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-03',
        'status' => AnalysisStatus::Pending,
    ]);
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-05',
        'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];

    expect(selfHealer(captureResumeRequests($captured))->run())->toBe(1);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
        ->and($captured[0]['subjectId'])->toBe($user->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::MonthlyRecap)
        ->and($captured[0]['discriminator'])->toBe('2026-03')
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('resumes both weekly and monthly chains in one sweep', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 3]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-04',
        'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];

    expect(selfHealer(captureResumeRequests($captured))->run())->toBe(2);

    expect(array_column($captured, 'type'))
        ->toContain(AnalysisType::WeeklyRecap)
        ->toContain(AnalysisType::MonthlyRecap);
});

it('re-kicks the earliest Pending per-activity group per user', function (): void {
    $user = User::factory()->create();
    $earliest = pendingActivityChainLink($user, '2026-05-01 06:00:00');
    // A later Pending run must NOT be the one resumed (earliest wins).
    pendingActivityChainLink($user, '2026-05-10 06:00:00');

    $captured = [];

    expect(selfHealer(captureResumeRequests($captured))->run())->toBe(1);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(Activity::class)
        ->and($captured[0]['subjectId'])->toBe($earliest->id)
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('re-kicks the earliest stalled CardFlavor per user with invalidate:false', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $card = RunCard::factory()->for($activity)->create();
    Analysis::factory()->create([
        'subject_type' => RunCard::class,
        'subject_id' => $card->id,
        'analysis_type' => AnalysisType::CardFlavor,
        'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];

    expect(selfHealer(captureResumeRequests($captured))->run())->toBe(1);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(RunCard::class)
        ->and($captured[0]['subjectId'])->toBe($card->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::CardFlavor)
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('batches multiple stalled CardFlavor rows per user, capped at the drain batch', function (): void {
    $user = User::factory()->create();
    // 12 stalled cards; the non-cascading drain batch is 10.
    for ($i = 0; $i < 12; $i++) {
        $activity = Activity::factory()->for($user)->create();
        $card = RunCard::factory()->for($activity)->create();
        Analysis::factory()->create([
            'subject_type' => RunCard::class,
            'subject_id' => $card->id,
            'analysis_type' => AnalysisType::CardFlavor,
            'status' => AnalysisStatus::Pending,
        ]);
    }

    $captured = [];

    expect(selfHealer(captureResumeRequests($captured))->run())->toBe(10);

    expect($captured)->toHaveCount(10)
        ->and(collect($captured)->pluck('type')->unique()->all())->toBe([AnalysisType::CardFlavor]);
});

it('batches multiple stalled PrContext rows per user', function (): void {
    $user = User::factory()->create();
    // Pin distinct categories: the factory picks one at random, so three PRs for
    // one user would otherwise sometimes collide on the (user_id, category) unique.
    $categories = ['5km', '10km', 'half_marathon'];
    for ($i = 0; $i < 3; $i++) {
        $pr = PersonalRecord::factory()->for($user)->create([
            'category' => $categories[$i],
            'set_at' => Carbon::parse('2026-05-0'.($i + 1)),
        ]);
        Analysis::factory()->create([
            'subject_type' => PersonalRecord::class,
            'subject_id' => $pr->id,
            'analysis_type' => AnalysisType::PrContext,
            'status' => AnalysisStatus::Pending,
        ]);
    }

    $captured = [];

    expect(selfHealer(captureResumeRequests($captured))->run())->toBe(3);

    expect($captured)->toHaveCount(3);
});

it('recovers a Failed PrContext under the retry budget', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->for($user)->create(['set_at' => Carbon::parse('2026-05-01')]);
    Analysis::factory()->failed()->create([
        'subject_type' => PersonalRecord::class,
        'subject_id' => $pr->id,
        'analysis_type' => AnalysisType::PrContext,
    ]);

    $captured = [];

    expect(selfHealer(captureResumeRequests($captured))->run())->toBe(1);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(PersonalRecord::class)
        ->and($captured[0]['subjectId'])->toBe($pr->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::PrContext)
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('leaves Done links alone (nothing stalled to resume)', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 3]);
    Analysis::factory()->done('already narrated')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);
    Analysis::factory()->done('month narrated')->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-04',
    ]);

    expect(selfHealer(nonDispatchingResumeService())->run())->toBe(0);
});

it('recovers a Failed weekly link (not only Pending)', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-31', 'runs' => 3]);
    Analysis::factory()->failed()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $captured = [];

    expect(selfHealer(captureResumeRequests($captured))->run())->toBe(1);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectId'])->toBe($snap->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::WeeklyRecap)
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('does not resume a Failed link that has burned its retry budget (dead-lettered)', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-31', 'runs' => 3]);
    Analysis::factory()->failed()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);

    expect(selfHealer(nonDispatchingResumeService())->run())->toBe(0);
});

it('recovers a Failed monthly link', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->failed()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-05',
    ]);

    $captured = [];

    expect(selfHealer(captureResumeRequests($captured))->run())->toBe(1);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['type'])->toBe(AnalysisType::MonthlyRecap)
        ->and($captured[0]['discriminator'])->toBe('2026-05');
});

it('skips the still-open current week (never narrates it early)', function (): void {
    // now = 2026-06-17: current week ends 2026-06-21 (> last closed 2026-06-14).
    $user = User::factory()->create();
    $open = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-06-21', 'runs' => 2]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $open->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    expect(selfHealer(nonDispatchingResumeService())->run())->toBe(0);
});

it('skips the still-open current month', function (): void {
    // now = 2026-06-17: current month 2026-06 (> last closed 2026-05).
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-06',
        'status' => AnalysisStatus::Pending,
    ]);

    expect(selfHealer(nonDispatchingResumeService())->run())->toBe(0);
});

it('re-kicks the earliest stalled briefing suggestion per user with invalidate:false', function (): void {
    $user = User::factory()->create();
    $earliest = Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'status' => AnalysisStatus::Pending,
    ]);
    // A later Pending briefing day must NOT be the one resumed (earliest wins).
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-06-01',
        'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];

    expect(selfHealer(captureResumeRequests($captured))->run())->toBe(1);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(AnalysisType::BRIEFING_SUBJECT_TYPE)
        ->and($captured[0]['subjectId'])->toBe($user->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::BriefingMascotVoice)
        ->and($captured[0]['discriminator'])->toBe($earliest->discriminator)
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('skips a demo user for the briefing suggestion so the resume net never auto-bills it', function (): void {
    $demo = User::factory()->demo()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $demo->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'status' => AnalysisStatus::Pending,
    ]);

    expect(selfHealer(nonDispatchingResumeService())->run())->toBe(0);
});

it('re-kicks the earliest stalled single-row block per user with invalidate:false', function (AnalysisType $type, string $subjectType, ?string $discriminator): void {
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => $subjectType,
        'subject_id' => $user->id,
        'analysis_type' => $type,
        'discriminator' => $discriminator,
        'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];

    expect(selfHealer(captureResumeRequests($captured))->run())->toBe(1);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe($subjectType)
        ->and($captured[0]['subjectId'])->toBe($user->id)
        ->and($captured[0]['type'])->toBe($type)
        ->and($captured[0]['discriminator'])->toBe($discriminator)
        ->and($captured[0]['invalidate'])->toBeFalse();
})->with([
    'BriefingMascotVoice' => [AnalysisType::BriefingMascotVoice, AnalysisType::BRIEFING_SUBJECT_TYPE, '2026-05-18'],
    'BriefingFeaturedKartuVoice' => [AnalysisType::BriefingFeaturedKartuVoice, AnalysisType::BRIEFING_SUBJECT_TYPE, '42'],
    'AkuProfileVoice' => [AnalysisType::AkuProfileVoice, AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE, '2026-W21'],
]);

it('skips a demo user for a single-row type so the resume net never auto-bills it', function (): void {
    $demo = User::factory()->demo()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE,
        'subject_id' => $demo->id,
        'analysis_type' => AnalysisType::AkuProfileVoice,
        'discriminator' => '2026-W21',
        'status' => AnalysisStatus::Pending,
    ]);

    expect(selfHealer(nonDispatchingResumeService())->run())->toBe(0);
});

it('sweeps past a retired-type row left in flight instead of dying on the enum cast', function (): void {
    $user = User::factory()->create();
    DB::table('ai_analyses')->insert([
        'subject_type' => 'trend_caption_user_day',
        'subject_id' => $user->id,
        'analysis_type' => 'trend_caption',
        'discriminator' => '2026-05-18',
        'status' => AnalysisStatus::Queued->value,
        'attempts' => 0,
        'queued_at' => Carbon::now()->subDay(),
        'created_at' => Carbon::now()->subDay(),
        'updated_at' => Carbon::now()->subDay(),
    ]);

    expect(selfHealer(nonDispatchingResumeService())->run())->toBe(0);

    expect(DB::table('ai_analyses')->where('analysis_type', 'trend_caption')->value('status'))
        ->toBe(AnalysisStatus::Queued->value);
});
