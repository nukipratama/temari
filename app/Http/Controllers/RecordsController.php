<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\PrScoreboardBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecordsController extends Controller
{
    public function __construct(
        private readonly PrScoreboardBuilder $scoreboardBuilder,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $personalRecords = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->orderBy('category')
            ->with([
                'activity:id',
                'activity.detail:id,activity_id,name,distance,moving_time,location_name,weather_temp_c,weather_humidity_pct,stream_summary',
            ])
            ->get();

        $analyses = Analysis::query()
            ->where('subject_type', PersonalRecord::class)
            ->where('analysis_type', AnalysisType::PrContext)
            ->whereIn('subject_id', $personalRecords->pluck('id'))
            ->get()
            ->keyBy('subject_id');

        $payload = $personalRecords->map(fn (PersonalRecord $row): array => [
            ...$row->toArray(),
            'context_analysis' => Analysis::toPayload(
                $analyses->get($row->id),
                AnalysisType::PrContext,
                PersonalRecord::class,
                $row->id,
            ),
        ])->all();

        $featured = $this->scoreboardBuilder->pickFeaturedPr($personalRecords);

        return Inertia::render('Collection/Records', [
            'personalRecords' => $payload,
            'featuredExtras' => $this->scoreboardBuilder->featuredExtras($featured),
        ]);
    }

}
