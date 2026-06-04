<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Services\Gamification\EquippedAccessories;
use App\Services\Run\Story\Temari;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Override;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Short TTL for the Strava-sync share. The two queries it runs fire on
     * every page load, while the "last synced" marker only moves when a sync
     * ingests a new activity (minutes apart at most), so a brief cache trades
     * a tiny staleness window for far fewer per-request queries.
     */
    private const int STRAVA_SYNC_CACHE_SECONDS = 120;

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
                'unlock' => fn () => $request->session()->get('unlock'),
            ],
            'demoLoginEnabled' => (bool) config('demo.login_enabled'),
            'equippedAccessories' => fn () => app(EquippedAccessories::class)->forUser($user),
            'pendingReveal' => fn () => $this->pendingRevealFor($user),
            'stravaSync' => fn () => $this->stravaSyncFor($user),
        ];
    }

    /**
     * @return array{state: string, last_synced_at: string|null}
     */
    private function stravaSyncFor(?User $user): array
    {
        if ($user === null) {
            return ['state' => 'disconnected', 'last_synced_at' => null];
        }

        return Cache::remember(
            "strava-sync:{$user->id}",
            self::STRAVA_SYNC_CACHE_SECONDS,
            fn (): array => $this->computeStravaSyncFor($user),
        );
    }

    /**
     * Resolve the honest connection state the UI branches on (the client derives
     * "connected" from this, so it is not shipped separately):
     *  - `disconnected`: no Strava connection at all.
     *  - `revoked`: connection exists but was revoked (token rejected / deauthorized).
     *  - `syncing`: connected, but no analyzed run has landed yet (backfill in flight).
     *  - `ready`: at least one analyzed run is on the dashboard.
     *
     * @return array{state: string, last_synced_at: string|null}
     */
    private function computeStravaSyncFor(User $user): array
    {
        $connection = $user->stravaConnection;
        if ($connection === null) {
            return ['state' => 'disconnected', 'last_synced_at' => null];
        }

        if ($connection->isRevoked()) {
            return ['state' => 'revoked', 'last_synced_at' => null];
        }

        // Use the most-recent activity ingest timestamp as the human-facing
        // "last synced" marker. Strava connection itself doesn't store a
        // sync timestamp; we tag every activity via fetched_at.
        $latest = Activity::query()
            ->where('user_id', $user->id)
            ->whereNotNull('fetched_at')
            ->orderByDesc('fetched_at')
            ->value('fetched_at');

        $hasAnalyzed = Activity::query()
            ->where('user_id', $user->id)
            ->whereNotNull('analyzed_at')
            ->exists();

        return [
            'state' => $hasAnalyzed ? 'ready' : 'syncing',
            'last_synced_at' => $latest?->toIso8601String(),
        ];
    }

    /**
     * @return array{card_id: int, activity_id: int, rarity: string, special_move: string, mood: string, badges: array<int, string>|null, detail_name: string|null, distance_m: float|null, moving_time_sec: int|null, trimp_edwards: float|null, summary_polyline: string|null, edition: array{index: int, total: int}, is_pr: bool, pr_category_label: string|null, pr_time_display: string|null, is_replay: bool}|null
     */
    private function pendingRevealFor(?User $user): ?array
    {
        if ($user === null || $user->pending_reveal_card_id === null) {
            return null;
        }

        // A re-watch (set by CardReplayController) flashes this marker so the
        // reveal can skip the PR celebration on replays.
        $isReplay = (bool) session('reveal_replay', false);

        $card = RunCard::query()
            ->whereKey($user->pending_reveal_card_id)
            ->with([
                'activity.detail:id,activity_id,name,distance,moving_time,trimp_edwards,average_heartrate,summary_polyline,stream_summary',
                'activity.postRunStoryLine',
                'activity:id,user_id',
            ])
            ->first();

        if ($card === null || $card->activity->user_id !== $user->id) {
            return null;
        }

        /** @var array<int, string>|null $badges */
        $badges = $card->badges;

        $detail = $card->activity->detail;

        $pr = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->where('activity_id', $card->activity_id)
            ->first();

        return [
            'card_id' => $card->id,
            'activity_id' => $card->activity_id,
            'rarity' => $card->rarity->value,
            'special_move' => $card->special_move,
            'mood' => $card->activity->postRunStoryLine->mood ?? Temari::MOOD_ADEM,
            'badges' => $badges,
            'detail_name' => $detail?->name,
            'distance_m' => $detail?->distance,
            'moving_time_sec' => $detail?->moving_time,
            'trimp_edwards' => $detail?->trimp_edwards,
            'average_heartrate' => $detail?->average_heartrate,
            'stream_summary' => $detail?->stream_summary,
            'summary_polyline' => $detail?->summary_polyline,
            'edition' => $this->editionFor($user, $card),
            'is_pr' => $pr !== null,
            'pr_category_label' => $pr?->category->label(),
            'pr_time_display' => $pr !== null ? $this->formatSeconds((int) $pr->value_sec) : null,
            'is_replay' => $isReplay,
        ];
    }

    /**
     * Collector number for a single card within its rarity, so the reveal shows
     * the same "#3/7" as the grid/detail pages. Two cheap counts, only paid when
     * a reveal is actually pending (the early return above gates this).
     *
     * @return array{index: int, total: int}
     */
    private function editionFor(User $user, RunCard $card): array
    {
        $sameRarity = RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->where('rarity', $card->rarity);

        return [
            'index' => (clone $sameRarity)->where('id', '<=', $card->id)->count(),
            'total' => $sameRarity->count(),
        ];
    }

    private function formatSeconds(int $totalSec): string
    {
        $h = intdiv($totalSec, 3600);
        $m = intdiv($totalSec % 3600, 60);
        $s = $totalSec % 60;

        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }

        return sprintf('%d:%02d', $m, $s);
    }

}
