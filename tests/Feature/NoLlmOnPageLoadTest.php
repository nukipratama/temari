<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeProfileVoiceJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

/**
 * Page-load invariant: visiting the primary surfaces against a fresh user
 * (no existing Analysis rows) must not enqueue any LLM job. Analyses are
 * always user-triggered via the AnalysisStatus "Analisis sekarang" button.
 */
it('does not enqueue any LLM job on GET / (Today)', function (): void {
    $this->actingAs(User::factory()->create())->get('/')->assertSuccessful();

    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('does not enqueue any LLM job on GET /profile', function (): void {
    $this->actingAs(User::factory()->create())->get('/profile')->assertSuccessful();

    Bus::assertNotDispatched(AnalyzeProfileVoiceJob::class);
});

it('does not enqueue any LLM job on GET /history?view=calendar', function (): void {
    $this->actingAs(User::factory()->create())->get('/history?view=calendar&month=2026-05')->assertSuccessful();

    Bus::assertNotDispatched(AnalyzeMonthlyRecapJob::class);
});

it('does not enqueue any LLM job on GET /history', function (): void {
    $this->actingAs(User::factory()->create())->get('/history')->assertSuccessful();

    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});
