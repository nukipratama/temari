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
 * {@see AnalysisType} case, or whose `discriminator` no longer matches a live
 * range for a type that narrows its own discriminators, from every query by
 * default. A narration surface's enum case can be retired (a lens
 * consolidation, a cut feature) without a cleanup migration for its
 * historical rows, and the enum cast throws a ValueError the moment such a
 * row is hydrated and its `analysis_type` is read — this scope stops that at
 * the query boundary instead of relying on every caller to remember a manual
 * filter. {@see AnalysisType::TrendRead} is the one type-with-a-closed-set
 * case today: `30d`/`90d`/`12mo` retired from
 * {@see AnalysisType::TREND_READ_RANGES} (#967), and their stored rows are
 * hidden here the same way a retired *type* is, since `analysis_type` alone
 * still matches a live case for them. {@see \App\Services\User\UserEraser}
 * opts out via `withoutGlobalScope` since account erasure must still reach
 * retired-type and retired-range rows.
 *
 * @implements Scope<Analysis>
 */
class KnownAnalysisTypeScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $typeColumn = $model->qualifyColumn('analysis_type');
        $discriminatorColumn = $model->qualifyColumn('discriminator');

        $builder
            ->whereIn($typeColumn, array_column(AnalysisType::cases(), 'value'))
            ->where(function (Builder $query) use ($typeColumn, $discriminatorColumn): void {
                $query->where($typeColumn, '!=', AnalysisType::TrendRead->value)
                    ->orWhereIn($discriminatorColumn, AnalysisType::TREND_READ_RANGES);
            });
    }
}
