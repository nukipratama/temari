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
 * Resolves the facts a clamp explanation is written from, for whoever asks:
 * the two sites that request the narration, and the job that later generates
 * it. Sharing one resolver is what keeps a row from being requested for a
 * clamp the job then cannot find.
 *
 * It deliberately reads the ceiling **fresh** rather than trusting what the
 * requester saw. The ceiling comes off {@see TrainingLoad}, which counts the
 * day's own runs, so it moves as the day goes on — and a clamp that has since
 * lifted should not be narrated at all. That is also why the row it backs is
 * fingerprinted coarsely; see {@see \App\Services\AI\MaterialFingerprint::forClamp()}.
 */
final readonly class ClampNarrationContext
{
    public function __construct(private TrainingLoad $trainingLoad)
    {
    }

    /**
     * @return array{ceiling: ReadinessCeiling, original: SessionType, clamped_to: SessionType, has_run_today: bool}|null
     *                                                                                                                   null when the day has no session, is pinned, or already fits under the ceiling
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

        // A pinned row is exempt from the clamp at render time, so there is
        // nothing to explain here either.
        if ($session === null || $session->pinned) {
            return null;
        }

        $ceiling = ReadinessCeiling::from(
            BriefingContext::forUser($user, $date, $this->trainingLoad->summary($user, $date))->readinessCeiling,
        );

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
        ];
    }
}
