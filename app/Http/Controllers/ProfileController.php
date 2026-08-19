<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Actions\Run\Metrics\EstimateThresholdAction;
use App\Services\Gamification\SeasonStreakSummaryBuilder;
use App\Services\Run\LifetimeStats;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\SeasonService;
use App\Services\Run\ProgressionSeriesBuilder;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\AkuProfileVoiceNarrator;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use App\Enums\PrCategory;
use Illuminate\Support\Carbon;
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

    public function __invoke(
        Request $request,
        AkuProfileVoiceNarrator $profileVoiceNarrator,
        ProgressionSeriesBuilder $progressionSeriesBuilder,
        LifetimeStats $lifetimeStats,
        VdotEstimator $vdotEstimator,
        EstimateThresholdAction $thresholdEstimator,
        TrainingPaceCalculator $trainingPaceCalculator,
        SeasonService $seasonService,
        SeasonStreakSummaryBuilder $seasonStreakBuilder,
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
            'personaMix' => $profileVoiceNarrator->personaMix($user),
            'profileVoice' => $this->resolveProfileVoice($user),
            'progressionByCategory' => $progressionByCategory,
            'fitness' => $this->fitness($vdotEstimator, $thresholdEstimator, $trainingPaceCalculator, $user),
            'seasonStreak' => [
                'season' => $seasonStreakBuilder->seasonPayload($user, $season, $today),
                'streak' => $seasonStreakBuilder->streakPayload($user, $today),
            ],
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
        $subjectType = AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE;

        $row = Analysis::query()
            ->forSubject($subjectType, $user->id, AnalysisType::AkuProfileVoice, $discriminator)
            ->first();

        return Analysis::toPayload($row, AnalysisType::AkuProfileVoice, $subjectType, $user->id, $discriminator);
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

        return $builder->buildMany($user, $prs, fn (PersonalRecord $pr): ?int => null);
    }
}
