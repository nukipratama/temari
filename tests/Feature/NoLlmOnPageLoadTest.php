<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeAkuProfileVoiceJob;
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

    Bus::assertNotDispatched(AnalyzeAkuProfileVoiceJob::class);
});

it('does not enqueue any LLM job on GET /calendar (Activities · Calendar)', function (): void {
    $this->actingAs(User::factory()->create())->get('/calendar?month=2026-05')->assertSuccessful();

    Bus::assertNotDispatched(AnalyzeMonthlyRecapJob::class);
});

it('does not enqueue any LLM job on GET /activities (Activities · Feed)', function (): void {
    $this->actingAs(User::factory()->create())->get('/activities')->assertSuccessful();

    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});
