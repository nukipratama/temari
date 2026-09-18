<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\HistoryNarrationGate;
use App\Enums\IngestState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-15 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function athleteConnectedAt(string $connectedAt = '2026-09-01 12:00:00'): User
{
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::parse($connectedAt)]);

    return $user;
}

function historyRunFor(User $user, string $startedAt, IngestState $state = IngestState::Detailed): Activity
{
    $activity = Activity::factory()->for($user)->create([
        'ingest_state' => $state,
        'analyzed_at' => $state === IngestState::Detailed ? Carbon::now() : null,
    ]);
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($startedAt)]);

    return $activity;
}

it('calls a run before the Strava connect historical', function (): void {
    $user = athleteConnectedAt();

    expect(app(HistoryNarrationGate::class)->isHistorical($user, Carbon::parse('2026-08-20 06:00:00')))->toBeTrue();
});

it('does not call a run after the Strava connect historical', function (): void {
    $user = athleteConnectedAt();

    expect(app(HistoryNarrationGate::class)->isHistorical($user, Carbon::parse('2026-09-14 06:00:00')))->toBeFalse();
});

it('calls nothing historical for an athlete with no Strava connection', function (): void {
    expect(app(HistoryNarrationGate::class)->isHistorical(User::factory()->create(), Carbon::parse('2020-01-01 06:00:00')))
        ->toBeFalse();
});

it('makes an on-demand read wait while an older run inside the window is unhydrated', function (): void {
    $user = athleteConnectedAt();
    historyRunFor($user, '2026-08-01 06:00:00', IngestState::Summary);
    $clicked = historyRunFor($user, '2026-08-20 06:00:00');

    expect(app(HistoryNarrationGate::class)->awaitsHydration($user, AnalysisType::PostRunSpeech, $clicked->id))
        ->toBeTrue();
});

it('lets an on-demand read through once every older run in the window is hydrated', function (): void {
    $user = athleteConnectedAt();
    historyRunFor($user, '2026-08-01 06:00:00');
    $clicked = historyRunFor($user, '2026-08-20 06:00:00');

    expect(app(HistoryNarrationGate::class)->awaitsHydration($user, AnalysisType::PostRunSpeech, $clicked->id))
        ->toBeFalse();
});

it('ignores an unhydrated run older than past-you reach', function (): void {
    $user = athleteConnectedAt();
    historyRunFor($user, '2025-08-01 06:00:00', IngestState::Summary);
    $clicked = historyRunFor($user, '2026-08-20 06:00:00');

    expect(app(HistoryNarrationGate::class)->awaitsHydration($user, AnalysisType::PostRunSpeech, $clicked->id))
        ->toBeFalse();
});

it('ignores an unhydrated run that is newer than the one asked about', function (): void {
    $user = athleteConnectedAt();
    historyRunFor($user, '2026-08-25 06:00:00', IngestState::Summary);
    $clicked = historyRunFor($user, '2026-08-20 06:00:00');

    expect(app(HistoryNarrationGate::class)->awaitsHydration($user, AnalysisType::PostRunSpeech, $clicked->id))
        ->toBeFalse();
});

it('never holds back a post-connect run', function (): void {
    $user = athleteConnectedAt();
    historyRunFor($user, '2026-09-02 06:00:00', IngestState::Summary);
    $clicked = historyRunFor($user, '2026-09-14 06:00:00');

    expect(app(HistoryNarrationGate::class)->awaitsHydration($user, AnalysisType::PostRunSpeech, $clicked->id))
        ->toBeFalse();
});

it('never holds back a subject that is not one run', function (): void {
    $user = athleteConnectedAt();

    expect(app(HistoryNarrationGate::class)->awaitsHydration($user, AnalysisType::TrendRead, $user->id))->toBeFalse();
});

it('narrates a historical run automatically inside the last 7 days', function (): void {
    expect(app(HistoryNarrationGate::class)->narratesAutomatically(Carbon::parse('2026-09-10 06:00:00')))
        ->toBeTrue();
});

it('leaves a historical run older than 7 days for the on-demand read', function (): void {
    expect(app(HistoryNarrationGate::class)->narratesAutomatically(Carbon::parse('2026-08-20 06:00:00')))
        ->toBeFalse();
});

it('does not auto-narrate a null start date', function (): void {
    expect(app(HistoryNarrationGate::class)->narratesAutomatically(null))->toBeFalse();
});

it('holds automatic narration while an older run within past-you reach still hydrates, inside the grace window', function (): void {
    $user = athleteConnectedAt('2026-09-15 08:00:00');
    historyRunFor($user, '2025-11-26 06:00:00', IngestState::Summary);

    expect(app(HistoryNarrationGate::class)->awaitsOlderHydration($user->id, Carbon::parse('2026-09-13 06:00:00')))
        ->toBeTrue();
});

it('releases automatic narration once the grace window after connecting has passed', function (): void {
    $user = athleteConnectedAt('2026-09-13 08:00:00');
    historyRunFor($user, '2025-11-26 06:00:00', IngestState::Summary);

    expect(app(HistoryNarrationGate::class)->awaitsOlderHydration($user->id, Carbon::parse('2026-09-12 06:00:00')))
        ->toBeFalse();
});

it('does not hold automatic narration on history past past-you reach', function (): void {
    $user = athleteConnectedAt('2026-09-15 08:00:00');
    historyRunFor($user, '2025-09-01 06:00:00', IngestState::Summary);

    expect(app(HistoryNarrationGate::class)->awaitsOlderHydration($user->id, Carbon::parse('2026-09-13 06:00:00')))
        ->toBeFalse();
});

it('makes an on-demand read wait on an unhydrated run older than the backfill window but inside past-you reach', function (): void {
    $user = athleteConnectedAt();
    historyRunFor($user, '2025-11-26 06:00:00', IngestState::Summary);
    $clicked = historyRunFor($user, '2026-08-20 06:00:00');

    expect(app(HistoryNarrationGate::class)->awaitsHydration($user, AnalysisType::PostRunSpeech, $clicked->id))
        ->toBeTrue();
});

it('holds full hydration while any run of the backlog is unhydrated, even outside past-you reach', function (): void {
    $user = athleteConnectedAt('2026-09-15 08:00:00');
    historyRunFor($user, '2020-01-01 06:00:00', IngestState::Summary);

    expect(app(HistoryNarrationGate::class)->awaitsFullHydration($user->id))->toBeTrue();
});

it('releases full hydration once every run of the backlog has hydrated', function (): void {
    $user = athleteConnectedAt('2026-09-15 08:00:00');
    historyRunFor($user, '2020-01-01 06:00:00');

    expect(app(HistoryNarrationGate::class)->awaitsFullHydration($user->id))->toBeFalse();
});

it('releases full hydration once the grace window after connecting has passed, despite a stuck backlog entry', function (): void {
    $user = athleteConnectedAt('2026-09-13 08:00:00');
    historyRunFor($user, '2020-01-01 06:00:00', IngestState::Summary);

    expect(app(HistoryNarrationGate::class)->awaitsFullHydration($user->id))->toBeFalse();
});

it('never holds full hydration for an athlete with no Strava connection', function (): void {
    $user = User::factory()->create();
    historyRunFor($user, '2020-01-01 06:00:00', IngestState::Summary);

    expect(app(HistoryNarrationGate::class)->awaitsFullHydration($user->id))->toBeFalse();
});
