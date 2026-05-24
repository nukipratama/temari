<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Override;

class HandleInertiaRequests extends Middleware
{
    private const array IN_FLIGHT_STATUSES = [
        AnalysisStatus::Pending,
        AnalysisStatus::Queued,
        AnalysisStatus::Processing,
    ];

    private const array USER_DAY_SUBJECT_TYPES = [
        AnalysisType::BRIEFING_SUBJECT_TYPE,
        AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
        AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
    ];

    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->firstName(),
                    'avatar_url' => $user->avatar_url ?? null,
                ],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
            ],
            'demoLoginEnabled' => (bool) config('demo.login_enabled'),
            'onboarding' => [
                'forceShow' => (bool) config('onboarding.force_show'),
            ],
            'unlockedAccessories' => fn () => $user === null
                ? []
                : UserUnlock::query()->where('user_id', $user->id)->pluck('unlock_key')->all(),
            'aiActivity' => fn () => $this->aiActivityCounts($user),
            'pendingReveal' => fn () => $this->pendingRevealFor($user),
            'stravaSync' => fn () => $this->stravaSyncFor($user),
        ];
    }

    /**
     * @return array{connected: bool, last_synced_at: string|null}
     */
    private function stravaSyncFor(?User $user): array
    {
        if ($user === null) {
            return ['connected' => false, 'last_synced_at' => null];
        }

        $connected = $user->stravaConnection !== null;
        if (! $connected) {
            return ['connected' => false, 'last_synced_at' => null];
        }

        // Use the most-recent activity ingest timestamp as the human-facing
        // "last synced" marker. Strava connection itself doesn't store a
        // sync timestamp; we tag every activity via fetched_at.
        $latest = Activity::query()
            ->where('user_id', $user->id)
            ->whereNotNull('fetched_at')
            ->orderByDesc('fetched_at')
            ->value('fetched_at');

        return [
            'connected' => true,
            'last_synced_at' => $latest?->toIso8601String(),
        ];
    }

    /**
     * @return array{card_id: int, activity_id: int, rarity: string, special_move: string, badges: array<int, string>|null, detail_name: string|null, distance_m: float|null, moving_time_sec: int|null, trimp_edwards: float|null}|null
     */
    private function pendingRevealFor(?User $user): ?array
    {
        if ($user === null || $user->pending_reveal_card_id === null) {
            return null;
        }

        $card = RunCard::query()
            ->whereKey($user->pending_reveal_card_id)
            ->with([
                'activity.detail:id,activity_id,name,distance,moving_time,trimp_edwards',
                'activity:id,user_id',
            ])
            ->first();

        if ($card === null || $card->activity->user_id !== $user->id) {
            return null;
        }

        /** @var array<int, string>|null $badges */
        $badges = $card->badges;

        $detail = $card->activity->detail;

        return [
            'card_id' => $card->id,
            'activity_id' => $card->activity_id,
            'rarity' => $card->rarity->value,
            'special_move' => $card->special_move,
            'badges' => $badges,
            'detail_name' => $detail?->name,
            'distance_m' => $detail?->distance,
            'moving_time_sec' => $detail?->moving_time,
            'trimp_edwards' => $detail?->trimp_edwards,
        ];
    }

    /**
     * @return array{pending: int, queued: int, processing: int}
     */
    private function aiActivityCounts(?User $user): array
    {
        if ($user === null) {
            return ['pending' => 0, 'queued' => 0, 'processing' => 0];
        }

        $rows = Analysis::query()
            ->whereIn('status', self::IN_FLIGHT_STATUSES)
            ->where(fn (Builder $q) => $this->scopeToUser($q, $user))
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'pending' => (int) ($rows[AnalysisStatus::Pending->value] ?? 0),
            'queued' => (int) ($rows[AnalysisStatus::Queued->value] ?? 0),
            'processing' => (int) ($rows[AnalysisStatus::Processing->value] ?? 0),
        ];
    }

    /**
     * @param  Builder<Analysis>  $query
     */
    private function scopeToUser(Builder $query, User $user): void
    {
        $userOwnedIds = [
            Activity::class => Activity::query()->where('user_id', $user->id)->select('id'),
            PersonalRecord::class => PersonalRecord::query()->where('user_id', $user->id)->select('id'),
            WeeklySnapshot::class => WeeklySnapshot::query()->where('user_id', $user->id)->select('id'),
            RunCard::class => RunCard::query()
                ->whereHas('activity', fn ($a) => $a->where('user_id', $user->id))
                ->select('id'),
        ];

        $query->where(fn (Builder $q) => $q
            ->whereIn('subject_type', self::USER_DAY_SUBJECT_TYPES)
            ->where('subject_id', $user->id));

        foreach ($userOwnedIds as $subjectType => $idQuery) {
            $query->orWhere(fn (Builder $q) => $q
                ->where('subject_type', $subjectType)
                ->whereIn('subject_id', $idQuery));
        }
    }
}
