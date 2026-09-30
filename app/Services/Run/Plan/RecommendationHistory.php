<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\ActivityDetail;
use App\Models\RecommendationRevision;
use App\Models\RecommendationView;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

final class RecommendationHistory
{
    public const int POLICY_VERSION = 1;

    /** @param array<string, mixed> $original
     * @param array<string, mixed> $effective
     */
    public function record(int $userId, string $date, array $original, array $effective, int $policyVersion = self::POLICY_VERSION): RecommendationRevision
    {
        return RecommendationRevision::query()->firstOrCreate([
            'user_id' => $userId,
            'date' => $date,
            'fingerprint' => hash('sha256', json_encode([$policyVersion, $original, $effective], JSON_THROW_ON_ERROR)),
        ], [
            'policy_version' => $policyVersion,
            'original' => $original,
            'effective' => $effective,
            'created_at' => Carbon::now('UTC'),
        ]);
    }

    /** @param array<string, mixed> $original
     * @param array<string, mixed> $effective
     */
    public function token(int $userId, string $date, array $original, array $effective): string
    {
        return Crypt::encryptString(json_encode(['user_id' => $userId, 'date' => $date, 'policy_version' => self::POLICY_VERSION, 'original' => $original, 'effective' => $effective], JSON_THROW_ON_ERROR));
    }

    public function shown(RecommendationRevision $revision, string $observationId): RecommendationView
    {
        $view = RecommendationView::query()->firstOrCreate([
            'observation_id' => $observationId,
        ], ['recommendation_revision_id' => $revision->id, 'shown_at' => Carbon::now('UTC')]);
        abort_unless($view->recommendation_revision_id === $revision->id, 409);

        return $view;
    }

    public function beforeRun(int $userId, ActivityDetail $detail): ?RecommendationRevision
    {
        return $this->beforeRuns($userId, [$detail])[$detail->id] ?? null;
    }

    /** @param list<ActivityDetail> $details
     * @return array<int, RecommendationRevision>
     */
    public function beforeRuns(int $userId, array $details): array
    {
        $known = [];
        foreach ($details as $detail) {
            if ($detail->start_date_utc !== null && $detail->start_date_local !== null) {
                $known[] = ['detail' => $detail, 'date' => $detail->start_date_local->toDateString(), 'start' => $detail->start_date_utc->toDateTimeString()];
            }
        }
        if ($known === []) {
            return [];
        }

        $views = RecommendationRevision::query()
            ->join('recommendation_views', 'recommendation_views.recommendation_revision_id', '=', 'recommendation_revisions.id')
            ->where('recommendation_revisions.user_id', $userId)
            ->whereIn('recommendation_revisions.date', array_unique(array_column($known, 'date')))
            ->orderByDesc('recommendation_views.shown_at')
            ->orderByDesc('recommendation_views.id')
            ->get(['recommendation_revisions.*', 'recommendation_views.shown_at'])
            ->groupBy(static fn (RecommendationRevision $revision): string => $revision->date->toDateString());
        $result = [];
        foreach ($known as $item) {
            $revision = $views->get($item['date'])?->first(
                static fn (RecommendationRevision $revision): bool => $revision->getRawOriginal('shown_at') < $item['start'],
            );
            if ($revision !== null) {
                $result[$item['detail']->id] = $revision;
            }
        }

        return $result;
    }
}
