<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RaceGoal;
use App\Models\User;
use App\Actions\Run\Metrics\EstimateThresholdAction;
use App\Services\Gamification\SeasonStreakSummaryBuilder;
use App\Services\Run\LifetimeStats;
use App\Services\Run\Metrics\TimeInZoneSummary;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\SeasonService;
use App\Services\Run\Plan\SeasonSummaryBuilder;
use App\Services\Run\ProgressionSeriesBuilder;
use App\Services\AI\AnalysisType;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use App\Enums\PrCategory;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * @var list<PrCategory>
     */
    private const array PROGRESSION_CATEGORIES = [
        PrCategory::Km5,
        PrCategory::Km10,
        PrCategory::HalfMarathon,
        PrCategory::Marathon,
    ];

    private const float RACE_DISTANCE_TOLERANCE = 0.05;

    public function __invoke(
        Request $request,
        ProgressionSeriesBuilder $progressionSeriesBuilder,
        LifetimeStats $lifetimeStats,
        VdotEstimator $vdotEstimator,
        EstimateThresholdAction $thresholdEstimator,
        TrainingPaceCalculator $trainingPaceCalculator,
        TimeInZoneSummary $timeInZoneSummary,
        SeasonService $seasonService,
        SeasonStreakSummaryBuilder $seasonStreakBuilder,
        SeasonSummaryBuilder $seasonSummaryBuilder,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();
        $lifetime = $lifetimeStats->forUser($user);

        $personalRecords = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->orderBy('category')
            ->get();

        $progressionByCategory = $this->buildProgressionByCategory($progressionSeriesBuilder, $user, $personalRecords);

        // peekCurrent, never ensureCurrent: opening Profile must not create a
        // season or fire the grant side effects a Plan page load does.
        $season = $seasonService->peekCurrent($user, $today);

        return Inertia::render('Profile', [
            'identity' => [
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
                'first_run_at' => $lifetime['first_run_at'],
                'member_since' => $user->created_at?->toIso8601String(),
                'strava_connected' => $user->stravaConnection !== null,
            ],
            'stats' => [
                'total_runs' => $lifetime['total_runs'],
                'total_km' => $lifetime['total_km'],
                'longest_run_km' => $lifetime['longest_km'],
            ],
            'profileVoice' => $this->resolveProfileVoice($user),
            'progressionByCategory' => $progressionByCategory,
            'fitness' => $this->fitness($vdotEstimator, $thresholdEstimator, $trainingPaceCalculator, $user),
            'timeInZone' => $timeInZoneSummary->forUser($user, $today) ?: null,
            'season' => $seasonStreakBuilder->seasonPayload($user, $season, $today),
            'seasonWeeks' => $season === null ? null : $seasonSummaryBuilder->build($user, $season, $today),
        ]);
    }

    /**
     * @return array{vdot: float|null, threshold_pace_sec: float|null, threshold_confidence: string|null, training_paces: array{easy: int, marathon: int, threshold: int, interval: int}|null}|null
     */
    private function fitness(VdotEstimator $vdotEstimator, EstimateThresholdAction $thresholdEstimator, TrainingPaceCalculator $trainingPaceCalculator, User $user): ?array
    {
        $vdot = $vdotEstimator->estimate($user);
        $threshold = $thresholdEstimator($user);

        if ($vdot === null && $threshold === null) {
            return null;
        }

        return [
            'vdot' => $vdot['vdot'] ?? null,
            'threshold_pace_sec' => $threshold['pace_sec'] ?? null,
            'threshold_confidence' => $threshold['confidence'] ?? null,
            'training_paces' => $trainingPaceCalculator->fromVdotResult($vdot),
        ];
    }

    /**
     * @return array{id: int|null, status: string, content: string|null, type: string, subject_type: string, subject_id: int, discriminator: string|null}
     */
    private function resolveProfileVoice(User $user): array
    {
        // Cache the voice per ISO week — the mood mix behind it doesn't shift by
        // the hour, and the narrator pulls 12 weeks of history regardless.
        $discriminator = AnalysisType::currentIsoWeek();
        $subjectType = AnalysisType::PROFILE_VOICE_SUBJECT_TYPE;

        $row = Analysis::query()
            ->forSubject($subjectType, $user->id, AnalysisType::ProfileVoice, $discriminator)
            ->first();

        return Analysis::toPayload($row, AnalysisType::ProfileVoice, $subjectType, $user->id, $discriminator);
    }

    /**
     * @param  Collection<int, PersonalRecord>  $records
     * @return array<string, array{category:string, weeks:array<int,string>, times_sec:array<int,int>, goal_sec:int|null}>
     */
    private function buildProgressionByCategory(ProgressionSeriesBuilder $builder, User $user, Collection $records): array
    {
        $prs = [];
        foreach (self::PROGRESSION_CATEGORIES as $category) {
            $pr = $records->first(fn (PersonalRecord $record): bool => $record->category === $category);
            if ($pr !== null) {
                $prs[] = $pr;
            }
        }

        $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();

        return $builder->buildMany($user, $prs, fn (PersonalRecord $pr): ?int => $this->raceGoalSecFor($race, $pr));
    }

    /**
     * The goal line is the user's active race, and only on the one distance
     * that race is run at. The band matches ProgressionSeriesBuilder's own
     * tolerance, so a 21.1 km race lines up with the half-marathon series.
     */
    private function raceGoalSecFor(?RaceGoal $race, PersonalRecord $pr): ?int
    {
        $target = $pr->category->distanceMeters();
        if ($race === null || $target === null) {
            return null;
        }

        return abs($race->distance_m - $target) <= $target * self::RACE_DISTANCE_TOLERANCE
            ? $race->goal_time_sec
            : null;
    }
}
