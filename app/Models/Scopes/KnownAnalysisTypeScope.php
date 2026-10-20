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
 * default: the enum cast throws a ValueError the moment such a row is hydrated.
 * {@see AnalysisType::TrendRead} is the one type with a closed set of ranges
 * today ({@see AnalysisType::TREND_READ_RANGES}).
 * {@see \App\Services\User\UserEraser} opts out via `withoutGlobalScope` since
 * account erasure must still reach retired-type and retired-range rows.
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
