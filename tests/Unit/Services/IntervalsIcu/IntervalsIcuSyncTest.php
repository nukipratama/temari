<?php

declare(strict_types=1);

use App\Enums\IntervalsIcuSyncStatus;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\IntervalsIcuConnection;
use App\Models\PlannedSession;
use App\Models\RecommendationRevision;
use App\Models\RecommendationView;
use App\Models\User;
use App\Notifications\IntervalsIcuDisconnectedNotification;
use App\Services\IntervalsIcu\IntervalsIcuSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-14 06:00:00');
    Notification::fake();
    intervalsIcuState(['threshold' => 163, 'status' => 200, 'put' => 200, 'id' => 501]);
    Http::fake(fn (Request $request) => match (true) {
        intervalsIcuState()['status'] !== 200 => Http::response('', intervalsIcuState()['status']),
        $request->method() === 'GET' => Http::response(['sportSettings' => [['types' => ['Run'], 'lthr' => intervalsIcuState()['threshold']]]]),
        $request->method() === 'POST' => Http::response([['id' => intervalsIcuState()['id']]]),
        $request->method() === 'PUT' => Http::response('', intervalsIcuState()['put']),
        default => Http::response([]),
    });
});

function syncedAthlete(SessionType $today = SessionType::Easy): IntervalsIcuConnection
{
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-10-14', 'phase' => PlanPhase::Base, 'session_type' => $today]);

    return IntervalsIcuConnection::factory()->for($user)->create(['athlete_id' => 'i42']);
}

/**
 * @param  array{threshold: int|null, status: int, put: int, id: int}|null  $replace
 * @return array{threshold: int|null, status: int, put: int, id: int}
 */
function intervalsIcuState(?array $replace = null): array
{
    static $state = ['threshold' => 163, 'status' => 200, 'put' => 200, 'id' => 501];

    if ($replace !== null) {
        $state = $replace;
    }

    return $state;
}

/** @param  array{threshold?: int|null, status?: int, put?: int, id?: int}  $state */
function intervalsIcuAnswers(array $state): void
{
    intervalsIcuState([...intervalsIcuState(), ...$state]);
    Http::fake();
}

/** @return array<int, string> */
function writesSent(): array
{
    return Http::recorded()
        ->map(fn (array $pair): string => $pair[0]->method().' '.str_replace('https://intervals.icu/api/v1', '', $pair[0]->url()))
        ->reject(fn (string $call): bool => str_starts_with($call, 'GET'))
        ->values()
        ->all();
}

function runSync(IntervalsIcuConnection $connection): IntervalsIcuConnection
{
    app(IntervalsIcuSync::class)->sync($connection, Carbon::today());

    return $connection->refresh();
}

it("sends today's run once and keeps its id", function (): void {

    $connection = runSync(syncedAthlete());

    expect(writesSent())->toBe(['POST /athlete/i42/events/bulk?upsert=true'])
        ->and($connection->event_id)->toBe('501')
        ->and($connection->synced_on?->toDateString())->toBe('2026-10-14')
        ->and($connection->sync_status)->toBe(IntervalsIcuSyncStatus::Sent)
        ->and($connection->payload_hash)->toHaveLength(64);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()[0]['start_date_local'] === '2026-10-14T00:00:00'
        && $request->data()[0]['external_id'] === 'temari-'.$connection->user_id.'-2026-10-14'
        && str_contains(Arr::string($request->data()[0], 'description'), '% LTHR'));
});

it('counts the sent workout as advice the athlete was shown, so the day is graded on it', function (): void {
    $connection = runSync(syncedAthlete());

    $view = RecommendationView::query()->sole();
    $revision = RecommendationRevision::query()->findOrFail($view->recommendation_revision_id);

    expect($revision->user_id)->toBe($connection->user_id)
        ->and($revision->date->toDateString())->toBe('2026-10-14')
        ->and($revision->effective['session_type'])->toBe('easy')
        ->and(Carbon::parse(Arr::string($view->getRawOriginal(), 'shown_at'), 'UTC')->equalTo(Carbon::now()))->toBeTrue();
});

it('records no view on a day that keeps its prescription beside advice the watch never carries', function (): void {
    $connection = syncedAthlete(SessionType::Interval);
    PlannedSession::query()->where('user_id', $connection->user_id)->update(['pinned' => true]);
    seedDemandingRunYesterday($connection->user);

    $connection = runSync($connection);

    expect($connection->sync_status)->toBe(IntervalsIcuSyncStatus::Sent)
        ->and(RecommendationView::query()->count())->toBe(0);
});

it('records no new view when nothing new is sent', function (): void {
    $connection = runSync(syncedAthlete());
    intervalsIcuAnswers([]);

    runSync($connection);

    expect(RecommendationView::query()->count())->toBe(1);
});

it('sends nothing when today is unchanged', function (): void {
    $connection = runSync(syncedAthlete());
    intervalsIcuAnswers([]);

    runSync($connection);

    expect(writesSent())->toBe([]);
});

it('updates the same event in place when today changes', function (): void {
    $connection = runSync(syncedAthlete());
    intervalsIcuAnswers(['threshold' => 170]);

    $connection = runSync($connection);

    expect(writesSent())->toBe(['PUT /athlete/i42/events/501'])
        ->and($connection->event_id)->toBe('501');
});

it('sends a new event when the athlete deleted the one it would update', function (): void {
    $connection = runSync(syncedAthlete());
    intervalsIcuAnswers(['threshold' => 170, 'put' => 404, 'id' => 777]);

    $connection = runSync($connection);

    expect(writesSent())->toBe(['PUT /athlete/i42/events/501', 'POST /athlete/i42/events/bulk?upsert=true'])
        ->and($connection->event_id)->toBe('777');
});

