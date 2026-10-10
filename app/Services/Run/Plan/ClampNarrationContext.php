<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Support\Carbon;

/**
 * Resolves the same clamp facts for the shared briefing side effects and the narration job.
 *
 * It deliberately reads the ceiling **fresh** rather than trusting what the
 * requester saw. The ceiling comes off {@see TrainingLoad}, which counts the
 * day's own runs, so it moves as the day goes on — and a clamp that has since
 * lifted should not be narrated at all. That is also why the row it backs is
 * fingerprinted coarsely; see {@see self::fingerprint()}.
 */
final readonly class ClampNarrationContext
{
    public function __construct(private TrainingLoad $trainingLoad)
    {
    }

    /**
     * @return array{ceiling: ReadinessCeiling, original: SessionType, clamped_to: SessionType, has_run_today: bool, readiness_reasons: list<string>, readiness_inputs: array<string, mixed>, decision_source: string}|null
     *                                                                                                                   null when the day has no session, or already fits under the ceiling
     */
    public function forUserOn(int $userId, Carbon $date): ?array
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return null;
        }

        $session = PlannedSession::query()
            ->where('user_id', $userId)
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($session === null) {
            return null;
        }

        $recordedAssessment = $session->readiness_assessment;
        $hasRecordedAdjustment = $session->rest_clamped_at !== null || $session->clamped_km !== null;
        if ($hasRecordedAdjustment && is_array($recordedAssessment) && is_string($recordedAssessment['ceiling'] ?? null)) {
            $assessment = $recordedAssessment;
            $decisionSource = 'recorded';
        } else {
            $assessment = BriefingContext::forUser($user, $date, $this->trainingLoad->summary($user, $date))->readinessAssessment;
            $decisionSource = 'live';
        }
        $ceiling = ReadinessCeiling::from($assessment['ceiling']);
        if ($ceiling === ReadinessCeiling::ModerateOk
            && ($session->session_type === SessionType::Race || IntensityPrescription::fromSession($session)?->isEasy() === false)) {
            return null;
        }

        $clampedTo = ReadinessClamp::downgradeFor($session->session_type, $ceiling);
        if ($clampedTo === null) {
            return null;
        }

        return [
            'ceiling' => $ceiling,
            'original' => $session->session_type,
            'clamped_to' => $clampedTo,
            'has_run_today' => Activity::query()
                ->where('user_id', $userId)
                ->whereHas('detail', fn ($q) => $q->whereDate('start_date_local', $date->toDateString()))
                ->exists(),
            'readiness_reasons' => $assessment['reasons'],
            'readiness_inputs' => $assessment['inputs'],
            'decision_source' => $decisionSource,
        ];
    }

    /**
     * What a clamp explanation actually speaks to, deliberately coarser than
     * the clamp itself. The ceiling is recomputed from {@see TrainingLoad}
     * on every ingest, so it drifts a little with each run logged; fingerprinting
     * the exact figures would re-bill this line several times on the one kind of
     * day it exists for. The band, the type it was downgraded to, and whether the
     * athlete has already run are the whole substance of the sentence — a
     * ceiling that slides within its own band changes nothing worth saying.
     *
     * @param  list<string>  $readinessReasons
     */
    public static function fingerprint(ReadinessCeiling $ceiling, SessionType $clampedTo, bool $hasRunToday, array $readinessReasons = []): string
    {
        $material = [
            'ceiling' => $ceiling->value,
            'clamped_to' => $clampedTo->value,
            'has_run_today' => $hasRunToday,
            ...($readinessReasons === [] ? [] : ['readiness_reasons' => $readinessReasons]),
        ];
        ksort($material);

        return hash('xxh128', (string) json_encode($material));
    }
}
