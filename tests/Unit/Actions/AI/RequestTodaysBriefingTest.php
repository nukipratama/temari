<?php

declare(strict_types=1);

use App\Actions\AI\RequestTodaysBriefing;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/** @return Collection<int, Analysis> */
function todaysBriefingRows(User $user): Collection
{
    return Analysis::query()
        ->where('subject_type', AnalysisType::BRIEFING_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::BriefingMascotVoice)
        ->where('discriminator', Carbon::today()->toDateString())
        ->get();
}

/**
 * A connected athlete whose backfill has already landed with nothing left
 * hydrating — the "not held" baseline every non-hold test needs.
 */
function backfilledUser(): User
{
    $user = User::factory()->create(['backfilled_at' => Carbon::now()]);
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()]);

    return $user;
}

it('stages the briefing Pending without dispatching at signup while the backfill has not landed yet (#1032)', function (): void {
    Bus::fake();
    // backfilled_at is null: the backfill sync hasn't even run, so there is no
    // Activity row yet for the row-based gate to read.
    $user = User::factory()->create();

    app(RequestTodaysBriefing::class)->atSignup($user);

    $row = todaysBriefingRows($user)->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe(AnalysisStatus::Pending);
    Bus::assertNothingDispatched();
});

it('dispatches the briefing at signup once the backfill has already landed', function (): void {
    Bus::fake();
    $user = backfilledUser();

    app(RequestTodaysBriefing::class)->atSignup($user);

    expect(todaysBriefingRows($user))->toHaveCount(1);
    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
});

it('never duplicates the row when signup runs twice', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    app(RequestTodaysBriefing::class)->atSignup($user);
    app(RequestTodaysBriefing::class)->atSignup($user);

    expect(todaysBriefingRows($user))->toHaveCount(1);
});

it('leaves an already-narrated row alone at signup', function (): void {
    $user = backfilledUser();
    app(RequestTodaysBriefing::class)->atSignup($user);
    $row = todaysBriefingRows($user)->first();
    $row->update(['status' => AnalysisStatus::Done, 'content' => 'already read']);

    Bus::fake();
    app(RequestTodaysBriefing::class)->atSignup($user);

    Bus::assertNothingDispatched();
    expect($row->refresh()->content)->toBe('already read');
});

it('serves the demo account from the rule-based filler without dispatching', function (): void {
    Bus::fake();
    $user = User::factory()->create(['is_demo' => true]);

    app(RequestTodaysBriefing::class)->atSignup($user);

    $row = todaysBriefingRows($user)->first();
    expect($row->status)->toBe(AnalysisStatus::Done)
        ->and($row->content)->not->toBeNull();
    Bus::assertNothingDispatched();
});

// BriefingMascotVoice stamps no material fingerprint, so nothing re-opens a
// Done row on its own: the backfill hook has to invalidate, and this is the
// test that would fail if it stopped doing so.
it('re-narrates the briefing once the backfill lands', function (): void {
    $user = backfilledUser();
    app(RequestTodaysBriefing::class)->atSignup($user);
    $row = todaysBriefingRows($user)->first();
    $row->update(['status' => AnalysisStatus::Done, 'content' => 'read against an empty history']);

    Bus::fake();
    app(RequestTodaysBriefing::class)->afterBackfill($user);

    expect(todaysBriefingRows($user))->toHaveCount(1)
        ->and($row->refresh()->status)->toBe(AnalysisStatus::Queued);
    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
});

it('re-requests at most once per athlete per day', function (): void {
    Bus::fake();
    $user = backfilledUser();

    app(RequestTodaysBriefing::class)->afterBackfill($user);
    app(RequestTodaysBriefing::class)->afterBackfill($user);

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
});

it('re-requests again the next day', function (): void {
    Bus::fake();
    $user = backfilledUser();

    app(RequestTodaysBriefing::class)->afterBackfill($user);
    Carbon::setTestNow(Carbon::tomorrow()->addHour());
    app(RequestTodaysBriefing::class)->afterBackfill($user);

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 2);

    Carbon::setTestNow();
});

// --- #1054: narrates right away (the early pass) once the backfill has landed ---

it('narrates from afterBackfill() right away even while detail hydration is still in progress', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    // backfilled_at is set (KickoffRecapsJob stamps it right before calling
    // afterBackfill()); the summary-only activity below still awaits the
    // detail hydration strava:hydrate-backlog runs separately, but that no
    // longer holds the briefing — see docs/decisions/history-narrates-on-demand.md.
    $user = User::factory()->create(['backfilled_at' => Carbon::now()]);
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()]);
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()->subDay()]);

    Bus::fake();
    app(RequestTodaysBriefing::class)->afterBackfill($user);

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
    expect(todaysBriefingRows($user)->first()?->status)->toBe(AnalysisStatus::Queued);

    Carbon::setTestNow();
});
