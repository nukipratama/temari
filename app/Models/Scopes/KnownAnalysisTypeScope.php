<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\AI\Analysis;
use App\Services\AI\AnalysisType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Excludes `ai_analyses` rows whose `analysis_type` no longer matches a live
 * {@see AnalysisType} case from every query by default. A narration surface's
 * enum case can be retired (a lens consolidation, a cut feature) without a
 * cleanup migration for its historical rows, and the enum cast throws a
 * ValueError the moment such a row is hydrated and its `analysis_type` is
 * read — this scope stops that at the query boundary instead of relying on
 * every caller to remember a manual filter. {@see \App\Services\User\UserEraser}
 * opts out via `withoutGlobalScope` since account erasure must still reach
 * retired-type rows.
 *
 * @implements Scope<Analysis>
 */
class KnownAnalysisTypeScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereIn(
            $model->qualifyColumn('analysis_type'),
            array_column(AnalysisType::cases(), 'value'),
        );
    }
}
