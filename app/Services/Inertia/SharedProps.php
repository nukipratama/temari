<?php

declare(strict_types=1);

namespace App\Services\Inertia;

use App\Models\User;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Http\Request;

/**
 * Builds the cross-cutting props merged into every Inertia response. The
 * `HandleInertiaRequests` middleware is wiring only; this is where the
 * shared-prop policy lives, and it is injectable, so a test can drive it
 * without a request cycle.
 *
 * The domain families each own their own builder ({@see GamificationProps},
 * {@see StravaProps}, {@see NotificationProps}, {@see AiProps}); what is left
 * here is the request-shaped remainder — identity, flashes, and two config
 * flags — plus the composition.
 *
 * Every prop but `auth` and the two config flags is a closure, so Inertia skips
 * the work entirely on a partial reload that did not ask for that key.
 */
final readonly class SharedProps
{
    public function __construct(
        private GamificationProps $gamification,
        private StravaProps $strava,
        private NotificationProps $notifications,
        private AiProps $ai,
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
            ],
            // Reads the flag EnforceMaintenanceMode already loaded this request.
            'demoLoginEnabled' => fn (): bool => (bool) config('demo.login_enabled')
                && ! app(AppConfig::class)->boolean(AppConfigKey::MaintenanceEnabled),
            // Public VAPID key only — the client needs it to subscribe; the private
            // key never leaves the server.
            'webPushPublicKey' => (string) config('webpush.vapid.public_key'),
            // '' when unconfigured; the run map falls back to plain OSM tiles rather
            // than a watermarked CARTO map.
            'cartoApiKey' => (string) config('services.carto.api_key'),
            'planRecalibration' => fn (): array => $this->planRecalibrationFor($user),
            ...$this->gamification->forUser($user),
            ...$this->strava->forUser($user),
            ...$this->notifications->forUser($user),
            ...$this->ai->forUser($user),
        ];
    }

    /**
     * @return array{pending: bool, started_at: string|null, completed_at: string|null}
     */
    private function planRecalibrationFor(?User $user): array
    {
        $started = $user?->plan_recalibration_started_at;
        $completed = $user?->plan_recalibration_completed_at;

        return [
            'pending' => $started !== null && $completed === null,
            'started_at' => $started?->toIso8601String(),
            'completed_at' => $completed?->toIso8601String(),
        ];
    }
}
