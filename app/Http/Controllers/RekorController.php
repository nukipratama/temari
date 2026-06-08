<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PrCategory;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\ProgressionSeriesBuilder;
use App\Services\Run\PrScoreboardBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RekorController extends Controller
{
    /**
     * Distances offered in the /rekor progression selector, longest last so the
     * client's last-tab default lands on the headline distance.
     *
     * @var list<PrCategory>
     */
    private const array PROGRESSION_CATEGORIES = [
        PrCategory::Km5,
        PrCategory::Km10,
        PrCategory::HalfMarathon,
        PrCategory::Marathon,
    ];

    public function __construct(
        private readonly ProgressionSeriesBuilder $progressionSeriesBuilder,
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
                'activity.detail:id,activity_id,name,distance,moving_time,location_name,weather_temp_c,weather_humidity_pct,splits_metric',
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
        $progressionByCategory = $this->buildProgressionByCategory($user, $personalRecords);

        return Inertia::render('Koleksi/Rekor', [
            'personalRecords' => $payload,
            'featuredExtras' => $this->scoreboardBuilder->featuredExtras($featured),
            'progressionByCategory' => $progressionByCategory,
        ]);
    }

    /**
     * Build a weekly-best progression series for each distance the runner has a
     * PR in, so /rekor can offer a 5K / 10K / HM / FM selector. Keyed by the
     * PrCategory value; a category with too few in-window runs is omitted.
     *
     * @param  Collection<int, PersonalRecord>  $records
     * @return array<string, array{category:string, weeks:array<int,string>, times_sec:array<int,int>, goal_sec:int|null}>
     */
    private function buildProgressionByCategory(User $user, Collection $records): array
    {
        $prs = [];
        foreach (self::PROGRESSION_CATEGORIES as $category) {
            $pr = $records->first(fn (PersonalRecord $record): bool => $record->category === $category);
            if ($pr !== null) {
                $prs[] = $pr;
            }
        }

        return $this->progressionSeriesBuilder->buildMany(
            $user,
            $prs,
            fn (PersonalRecord $pr): ?int => $this->scoreboardBuilder->milestoneFor($pr)['target_sec'],
        );
    }
}
