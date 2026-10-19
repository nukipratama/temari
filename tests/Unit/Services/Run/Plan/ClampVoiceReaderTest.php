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
});
afterEach(fn () => Carbon::setTestNow());

function clampedAthlete(): User
{
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Interval,
        'clamped_km' => 6.0,
        'readiness_assessment' => [
            'ceiling' => 'moderate_ok',
            'reasons' => ['demanding_session_within_24h'],
            'inputs' => ['form_status' => null],
        ],
    ]);

    return $user;
}

function clampVoiceRow(User $user, AnalysisStatus $status, string $fingerprint): void
{
    Analysis::factory()->create([
        'subject_type' => AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanClampVoice,
        'discriminator' => Carbon::today()->toDateString(),
        'status' => $status,
        'content' => 'today is an easy run.',
        'content_fingerprint' => $fingerprint,
    ]);
}

it('returns the narration of a Done row whose fingerprint matches the live clamp', function (): void {
    $user = clampedAthlete();
    clampVoiceRow($user, AnalysisStatus::Done, ClampNarrationContext::fingerprint(ReadinessCeiling::ModerateOk, SessionType::Easy, false, ['demanding_session_within_24h']));

    expect(app(ClampVoiceReader::class)->clampVoiceFor($user, Carbon::today()))->toBe('today is an easy run.');
});

it('returns null for a Done row fingerprinted against a different clamp', function (): void {
    $user = clampedAthlete();
    clampVoiceRow($user, AnalysisStatus::Done, ClampNarrationContext::fingerprint(ReadinessCeiling::EasyOnly, SessionType::Easy, true));

    expect(app(ClampVoiceReader::class)->clampVoiceFor($user, Carbon::today()))->toBeNull();
});

it('returns null while the row is still pending', function (): void {
    $user = clampedAthlete();
    clampVoiceRow($user, AnalysisStatus::Pending, ClampNarrationContext::fingerprint(ReadinessCeiling::ModerateOk, SessionType::Easy, false, ['demanding_session_within_24h']));

    expect(app(ClampVoiceReader::class)->clampVoiceFor($user, Carbon::today()))->toBeNull();
});

it('returns null when the day has no clamp', function (): void {
    expect(app(ClampVoiceReader::class)->clampVoiceFor(User::factory()->create(), Carbon::today()))->toBeNull();
});

it('matches a live clamp against the readiness assessment its caller holds', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString(), 'session_type' => SessionType::Interval]);
    clampVoiceRow($user, AnalysisStatus::Done, ClampNarrationContext::fingerprint(ReadinessCeiling::EasyOnly, SessionType::Easy, false, ['form_overreaching']));
    $held = ['ceiling' => 'easy_only', 'reasons' => ['form_overreaching'], 'inputs' => []];

    expect(app(ClampVoiceReader::class)->clampVoiceFor($user, Carbon::today()))->toBeNull()
        ->and(app(ClampVoiceReader::class)->clampVoiceFor($user, Carbon::today(), $held))->toBe('today is an easy run.');
});
