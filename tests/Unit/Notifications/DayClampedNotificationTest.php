<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Enums\SessionType;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\DayClampedNotification;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaterialFingerprint;
use App\Services\Run\Metrics\ReadinessCeiling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
    $this->date = '2026-09-08';
    $this->note = "Recovery's still catching up, today's a full rest.";
});

function clampNotification(SessionType $clampedTo = SessionType::Rest): DayClampedNotification
{
    return new DayClampedNotification(test()->date, $clampedTo, test()->note);
}

// A clamp is advisory and the briefing path records it at 00:01, so a lock
// screen is the wrong place for it; the master switch does not name it either.
it('stays in the inbox even for an athlete every outbound channel could reach', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['revoked_at' => null]);
    $user->updatePushSubscription('https://push.example/endpoint', 'key', 'auth');

    expect(clampNotification()->via($user->fresh()))->toBe([InAppChannel::class]);
});

it('records a row that opens the home page', function (): void {
    $message = clampNotification()->toInbox(User::factory()->create());

    expect($message->kind)->toBe(NotificationKind::PlanClamp)
        ->and($message->title)->toBe("Today's a full rest")
        ->and($message->body)->toBe($this->note)
        ->and($message->payload)->toBe(['url' => route('dashboard')]);
});

it('names an eased day as eased rather than rested', function (): void {
    expect(clampNotification(SessionType::Easy)->toInbox(User::factory()->create())->title)
        ->toBe('Today eases off');
});

// A repeated morning briefing must not stack a second clamp row for the day.
it('dedupes on the clamped date', function (): void {
    expect(clampNotification()->toInbox(User::factory()->create())->dedupeKey)
        ->toBe('plan_clamp:'.$this->date);
});

it('prefers the clamp narration once one has landed', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create([
        'date' => $this->date,
        'session_type' => SessionType::Interval,
        'rest_clamped_at' => Carbon::parse($this->date),
        'readiness_assessment' => [
            'ceiling' => 'rest',
            'reasons' => ['illness_reported'],
            'inputs' => ['form_status' => null],
        ],
    ]);
    Analysis::factory()->create([
        'subject_type' => AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanClampVoice,
        'discriminator' => $this->date,
        'status' => AnalysisStatus::Done,
        'content' => 'you reported feeling ill, so today is a full rest.',
        'content_fingerprint' => MaterialFingerprint::forClamp(ReadinessCeiling::Rest, SessionType::Rest, false, ['illness_reported']),
    ]);

    expect(clampNotification()->toInbox($user)->body)
        ->toBe('you reported feeling ill, so today is a full rest.');
});

it('keeps the factual fallback when a completed narration has no matching decision', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanClampVoice,
        'discriminator' => $this->date,
        'status' => AnalysisStatus::Done,
        'content' => 'old advice from a different assessment.',
    ]);

    expect(clampNotification()->toInbox($user)->body)->toBe($this->note);
});

// The templated note is the permanent floor `the-clamp-explains-itself` keeps
// it as, so a paused or still-pending narration is never an empty row.
it('falls back to the templated note while the narration is only pending', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanClampVoice,
        'discriminator' => $this->date,
        'status' => AnalysisStatus::Pending,
        'content' => null,
    ]);

    expect(clampNotification()->toInbox($user)->body)->toBe($this->note);
});

it('reads the narration for its own date, not another day', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanClampVoice,
        'discriminator' => Carbon::parse($this->date)->subDay()->toDateString(),
        'status' => AnalysisStatus::Done,
        'content' => 'yesterday said something else.',
    ]);

    expect(clampNotification()->toInbox($user)->body)->toBe($this->note);
});
