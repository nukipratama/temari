<?php

declare(strict_types=1);

use App\Actions\AI\RequestTodaysBriefing;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Models\AI\Analysis;
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

it('creates and dispatches exactly one briefing row at signup', function (): void {
    Bus::fake();
    $user = User::factory()->create();

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
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();

    app(RequestTodaysBriefing::class)->afterBackfill($user);
    app(RequestTodaysBriefing::class)->afterBackfill($user);

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
});

it('re-requests again the next day', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    app(RequestTodaysBriefing::class)->afterBackfill($user);
    Carbon::setTestNow(Carbon::tomorrow()->addHour());
    app(RequestTodaysBriefing::class)->afterBackfill($user);

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 2);
});
