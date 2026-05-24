<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Jobs\AI\AnalyzePersonaSummaryJob;
use App\Jobs\AI\AnalyzeTrendCaptionJob;
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
it('does not enqueue any LLM job on GET / (Hari Ini)', function (): void {
    $this->actingAs(User::factory()->create())->get('/')->assertSuccessful();

    Bus::assertNotDispatched(AnalyzeBriefingJob::class);
    Bus::assertNotDispatched(AnalyzeDailyGreetingJob::class);
    Bus::assertNotDispatched(AnalyzeTrendCaptionJob::class);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('does not enqueue any LLM job on GET /profil (Aku)', function (): void {
    $this->actingAs(User::factory()->create())->get('/profil')->assertSuccessful();

    Bus::assertNotDispatched(AnalyzePersonaSummaryJob::class);
});

it('does not enqueue any LLM job on GET /kalender (Riwayat · Kalender)', function (): void {
    $this->actingAs(User::factory()->create())->get('/kalender?month=2026-05')->assertSuccessful();

    Bus::assertNotDispatched(AnalyzeMonthlyRecapJob::class);
});

it('does not enqueue any LLM job on GET /aktivitas (Riwayat · Linimasa)', function (): void {
    $this->actingAs(User::factory()->create())->get('/aktivitas')->assertSuccessful();

    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});
