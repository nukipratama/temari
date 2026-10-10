<?php

declare(strict_types=1);

use App\Enums\SessionType;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Plan\ClampNarrationContext;
use App\Services\Run\Plan\ClampVoiceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-31 08:00:00');
    $this->user = User::factory()->create();
    PlannedSession::factory()->for($this->user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Interval,
        'rest_clamped_at' => Carbon::today(),
        'readiness_assessment' => [
            'ceiling' => 'rest',
            'reasons' => ['illness_reported'],
            'inputs' => ['form_status' => null],
        ],
    ]);
});
afterEach(fn () => Carbon::setTestNow());

function clampVoiceRow(User $user, AnalysisStatus $status, string $fingerprint): void
{
    Analysis::factory()->create([
        'subject_type' => AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanClampVoice,
        'discriminator' => Carbon::today()->toDateString(),
        'status' => $status,
        'content' => 'today is a full rest.',
        'content_fingerprint' => $fingerprint,
    ]);
}

it('returns the narration of a Done row whose fingerprint matches the live clamp', function (): void {
    clampVoiceRow($this->user, AnalysisStatus::Done, ClampNarrationContext::fingerprint(ReadinessCeiling::Rest, SessionType::Rest, false, ['illness_reported']));

    expect(app(ClampVoiceReader::class)->clampVoiceFor($this->user, Carbon::today()))->toBe('today is a full rest.');
});

it('returns null for a Done row fingerprinted against a different clamp', function (): void {
    clampVoiceRow($this->user, AnalysisStatus::Done, ClampNarrationContext::fingerprint(ReadinessCeiling::EasyOnly, SessionType::Easy, true));

    expect(app(ClampVoiceReader::class)->clampVoiceFor($this->user, Carbon::today()))->toBeNull();
});

it('returns null while the row is still pending', function (): void {
    clampVoiceRow($this->user, AnalysisStatus::Pending, ClampNarrationContext::fingerprint(ReadinessCeiling::Rest, SessionType::Rest, false, ['illness_reported']));

    expect(app(ClampVoiceReader::class)->clampVoiceFor($this->user, Carbon::today()))->toBeNull();
});

it('returns null when the day has no clamp', function (): void {
    expect(app(ClampVoiceReader::class)->clampVoiceFor(User::factory()->create(), Carbon::today()))->toBeNull();
});
