<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RunnerProfile;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Services\Telegram\TelegramLinkToken;
use App\Support\Cooldown;
use App\Support\DataUseStatement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __invoke(Request $request, TelegramLinkToken $telegramLinkToken): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Settings/Index', [
            'dataUse' => [
                'headline' => DataUseStatement::HEADLINE,
                'points' => DataUseStatement::points(),
            ],
            'telegram' => $this->resolveTelegram($user, $telegramLinkToken),
            'notificationPrefs' => $this->resolveNotificationPrefs($user),
            // Lets the test button render a countdown instead of failing on a
            // 429 the UI cannot explain.
            'testCooldownSeconds' => new Cooldown(
                Cooldown::testNotificationKey($user->id),
                Cooldown::TEST_WINDOW_SECONDS,
            )->remaining(),
            'hrZones' => $this->resolveHrZones($user),
            'trainingPreferences' => $this->resolveTrainingPreferences($user),
        ]);
    }

    /**
     * @return array{experience_level: string|null, sessions_per_week: int|null, goal_type: string|null, run_days: list<int>|null, long_run_day: int|null}
     */
    private function resolveTrainingPreferences(User $user): array
    {
        $preference = TrainingPreference::query()->where('user_id', $user->id)->first();

        return [
            'experience_level' => $preference?->experience_level?->value,
            'sessions_per_week' => $preference?->sessions_per_week,
            'goal_type' => $preference?->goal_type?->value,
            'run_days' => $preference?->run_days,
            'long_run_day' => $preference?->long_run_day,
        ];
    }

    /**
     * @return array{profile: array<string, mixed>, source: string, stravaSyncedLabel: string|null, canSyncFromStrava: bool}
     */
    private function resolveHrZones(User $user): array
    {
        $profile = RunnerProfile::query()->where('user_id', $user->id)->first();

        return [
            'profile' => $user->hrProfile(),
            'source' => $profile !== null ? $profile->source : 'default',
            'stravaSyncedLabel' => $profile !== null ? $profile->strava_zones_synced_at?->format('j M Y, H:i') : null,
            'canSyncFromStrava' => $this->canSyncFromStrava($user),
        ];
    }

    private function canSyncFromStrava(User $user): bool
    {
        $connection = $user->stravaConnection;

        return $connection !== null && ! $connection->isRevoked() && $connection->hasZoneScope();
    }

    /**
     * @return array{connected: bool, username: string|null, connect_url: string|null}
     */
    private function resolveTelegram(User $user, TelegramLinkToken $linkToken): array
    {
        $botUsername = (string) config('services.telegram.bot_username');
        // A fresh, signed deep-link token per render (60 min TTL). Null when the
        // bot username isn't configured, so the UI hides the connect button.
        $connectUrl = $botUsername !== ''
            ? "https://t.me/{$botUsername}?start=" . $linkToken->mint($user->id)
            : null;

        $connection = $user->telegramConnection;
        if ($connection === null) {
            return ['connected' => false, 'username' => null, 'connect_url' => $connectUrl];
        }

        $connected = ! $connection->isRevoked();

        return [
            'connected' => $connected,
            'username' => $connected ? $connection->username : null,
            'connect_url' => $connectUrl,
        ];
    }

    /**
     * Both preference axes: the channel-neutral master switch, and the
     * per-channel mutes. A missing row means all-on, so an untouched account
     * defaults to true everywhere.
     *
     * @return array{notifications_enabled: bool, telegram_enabled: bool, push_enabled: bool}
     */
    private function resolveNotificationPrefs(User $user): array
    {
        $preference = $user->notificationPreference;
        if ($preference === null) {
            return [
                'notifications_enabled' => true,
                'telegram_enabled' => true,
                'push_enabled' => true,
            ];
        }

        return [
            'notifications_enabled' => $preference->notifications_enabled,
            'telegram_enabled' => $preference->telegram_enabled,
            'push_enabled' => $preference->push_enabled,
        ];
    }
}
