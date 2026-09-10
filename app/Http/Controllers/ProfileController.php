<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\User;
use App\Actions\Run\Metrics\EstimateThresholdAction;
use App\Services\Gamification\SeasonStreakSummaryBuilder;
use App\Services\Run\LifetimeStats;
use App\Services\Run\Metrics\TimeInZoneSummary;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\SeasonService;
use App\Services\Run\Plan\SeasonSummaryBuilder;
use App\Services\Run\Plan\WeekSessionTypesBuilder;
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
        ResolveActiveRaceAction $activeRace,
        WeekSessionTypesBuilder $weekSessionTypes,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();

        // Two requests serve this page: the initial render, then Inertia's
        // partial for the deferred props, which runs the whole action again.
        // Every prop is a closure so the partial resolves only what it asked
        // for, and the two that share the lifetime totals memoize them.
        $lifetime = null;
        $loadLifetime = function () use (&$lifetime, $lifetimeStats, $user): array {
            if ($lifetime === null) {
                $lifetime = $lifetimeStats->forUser($user);
            }

            return $lifetime;
        };

        // peekCurrent, never ensureCurrent: opening Profile must not create a
        // season or fire the grant side effects a Plan page load does.
        $season = null;
        $seasonResolved = false;
        $loadSeason = function () use (&$season, &$seasonResolved, $seasonService, $user, $today): ?Season {
            if (! $seasonResolved) {
                $season = $seasonService->peekCurrent($user, $today);
                $seasonResolved = true;
            }

            return $season;
        };

        return Inertia::render('Profile', [
            'identity' => fn (): array => [
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
                'first_run_at' => $loadLifetime()['first_run_at'],
                'member_since' => $user->created_at?->toIso8601String(),
                'strava_connected' => $user->stravaConnection !== null,
            ],
            'stats' => fn (): array => [
                'total_runs' => $loadLifetime()['total_runs'],
                'total_km' => $loadLifetime()['total_km'],
                'longest_run_km' => $loadLifetime()['longest_km'],
            ],
            'profileVoice' => fn (): array => $this->resolveProfileVoice($user),
            'progressionByCategory' => Inertia::defer(fn (): array => $this->buildProgressionByCategory($progressionSeriesBuilder, $user, $this->personalRecords($user), $activeRace($user->id))),
            'fitness' => Inertia::defer(fn (): ?array => $this->fitness($vdotEstimator, $thresholdEstimator, $trainingPaceCalculator, $weekSessionTypes, $user, $today, $activeRace)),
            'timeInZone' => Inertia::defer(fn (): ?array => $timeInZoneSummary->forUser($user, $today) ?: null),
            'season' => Inertia::defer(fn (): ?array => $seasonStreakBuilder->seasonPayload($user, $loadSeason(), $today)),
            'seasonWeeks' => Inertia::defer(function () use ($loadSeason, $seasonSummaryBuilder, $user, $today): ?array {
                $season = $loadSeason();

                return $season === null ? null : $seasonSummaryBuilder->build($user, $season, $today);
            }),
        ]);
    }

    /**
     * @return Collection<int, PersonalRecord>
     */
    private function personalRecords(User $user): Collection
    {
        return PersonalRecord::query()
            ->where('user_id', $user->id)
            ->orderBy('category')
            ->get();
    }

    /**
     * @return array{vdot: float|null, vdot_source: array{category: string, set_at: string, stale: bool, quality_category: string|null, quality_set_at: string|null}|null, threshold_pace_sec: float|null, threshold_confidence: string|null, training_paces: array{easy: int, marathon: int, threshold: int, interval: int}|null, week_sessions: list<array{weekday: string, session_type: string, distance_km: float, is_today: bool}>}|null
     */
    private function fitness(VdotEstimator $vdotEstimator, EstimateThresholdAction $thresholdEstimator, TrainingPaceCalculator $trainingPaceCalculator, WeekSessionTypesBuilder $weekSessionTypes, User $user, Carbon $today, ResolveActiveRaceAction $activeRace): ?array
    {
        $vdot = $vdotEstimator->estimate($user);
        $threshold = $thresholdEstimator($user);

        if ($vdot === null && $threshold === null) {
            return null;
        }

        $paces = $trainingPaceCalculator->fromVdotResult($vdot);
        $race = $activeRace($user->id);
        $raceDistanceM = $race !== null ? (float) $race->distance_m : null;

        return [
            'vdot' => $vdot['vdot'] ?? null,
            'vdot_source' => $vdot === null ? null : [
                'category' => $vdot['source_category'],
                'set_at' => $vdot['set_at']->toDateString(),
                'stale' => $vdot['stale'],
                'quality_category' => $vdot['quality_source']['source_category'] ?? null,
                'quality_set_at' => isset($vdot['quality_source'])
                    ? $vdot['quality_source']['set_at']->toDateString()
                    : null,
            ],
            'threshold_pace_sec' => $threshold['pace_sec'] ?? null,
            'threshold_confidence' => $threshold['confidence'] ?? null,
            'training_paces' => $paces,
            'week_sessions' => $weekSessionTypes->forUser($user, $today, $paces, $raceDistanceM),
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
    private function buildProgressionByCategory(ProgressionSeriesBuilder $builder, User $user, Collection $records, ?RaceGoal $race): array
    {
        $prs = [];
        foreach (self::PROGRESSION_CATEGORIES as $category) {
            $pr = $records->first(fn (PersonalRecord $record): bool => $record->category === $category);
            if ($pr !== null) {
                $prs[] = $pr;
            }
        }

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
