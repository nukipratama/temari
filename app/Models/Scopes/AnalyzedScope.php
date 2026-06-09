<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides un-ingested activity stubs (synced from Strava, `analyzed_at IS NULL`,
 * no detail/streams yet) from every Activity query by default, so only fully
 * ingested runs are ever shown or counted. The ingestion pipeline opts out via
 * {@see \App\Models\Activity::scopeWithStubs()}.
 *
 * @implements Scope<Activity>
 */
class AnalyzedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Qualify the column so the scope is unambiguous inside joins/whereHas.
        $builder->whereNotNull($model->qualifyColumn('analyzed_at'));
    }
}