it('removes the event when today becomes a rest day or is skipped', function (array $change): void {
    $connection = runSync(syncedAthlete());
    PlannedSession::query()->where('user_id', $connection->user_id)->sole()->update($change);
    intervalsIcuAnswers([]);

    $connection = runSync($connection);

    expect(writesSent())->toBe(['DELETE /athlete/i42/events/501'])
        ->and($connection->event_id)->toBeNull()
        ->and($connection->sync_status)->toBeNull();
})->with([
    'rest' => [['session_type' => SessionType::Rest]],
    'rest already scored done' => [['session_type' => SessionType::Rest, 'status' => PlannedSessionStatus::Done]],
    'skipped' => [['skipped' => true]],
]);

it('sends nothing on a rest day', function (): void {

    $connection = runSync(syncedAthlete(SessionType::Rest));

    expect(Http::recorded())->toHaveCount(0)
        ->and($connection->sync_status)->toBeNull();
});

it('does not send a day without paces when intervals.icu has no run threshold', function (): void {
    intervalsIcuAnswers(['threshold' => null]);

    $connection = runSync(syncedAthlete());

    expect(writesSent())->toBe([])
        ->and($connection->event_id)->toBeNull()
        ->and($connection->sync_status)->toBe(IntervalsIcuSyncStatus::NoThresholdHeartRate);
});

it("leaves yesterday's event and sends a new one for today", function (): void {
    $connection = syncedAthlete();
    $connection->update(['synced_on' => '2026-10-13', 'event_id' => '400', 'payload_hash' => str_repeat('a', 64), 'sync_status' => IntervalsIcuSyncStatus::Sent]);

    $connection = runSync($connection);

    expect(writesSent())->toBe(['POST /athlete/i42/events/bulk?upsert=true'])
        ->and($connection->event_id)->toBe('501');
});

it('marks a rejected key broken and tells the athlete once', function (): void {
    intervalsIcuAnswers(['status' => 401]);
    $connection = syncedAthlete();

    runSync($connection);
    $connection = runSync($connection);

    expect($connection->broken_at)->not->toBeNull()
        ->and(Http::recorded())->toHaveCount(1);
    Notification::assertSentToTimes($connection->user, IntervalsIcuDisconnectedNotification::class, 1);
});

it('keeps the event and marks today unreachable when intervals.icu fails', function (): void {
    $connection = runSync(syncedAthlete());
    intervalsIcuAnswers(['status' => 503]);

    $connection = runSync($connection);

    expect($connection->event_id)->toBe('501')
        ->and($connection->sync_status)->toBe(IntervalsIcuSyncStatus::Unreachable)
        ->and($connection->broken_at)->toBeNull();
});

it("removes only today's event on request", function (string $syncedOn, array $writes): void {
    $connection = syncedAthlete();
    $connection->update(['synced_on' => $syncedOn, 'event_id' => '400']);

    app(IntervalsIcuSync::class)->removeToday($connection, Carbon::today());

    expect(writesSent())->toBe($writes);
})->with([
    'today' => ['2026-10-14', ['DELETE /athlete/i42/events/400']],
    'yesterday' => ['2026-10-13', []],
]);

it('removes today best effort and logs a failure instead of throwing', function (int $status, array $writes): void {
    intervalsIcuAnswers(['status' => $status]);
    $log = Log::spy();
    $connection = syncedAthlete();
    $connection->update(['synced_on' => '2026-10-14', 'event_id' => '400']);

    app(IntervalsIcuSync::class)->removeTodayBestEffort($connection, Carbon::today());

    expect(writesSent())->toBe($writes);
    $status === 200 ? $log->shouldNotHaveReceived('warning') : $log->shouldHaveReceived('warning')->once();
})->with([
    'accepted' => [200, ['DELETE /athlete/i42/events/400']],
    'rejected' => [401, ['DELETE /athlete/i42/events/400']],
    'unreachable' => [503, ['DELETE /athlete/i42/events/400']],
]);

it('skips a broken connection when removing best effort', function (): void {
    Http::fake();
    $connection = syncedAthlete();
    $connection->update(['broken_at' => now(), 'synced_on' => '2026-10-14', 'event_id' => '400']);

    app(IntervalsIcuSync::class)->removeTodayBestEffort($connection, Carbon::today());

    Http::assertNothingSent();
});

it("leaves today's workout as sent once today's run is credited, even when the day changes", function (array $change): void {
    $connection = runSync(syncedAthlete());
    PlannedSession::query()->where('user_id', $connection->user_id)->sole()->update(['status' => PlannedSessionStatus::Done, ...$change]);
    intervalsIcuAnswers(['threshold' => 170]);

    $connection = runSync($connection);

    expect(Http::recorded())->toHaveCount(0)
        ->and($connection->event_id)->toBe('501')
        ->and($connection->sync_status)->toBe(IntervalsIcuSyncStatus::Sent);
})->with([
    'threshold changed' => [[]],
    'turned into a long run' => [['session_type' => SessionType::Long]],
]);

it('sends nothing for a run already credited before the athlete connected', function (): void {
    $connection = syncedAthlete();
    PlannedSession::query()->where('user_id', $connection->user_id)->sole()->update(['status' => PlannedSessionStatus::Done]);

    $connection = runSync($connection);

    expect(Http::recorded())->toHaveCount(0)
        ->and($connection->event_id)->toBeNull();
});
