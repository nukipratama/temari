<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\NarrationEligibility;
use App\Services\AI\NarrationVerdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-15 09:00:00');
    config()->set('ai.backfill_max_age_days', 84);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function eligibilityAthlete(int $lastSeenDaysAgo = 0, bool $demo = false, string $connectedAt = '2026-09-01 12:00:00'): User
{
    $factory = $demo ? User::factory()->demo() : User::factory();
    $user = $factory->create(['last_seen_at' => Carbon::now()->subDays($lastSeenDaysAgo)]);
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::parse($connectedAt)]);

    return $user;
}

function eligibilityRun(User $user, string $startedAt, IngestState $state = IngestState::Detailed): Activity
{
    $activity = Activity::factory()->for($user)->create([
        'ingest_state' => $state,
        'analyzed_at' => $state === IngestState::Detailed ? Carbon::now() : null,
    ]);
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($startedAt)]);

    return $activity;
}

function ingestVerdict(User $user, string $startedAt): NarrationVerdict
{
    return app(NarrationEligibility::class)->forIngestedRun($user, Carbon::parse($startedAt));
}

it('narrates an active athlete run logged after the connect', function (): void {
    expect(ingestVerdict(eligibilityAthlete(), '2026-09-14 06:00:00'))->toBe(NarrationVerdict::Eligible);
});

it('ranks demo above every other ingest reason', function (): void {
    $demo = eligibilityAthlete(lastSeenDaysAgo: 30, demo: true);

    expect(ingestVerdict($demo, '2025-01-01 06:00:00'))->toBe(NarrationVerdict::Demo);
});

it('ranks too old above pre-connect and inactive', function (): void {
    $away = eligibilityAthlete(lastSeenDaysAgo: 30);

    expect(ingestVerdict($away, '2025-01-01 06:00:00'))->toBe(NarrationVerdict::TooOld);
});

it('ranks pre-connect above inactive', function (): void {
    $away = eligibilityAthlete(lastSeenDaysAgo: 30);

    expect(ingestVerdict($away, '2026-08-20 06:00:00'))->toBe(NarrationVerdict::PreConnect);
});

it('narrates a historical run inside the last 7 days instead of deferring to pre-connect', function (): void {
    $user = eligibilityAthlete(connectedAt: '2026-09-14 12:00:00');

    expect(ingestVerdict($user, '2026-09-10 06:00:00'))->toBe(NarrationVerdict::Eligible);
});

it('still defers a historical run older than 7 days to pre-connect for an active athlete', function (): void {
    $user = eligibilityAthlete();

    expect(ingestVerdict($user, '2026-08-20 06:00:00'))->toBe(NarrationVerdict::PreConnect);
});

it('defers a fresh run of an athlete away past the active window', function (): void {
    $away = eligibilityAthlete(lastSeenDaysAgo: 8);

    expect(ingestVerdict($away, '2026-09-14 06:00:00'))->toBe(NarrationVerdict::Inactive);
});

it('defers a fresh run of an athlete who has never opened the app', function (): void {
    $user = eligibilityAthlete();
    $user->forceFill(['last_seen_at' => null])->save();

    expect(ingestVerdict($user, '2026-09-14 06:00:00'))->toBe(NarrationVerdict::Inactive);
});

it('serves a demo manual trigger rule-based before any age or backlog check', function (): void {
    $demo = eligibilityAthlete(demo: true);
    $run = eligibilityRun($demo, '2026-08-20 06:00:00');
    eligibilityRun($demo, '2026-08-10 06:00:00', IngestState::Summary);

    expect(app(NarrationEligibility::class)->forManualTrigger($demo, AnalysisType::PostRunSpeech, $run->id, null))
        ->toBe(NarrationVerdict::Demo);
});

it('ranks too old above awaiting backlog on a manual trigger', function (): void {
    $user = eligibilityAthlete();
    eligibilityRun($user, '2025-01-01 06:00:00', IngestState::Summary);
    $run = eligibilityRun($user, '2025-01-02 06:00:00');
    $card = RunCard::factory()->create(['activity_id' => $run->id]);

    expect(app(NarrationEligibility::class)->forManualTrigger($user, AnalysisType::CardFlavor, $card->id, null))
        ->toBe(NarrationVerdict::TooOld);
});

it('holds a manual trigger on history while an older run in the window still hydrates', function (): void {
    $user = eligibilityAthlete();
    eligibilityRun($user, '2026-08-10 06:00:00', IngestState::Summary);
    $run = eligibilityRun($user, '2026-08-20 06:00:00');

    expect(app(NarrationEligibility::class)->forManualTrigger($user, AnalysisType::PostRunSpeech, $run->id, null))
        ->toBe(NarrationVerdict::AwaitingBacklog);
});

it('narrates a manual trigger on hydrated history', function (): void {
    $user = eligibilityAthlete();
    $run = eligibilityRun($user, '2026-08-20 06:00:00');

    expect(app(NarrationEligibility::class)->forManualTrigger($user, AnalysisType::PostRunSpeech, $run->id, null))
        ->toBe(NarrationVerdict::Eligible);
});

it('never defers a manual trigger for inactivity', function (): void {
    $away = eligibilityAthlete(lastSeenDaysAgo: 30);
    $run = eligibilityRun($away, '2026-09-14 06:00:00');

    expect(app(NarrationEligibility::class)->forManualTrigger($away, AnalysisType::PostRunSpeech, $run->id, null))
        ->toBe(NarrationVerdict::Eligible);
});

it('holds an ingested run whose older history is still hydrating right after the connect', function (): void {
    $user = eligibilityAthlete(connectedAt: '2026-09-15 08:00:00');
    eligibilityRun($user, '2025-11-26 06:00:00', IngestState::Summary);

    expect(ingestVerdict($user, '2026-09-13 06:00:00'))->toBe(NarrationVerdict::AwaitingBacklog)
        ->and(ingestVerdict($user, '2026-09-15 08:30:00'))->toBe(NarrationVerdict::AwaitingBacklog);
});
