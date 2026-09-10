<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\AI\AnalysisVersion;
use App\Services\AI\ServedBy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts served_by to the enum and generated_at to a date', function (): void {
    $version = AnalysisVersion::factory()->create([
        'served_by' => ServedBy::RuleBased,
        'generated_at' => '2026-09-01 10:00:00',
    ]);

    expect($version->fresh()->served_by)->toBe(ServedBy::RuleBased)
        ->and($version->fresh()->generated_at?->toDateString())->toBe('2026-09-01');
});

it('belongs to the analysis row it superseded', function (): void {
    $row = Analysis::factory()->done()->create();
    $version = AnalysisVersion::factory()->create(['analysis_id' => $row->id]);

    expect($version->analysis->id)->toBe($row->id);
});

it('is deleted with the analysis row, so a removed block leaves no orphan history', function (): void {
    $row = Analysis::factory()->done()->create();
    $version = AnalysisVersion::factory()->create(['analysis_id' => $row->id]);

    $row->delete();

    expect(AnalysisVersion::query()->find($version->id))->toBeNull();
});
