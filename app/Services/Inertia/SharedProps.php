<?php

declare(strict_types=1);

namespace App\Services\Inertia;

use App\Models\Activity;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\Gamification\EquippedAccessories;
use App\Services\Gamification\GoalResolver;
use App\Services\Notifications\ChannelRouter;
use App\Services\Run\Story\CardPresenter;
use App\Support\SharedPropCacheKey;
use Illuminate\Http\Request;

/**
 * Builds the cross-cutting props merged into every Inertia response.
 *
 * These used to live on `HandleInertiaRequests` itself, which left an HTTP
 * middleware owning eight domain prop builders and service-locating five
 * collaborators through `app()`. The middleware is now wiring only; this is
 * where the shared-prop policy lives, and it is injectable, so a test can drive
 * it without a request cycle.
 *
 * Every prop but `auth` and the two config flags is a closure, so Inertia skips
 * the work entirely on a partial reload that did not ask for that key.
 */
final readonly class SharedProps
{
    public function __construct(
        private EquippedAccessories $equippedAccessories,
        private ChannelRouter $channels,
        private GoalResolver $goals,
        private AnalysisService $analyses,
        private CardPresenter $cards,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forRequest(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->firstName(),
                    'avatar_url' => $user->avatar_url ?? null,
                    'is_demo' => (bool) $user->is_demo,
                ],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
                'unlock' => fn () => $request->session()->get('unlock'),
            ],
            'demoLoginEnabled' => (bool) config('demo.login_enabled'),
            // Public VAPID key only — the client needs it to subscribe; the private
            // key never leaves the server.
            'webPushPublicKey' => (string) config('webpush.vapid.public_key'),
            'equippedAccessories' => fn (): array => $this->equippedAccessoriesFor($user),
            'pendingReveal' => fn () => $this->pendingRevealFor($user),
            'stravaSync' => fn () => $this->stravaSyncFor($user),
            'goalsSummary' => fn () => $this->goalsSummaryFor($user),
            'hrZonesChangedAt' => fn () => $this->hrZonesChangedAtFor($user),
            'telegramConnected' => fn (): bool => $this->telegramConnectedFor($user),
            'webPushSubscribed' => fn (): bool => $this->webPushSubscribedFor($user),
            'stravaZoneScopeMissing' => fn (): bool => $this->stravaZoneScopeMissingFor($user),
            'aiPaused' => fn (): bool => $this->aiPausedFor($user),
        ];
    }

    /**
     * Which accessories the mascot is wearing. Cached because it costs a
     * `user_unlocks` scan on every page load while only ever moving when the
     * user equips something ({@see \App\Http\Controllers\AksesoriController}
     * busts it there). Granting an unlock cannot change it: rows are inserted
     * without `equipped`, which defaults to false.
     *
     * @return array<string, string|null>
     */
    private function equippedAccessoriesFor(?User $user): array
    {
        if ($user === null) {
            return $this->equippedAccessories->forUser(null);
        }

        return SharedPropCacheKey::EquippedAccessories->remember(
            $user->id,
            fn (): array => $this->equippedAccessories->forUser($user),
        );
    }

    /**
     * Whether LLM narration is globally paused right now (cost ceiling, kill
     * switch, unconfigured Azure, or a tripped config circuit), so the UI can show
     * a soft "Temari lagi istirahat" banner instead of scattered silent empty
     * states. Only the pause *fact* is shared, never the operator-facing reason
     * (that stays maintainer-only via the Telegram alert + /ai-usage). Cached
     * globally for a short window since it fires on every page load; guests never
     * see it, so the check is skipped for them.
     */
    private function aiPausedFor(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return SharedPropCacheKey::AiPaused->remember(
            null,
            fn (): bool => $this->analyses->generationPaused(),
        );
    }

    /**
     * True when the auth user has a live (non-revoked) Strava connection whose
     * granted scopes lack `profile:read_all` — the zone-sync gate needs that
     * scope, so this drives the reconnect banner rather than provoking a 403.
     * The demo user never syncs zones from Strava, so it is never nudged.
     */
    private function stravaZoneScopeMissingFor(?User $user): bool
    {
        if ($user === null || $user->is_demo) {
            return false;
        }

        return SharedPropCacheKey::StravaZoneScopeMissing->remember(
            $user->id,
            function () use ($user): bool {
                $connection = $user->stravaConnection;

                if ($connection === null || $connection->isRevoked()) {
                    return false;
                }

                return ! $connection->hasZoneScope();
            },
        );
    }

    /**
     * Whether a "Kirim notifikasi" affordance can actually deliver over Telegram.
     *
     * This means wired **and** un-muted, not merely connected. A muted channel
     * would otherwise leave the button looking live while the send silently goes
     * nowhere — worse than the disabled state, which at least points at
     * Pengaturan.
     */
    private function telegramConnectedFor(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return SharedPropCacheKey::TelegramConnected->remember(
            $user->id,
            fn (): bool => $this->channels->telegramReachable($user),
        );
    }

    /**
     * Same for web push. Paired with {@see self::telegramConnectedFor()} so the
     * UI can enable the manual send whenever *any* channel can deliver.
     */
    private function webPushSubscribedFor(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return SharedPropCacheKey::WebPushSubscribed->remember(
            $user->id,
            fn (): bool => $this->channels->pushReachable($user),
        );
    }

    /**
     * ISO-8601 timestamp of the auth user's last heart-rate-zone change, or null
     * when there is no runner profile or it has never changed. The front end
     * compares this against each analysis block's `generated_at` to flag blocks
     * computed with stale zones.
     */
    private function hrZonesChangedAtFor(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        // Wrap in an array so a null marker (the common no-custom-profile case)
        // is still cached; Cache::remember treats a bare null as a miss and
        // would re-query every request.
        return SharedPropCacheKey::HrZonesChangedAt->remember(
            $user->id,
            fn (): array => ['at' => $user->runnerProfile?->hr_zones_changed_at?->toIso8601String()],
        )['at'];
    }

    /**
     * @return array{state: string, last_synced_at: string|null}
     */
    private function stravaSyncFor(?User $user): array
    {
        if ($user === null) {
            return ['state' => 'disconnected', 'last_synced_at' => null];
        }

        return SharedPropCacheKey::StravaSync->remember(
            $user->id,
            fn (): array => $this->computeStravaSyncFor($user),
        );
    }

    /**
     * @return array{total: int, completed: int, closest: list<array{id: string, title: string, current: int|float, target: int|float, unit: string}>}|null
     */
    private function goalsSummaryFor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return SharedPropCacheKey::GoalsSummary->remember(
            $user->id,
            fn (): array => $this->computeGoalsSummary($user),
        );
    }

    /**
     * @return array{total: int, completed: int, closest: list<array{id: string, title: string, current: int|float, target: int|float, unit: string}>}
     */
    private function computeGoalsSummary(User $user): array
    {
        $goals = $this->goals->forUser($user);
        $completed = $this->goals->completedCount($goals);
        $closest = $this->goals->closestToCompletion($user, 3, $goals);

        return [
            'total' => count($goals),
            'completed' => $completed,
            'closest' => array_map(fn (array $g): array => [
                'id' => $g['id'],
                'title' => $g['title'],
                'current' => $g['current'],
                'target' => $g['target'],
                'unit' => $g['unit'],
            ], $closest),
        ];
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

        // "Last synced" reflects the most recent Strava pull, so it counts
        // stubs too (a just-synced run that has not been ingested yet still
        // means we synced). withStubs() opts out of the analyzed-only scope.
        $latest = Activity::query()
            ->withStubs()
            ->where('user_id', $user->id)
            ->whereNotNull('fetched_at')
            ->orderByDesc('fetched_at')
            ->value('fetched_at');

        // "ready" once at least one run is fully ingested; the scope already
        // restricts to analyzed rows, so exists() answers that directly.
        $hasAnalyzed = Activity::query()
            ->where('user_id', $user->id)
            ->exists();

        return [
            'state' => $hasAnalyzed ? 'ready' : 'syncing',
            'last_synced_at' => $latest?->toIso8601String(),
        ];
    }

    /**
     * @return array{card_id: int, activity_id: int, rarity: string, special_move: string, mood: string, badges: array<int, string>|null, detail_name: string|null, distance_m: float|null, moving_time_sec: int|null, trimp_edwards: float|null, average_heartrate: float|null, stream_summary: array<string, mixed>|null, summary_polyline: string|null, public_share_url: string, edition: array{index: int, total: int}}|null
     */
    private function pendingRevealFor(?User $user): ?array
    {
        if ($user === null || $user->pending_reveal_card_id === null) {
            return null;
        }

        $card = RunCard::query()
            ->whereKey($user->pending_reveal_card_id)
            ->with([
                'activity.detail:id,activity_id,name,distance,moving_time,trimp_edwards,average_heartrate,summary_polyline,stream_summary,weather_temp_c',
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

        return [
            'card_id' => $card->id,
            'activity_id' => $card->activity_id,
            'rarity' => $card->rarity->value,
            'special_move' => $card->special_move,
            'mood' => $this->cards->mood($card),
            'badges' => $badges,
            'detail_name' => $detail?->name,
            'distance_m' => $detail?->distance,
            'moving_time_sec' => $detail?->moving_time,
            'trimp_edwards' => $detail?->trimp_edwards,
            'average_heartrate' => $detail?->average_heartrate,
            'stream_summary' => $detail?->stream_summary,
            'summary_polyline' => $detail?->summary_polyline,
            'public_share_url' => route('aktivitas.show', ['activity' => $card->activity_id]),
            'edition' => $this->cards->edition($card, $user->id),
        ];
    }
}
