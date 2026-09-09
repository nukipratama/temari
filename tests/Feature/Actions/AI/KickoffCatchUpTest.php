<?php

declare(strict_types=1);

use App\Actions\AI\KickoffCatchUp;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Monday 2026-05-18, so the whole Monday kickoff block is in scope and the
    // last fully-closed week ends Sunday 2026-05-17.
    Carbon::setTestNow('2026-05-18 09:00:00');
    Bus::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function activeAthlete(bool $demo = false): User
{
    $user = $demo ? User::factory()->demo()->create() : User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()->subDay()]);

    return $user;
}

function rowFor(AnalysisType $type, int $subjectId, ?string $discriminator): ?Analysis
{
    return Analysis::query()
        ->where('subject_type', $type->subjectType())
        ->where('subject_id', $subjectId)
        ->where('analysis_type', $type)
        ->where('discriminator', $discriminator)
        ->first();
}

it('creates every kickoff row a missed 00:01 and Monday block would have created, and dispatches none of them', function (): void {
    $user = activeAthlete();
    $lastWeek = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    $created = app(KickoffCatchUp::class)();

    expect($created)->toBe(3);

    $briefing = rowFor(AnalysisType::BriefingMascotVoice, $user->id, '2026-05-18');
    $voice = rowFor(AnalysisType::ProfileVoice, $user->id, AnalysisType::currentIsoWeek());
    $recap = rowFor(AnalysisType::WeeklyRecap, $lastWeek->id, null);

    expect($briefing?->status)->toBe(AnalysisStatus::Pending)
        ->and($voice?->status)->toBe(AnalysisStatus::Pending)
        ->and($recap?->status)->toBe(AnalysisStatus::Pending);

    // Filling is ai:self-heal's job — the sweep only ever creates.
    Bus::assertNothingDispatched();
});

it('creates nothing on a second run', function (): void {
    $user = activeAthlete();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    app(KickoffCatchUp::class)();

    expect(app(KickoffCatchUp::class)())->toBe(0)
        ->and(Analysis::query()->count())->toBe(3);
});

it('leaves an existing row of any status exactly as it found it', function (): void {
    $user = activeAthlete();

    Analysis::factory()->done('temari note')->create([
        'subject_type' => AnalysisType::BriefingMascotVoice->subjectType(),
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    $deadLettered = Analysis::factory()->create([
        'subject_type' => AnalysisType::ProfileVoice->subjectType(),
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::ProfileVoice,
        'discriminator' => AnalysisType::currentIsoWeek(),
        'status' => AnalysisStatus::Failed,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);

    expect(app(KickoffCatchUp::class)())->toBe(0);

    $briefing = rowFor(AnalysisType::BriefingMascotVoice, $user->id, '2026-05-18');
    expect($briefing?->status)->toBe(AnalysisStatus::Done)
        ->and($briefing?->content)->toBe('temari note')
        ->and($deadLettered->refresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($deadLettered->attempts)->toBe(Analysis::MAX_SELF_HEAL_ATTEMPTS);
});

it('never resurrects a day the kickoff itself would have skipped', function (): void {
    // Dormant: no run inside the active window, so no kickoff was owed at all.
    $dormant = User::factory()->create();
    $old = Activity::factory()->for($dormant)->analyzed()->create();
    ActivityDetail::factory()->for($old)->create(['start_date_local' => Carbon::today()->subDays(30)]);

    $active = activeAthlete();

    app(KickoffCatchUp::class)();

    expect(Analysis::query()->where('subject_id', $dormant->id)->count())->toBe(0)
        // Yesterday's missed briefing stays missed: the sweep only ever offers today's.
        ->and(rowFor(AnalysisType::BriefingMascotVoice, $active->id, '2026-05-17'))->toBeNull();
});

it('creates nothing for the demo account', function (): void {
    $demo = activeAthlete(demo: true);
    WeeklySnapshot::factory()->for($demo)->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    expect(app(KickoffCatchUp::class)())->toBe(0)
        ->and(Analysis::query()->count())->toBe(0);
});

it('does not stage a recap for a week that has not closed', function (): void {
    $user = activeAthlete();
    $openWeek = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-24', 'runs' => 2]);

    app(KickoffCatchUp::class)();

    expect(rowFor(AnalysisType::WeeklyRecap, $openWeek->id, null))->toBeNull();
});
