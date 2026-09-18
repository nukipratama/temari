<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\Scopes\KnownAnalysisTypeScope;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Inserts a raw ai_analyses row bypassing Eloquent, so its analysis_type
 * never touches the enum cast on write — matching the shape of the 1,686
 * orphaned prod rows left behind when RunInsightTechnical/Splits/Zones
 * consolidated into RunInsight without a cleanup migration.
 */
function insertRetiredTypeRow(int $subjectId, string $retiredType = 'run_insight_technical'): void
{
    DB::table('ai_analyses')->insert([
        'subject_type' => Activity::class,
        'subject_id' => $subjectId,
        'analysis_type' => $retiredType,
        'discriminator' => null,
        'status' => 'done',
        'content' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('hides a retired-type row from default Analysis queries', function (): void {
    $activity = Activity::factory()->analyzed()->create();
    insertRetiredTypeRow($activity->id);
    $live = Analysis::factory()->done('x')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::RunInsight,
    ]);

    expect(Analysis::query()->pluck('id')->all())->toBe([$live->id])
        ->and(Analysis::query()->count())->toBe(1);
});

it('does not crash reading analysis_type on the rows a default query does return', function (): void {
    $activity = Activity::factory()->analyzed()->create();
    insertRetiredTypeRow($activity->id, 'run_insight_splits');
    insertRetiredTypeRow($activity->id, 'run_insight_zones');
    Analysis::factory()->done('x')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::RunInsight,
    ]);

    $types = Analysis::query()->get()->map(fn (Analysis $row): string => $row->analysis_type->value)->all();

    expect($types)->toBe([AnalysisType::RunInsight->value]);
});

it('reveals a retired-type row only when withoutGlobalScope opts out', function (): void {
    $activity = Activity::factory()->analyzed()->create();
    insertRetiredTypeRow($activity->id);

    expect(Analysis::query()->withoutGlobalScope(KnownAnalysisTypeScope::class)->count())->toBe(1)
        ->and(Analysis::query()->count())->toBe(0);
});

it('hides a trend_read row under a retired discriminator', function (): void {
    $live = Analysis::factory()->done('holding steady')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '7d',
    ]);
    Analysis::factory()->done('an old 30d read')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '30d',
    ]);
    Analysis::factory()->done('an old 12mo read')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '12mo',
    ]);

    expect(Analysis::query()->pluck('id')->all())->toBe([$live->id])
        ->and(Analysis::query()->count())->toBe(1);
});

it('reveals a retired-discriminator trend_read row only when withoutGlobalScope opts out', function (): void {
    Analysis::factory()->done('an old 90d read')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '90d',
    ]);

    expect(Analysis::query()->withoutGlobalScope(KnownAnalysisTypeScope::class)->count())->toBe(1)
        ->and(Analysis::query()->count())->toBe(0);
});

it('does not hide a null-discriminator row of an unrelated type', function (): void {
    $row = Analysis::factory()->done('x')->create([
        'analysis_type' => AnalysisType::CardFlavor,
        'discriminator' => null,
    ]);

    expect(Analysis::query()->pluck('id')->all())->toBe([$row->id]);
});
