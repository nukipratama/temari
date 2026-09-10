<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzePlanDayVoiceJob;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A regenerated plan can move its training days, and the very first plan starts
 * on the day the athlete registers — so rows written for days outside it have
 * no session and never will. Left Failed they showed in /devtools/narration as "still
 * auto-retrying" behind a Try again that could not succeed.
 */
it('deletes its row when the day has no planned session', function (): void {
    $user = User::factory()->create();
    $row = Analysis::factory()->create([
        'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanDayVoice,
        'discriminator' => '2026-08-31',
        'status' => AnalysisStatus::Pending,
    ]);

    new AnalyzePlanDayVoiceJob($row->id)->handle(app(AnalysisService::class));

    expect($row->fresh())->toBeNull();
});
