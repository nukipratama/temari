<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    config()->set('ai.cooldown_seconds', 300);
});

it('returns null cooldown for non-Done rows', function (): void {
    foreach ([AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Processing, AnalysisStatus::Failed] as $i => $status) {
        $row = Analysis::factory()->create([
            'discriminator' => "2026-05-{$i}",
            'status' => $status,
            'generated_at' => Carbon::now(),
        ]);
        expect($row->cooldownRemaining())->toBeNull("status={$status->value}");
    }
});

it('returns null cooldown when generated_at is null', function (): void {
    $row = Analysis::factory()->create([
        'status' => AnalysisStatus::Done,
        'content' => 'x',
        'generated_at' => null,
    ]);
    expect($row->cooldownRemaining())->toBeNull();
});

it('returns positive remaining seconds when within cooldown window', function (): void {
    $row = Analysis::factory()->done('x')->create([
        'generated_at' => Carbon::now()->subSeconds(30),
    ]);
    expect($row->cooldownRemaining())->toBeGreaterThan(0)->toBeLessThanOrEqual(300);
});

it('returns null when cooldown has elapsed', function (): void {
    $row = Analysis::factory()->done('x')->create([
        'generated_at' => Carbon::now()->subHour(),
    ]);
    expect($row->cooldownRemaining())->toBeNull();
});

it('returns null when cooldown is disabled via config', function (): void {
    config()->set('ai.cooldown_seconds', 0);
    $row = Analysis::factory()->done('x')->create([
        'generated_at' => Carbon::now()->subSeconds(5),
    ]);
    expect($row->cooldownRemaining())->toBeNull();
});

it('toPayload includes retry_after_seconds derived from the row', function (): void {
    $row = Analysis::factory()->done('hi')->create([
        'generated_at' => Carbon::now()->subSeconds(60),
    ]);

    $payload = Analysis::toPayload($row, AnalysisType::BriefingHeadline, $row->subject_type, $row->subject_id, $row->discriminator);

    expect($payload['retry_after_seconds'])->toBeGreaterThan(0)->toBeLessThanOrEqual(300);
});

it('toPayload returns retry_after_seconds null when row is null', function (): void {
    $payload = Analysis::toPayload(null, AnalysisType::BriefingHeadline, 'briefing_user_day', 1, '2026-05-20');
    expect($payload['retry_after_seconds'])->toBeNull();
});
