<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PerformanceEvidenceKind;
use App\Enums\PlannedSessionStatus;
use App\Models\PerformanceEvidence;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PerformanceEvidenceController extends Controller
{
    private const float MIN_PLAUSIBLE_VDOT = 15.0;

    private const float MAX_PLAUSIBLE_VDOT = 90.0;

    public function __construct(
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $trainingPaceCalculator,
        private readonly Periodizer $periodizer,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validate([
            'kind' => ['required', Rule::enum(PerformanceEvidenceKind::class)],
            'distance_m' => ['required', 'integer', 'between:1000,42195'],
            'elapsed_time_sec' => ['required', 'integer', 'between:60,604800'],
            'performed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'activity_id' => ['nullable', 'integer', Rule::exists('activities', 'id')->where('user_id', $user->id)],
            'race_goal_id' => ['nullable', 'integer', Rule::exists('race_goals', 'id')->where('user_id', $user->id)],
        ]);

        $performanceVdot = $this->vdotEstimator->vdotFromTimeAndDistance($attributes['elapsed_time_sec'], $attributes['distance_m']);
        if ($performanceVdot === null || $performanceVdot < self::MIN_PLAUSIBLE_VDOT || $performanceVdot > self::MAX_PLAUSIBLE_VDOT) {
            throw ValidationException::withMessages(['elapsed_time_sec' => 'This time is not plausible for the distance.']);
        }

        $before = $this->vdotEstimator->estimate($user);
        $evidenceAttributes = [...$attributes, 'user_id' => $user->id, 'confirmed_at' => now()];
        $activityId = $attributes['activity_id'] ?? null;
        $evidence = $activityId === null
            ? PerformanceEvidence::query()->create($evidenceAttributes)
            : PerformanceEvidence::query()->firstOrCreate(
                ['user_id' => $user->id, 'activity_id' => $activityId],
                $evidenceAttributes,
            );
        $this->vdotEstimator->forget($user);
        $after = $this->vdotEstimator->estimate($user);
        $paces = $this->trainingPaceCalculator->fromVdotResult($after);
        $planUpdated = false;

        if ($evidence->wasRecentlyCreated && self::pacesChanged($this->trainingPaceCalculator->fromVdotResult($before), $paces)) {
            $hasPlannedSessions = PlannedSession::query()->where('user_id', $user->id)
                ->where('status', PlannedSessionStatus::Planned)
                ->where('date', '>=', Carbon::today()->toDateString())
                ->exists();

            if ($hasPlannedSessions) {
                try {
                    $this->periodizer->regenerate($user, Carbon::today(), Periodizer::REQUEST_LOCK_WAIT_SECONDS);
                    $planUpdated = true;
                } catch (LockTimeoutException) {
                    $planUpdated = false;
                }
            }
        }

        return response()->json([
            'id' => $evidence->id,
            'fitness' => $after === null ? null : [
                'vdot' => $after['vdot'],
                'quality_vdot' => $after['quality_vdot'],
                'vdot_source' => [
                    'category' => $after['source_category'],
                    'set_at' => $after['set_at']->toDateString(),
                    'stale' => $after['stale'],
                    'confidence' => $after['confidence'],
                    'evidence_id' => $after['evidence_id'],
                    'evidence_kind' => $after['evidence_kind'] ?? null,
                    'distance_m' => $after['distance_m'] ?? null,
                    'corroborating_quality_count' => $after['corroborating_quality_count'],
                    'quality_category' => $after['quality_source']['source_category'] ?? null,
                    'quality_set_at' => isset($after['quality_source'])
                        ? $after['quality_source']['set_at']->toDateString()
                        : null,
                    'quality_evidence_kind' => $after['quality_source']['evidence_kind'] ?? null,
                    'quality_distance_m' => $after['quality_source']['distance_m'] ?? null,
                ],
                'training_paces' => $paces,
            ],
            'plan_updated' => $planUpdated,
        ], $evidence->wasRecentlyCreated ? 201 : 200);
    }

    /** @param array{easy: int, marathon: int, threshold: int, interval: int}|null $before
     * @param array{easy: int, marathon: int, threshold: int, interval: int}|null $after
     */
    private static function pacesChanged(?array $before, ?array $after): bool
    {
        if ($before === null || $after === null) {
            return $before !== $after;
        }

        return array_any(
            ['easy', 'marathon', 'threshold', 'interval'],
            static fn ($pace): bool => abs($before[$pace] - $after[$pace]) >= 5,
        );
    }
}
