<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Support\Cooldown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/** Start the re-trigger cooldown window for a row and return its key. */
function startRowCooldown(Analysis $row): string
{
    $key = Analysis::cooldownKey($row->analysis_type, $row->subject_id, $row->discriminator);
    RateLimiter::hit($key, Cooldown::WINDOW_SECONDS);

    return $key;
}

it('returns null cooldown for non-Done rows even with an active window', function (): void {
    foreach ([AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Processing, AnalysisStatus::Failed] as $i => $status) {
        $row = Analysis::factory()->create([
            'discriminator' => "2026-05-{$i}",
            'status' => $status,
        ]);
        startRowCooldown($row);
        expect($row->cooldownRemaining())->toBeNull("status={$status->value}");
    }
});

it('returns null cooldown for a Done row with no active window', function (): void {
    $row = Analysis::factory()->done('x')->create();
    expect($row->cooldownRemaining())->toBeNull();
});

it('returns positive remaining seconds while the window is active', function (): void {
    $row = Analysis::factory()->done('x')->create();
    startRowCooldown($row);
    expect($row->cooldownRemaining())->toBeGreaterThan(0)->toBeLessThanOrEqual(Cooldown::WINDOW_SECONDS);
});

it('returns null once the window is released', function (): void {
    $row = Analysis::factory()->done('x')->create();
    $key = startRowCooldown($row);
    RateLimiter::clear($key);
    expect($row->cooldownRemaining())->toBeNull();
});

it('toPayload surfaces retry_after_seconds from the active window', function (): void {
    $row = Analysis::factory()->done('hi')->create();
    startRowCooldown($row);

    $payload = Analysis::toPayload($row, $row->analysis_type, $row->subject_type, $row->subject_id, $row->discriminator);

    expect($payload['retry_after_seconds'])->toBeGreaterThan(0)->toBeLessThanOrEqual(Cooldown::WINDOW_SECONDS);
});

it('telegramCooldownRemaining is null for a missing or not-Done payload', function (): void {
    expect(Analysis::telegramCooldownRemaining(['id' => null, 'status' => 'done']))->toBeNull()
        ->and(Analysis::telegramCooldownRemaining(['id' => 7, 'status' => 'pending']))->toBeNull();
});

it('telegramCooldownRemaining reflects an active Telegram window for a Done payload', function (): void {
    RateLimiter::hit(Cooldown::telegramKey(7), Cooldown::WINDOW_SECONDS);

    expect(Analysis::telegramCooldownRemaining(['id' => 7, 'status' => 'done']))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(Cooldown::WINDOW_SECONDS);
});

it('toPayload returns retry_after_seconds null when row is null', function (): void {
    $payload = Analysis::toPayload(null, AnalysisType::BriefingHeadline, 'briefing_user_day', 1, '2026-05-20');
    expect($payload['retry_after_seconds'])->toBeNull();
});

it('payloadsForSubjects returns an empty array for no ids', function (): void {
    expect(Analysis::payloadsForSubjects('briefing_user_day', AnalysisType::WeeklyRecap, []))->toBe([]);
});

it('payloadsForSubjects keys payloads by subject id, falling back to a pending payload', function (): void {
    $subjectType = 'weekly_snapshot';

    $done = Analysis::factory()->done('recap text')->create([
        'subject_type' => $subjectType,
        'subject_id' => 10,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $payloads = Analysis::payloadsForSubjects($subjectType, AnalysisType::WeeklyRecap, [10, 20]);

    expect(array_keys($payloads))->toBe([10, 20])
        ->and($payloads[10]['id'])->toBe($done->id)
        ->and($payloads[10]['status'])->toBe('done')
        ->and($payloads[10]['content'])->toBe('recap text')
        ->and($payloads[20]['id'])->toBeNull()
        ->and($payloads[20]['status'])->toBe('pending')
        ->and($payloads[20]['subject_id'])->toBe(20);
});

it('payloadsForSubjects ignores rows of a different type or subject_type', function (): void {
    $subjectType = 'weekly_snapshot';

    Analysis::factory()->done('wrong type')->create([
        'subject_type' => $subjectType,
        'subject_id' => 30,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => null,
    ]);

    $payloads = Analysis::payloadsForSubjects($subjectType, AnalysisType::WeeklyRecap, [30]);

    expect($payloads[30]['id'])->toBeNull()
        ->and($payloads[30]['status'])->toBe('pending');
});
