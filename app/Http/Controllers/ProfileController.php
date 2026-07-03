<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\UserUnlock;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\PersonaSummaryNarrator;
use App\Services\Telegram\TelegramLinkToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    private const int TOP_PR_COUNT = 3;

    public function __invoke(
        Request $request,
        PersonaSummaryNarrator $personaNarrator,
        TelegramLinkToken $telegramLinkToken,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $totalRuns = $user->activities()->count();

        $detailAggregates = ActivityDetail::query()
            ->whereHas(
                'activity',
                fn ($q) => $q->where('user_id', $user->id),
            )
            ->selectRaw('SUM(distance) AS total_distance, MAX(distance) AS longest_distance, MIN(start_date_local) AS first_run_at')
            ->first();

        $totalDistanceMeters = (float) ($detailAggregates?->getAttribute('total_distance') ?? 0);
        $longestRunMeters = (float) ($detailAggregates?->getAttribute('longest_distance') ?? 0);
        $firstRunAt = $detailAggregates?->getAttribute('first_run_at');

        $unlocks = UserUnlock::query()
            ->where('user_id', $user->id)
            ->orderBy('unlocked_at')
            ->get()
            ->map(fn (UserUnlock $row): array => [
                'unlock_key' => $row->unlock_key,
                'unlocked_at' => $row->unlocked_at->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Aku', [
            'identity' => [
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
                'first_run_at' => \is_string($firstRunAt) ? $firstRunAt : $firstRunAt?->toIso8601String(),
                'member_since' => $user->created_at?->toIso8601String(),
                'strava_connected' => $user->stravaConnection !== null,
            ],
            'stats' => [
                'total_runs' => $totalRuns,
                'total_km' => round($totalDistanceMeters / 1000, 1),
                'longest_run_km' => round($longestRunMeters / 1000, 2),
            ],
            'topPrs' => $this->topPrs($user),
            'unlocks' => $unlocks,
            'unlockCatalog' => config('temari_unlocks'),
            'personaMix' => $personaNarrator->personaMix($user),
            'personaSummary' => $this->resolvePersonaSummary($user),
            'profileVoice' => $this->resolveProfileVoice($user),
            'telegram' => $this->resolveTelegram($user, $telegramLinkToken),
        ]);
    }

    /**
     * @return array{connected: bool, username: string|null, connect_url: string|null, notify_post_run: bool, notify_weekly_recap: bool, notify_monthly_recap: bool}
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
            return [
                'connected' => false,
                'username' => null,
                'connect_url' => $connectUrl,
                'notify_post_run' => true,
                'notify_weekly_recap' => true,
                'notify_monthly_recap' => true,
            ];
        }

        $connected = ! $connection->isRevoked();

        return [
            'connected' => $connected,
            'username' => $connected ? $connection->username : null,
            'connect_url' => $connectUrl,
            'notify_post_run' => $connection->notify_post_run,
            'notify_weekly_recap' => $connection->notify_weekly_recap,
            'notify_monthly_recap' => $connection->notify_monthly_recap,
        ];
    }

    /**
     * @return array{id: int|null, status: string, content: string|null, type: string, subject_type: string, subject_id: int, discriminator: string|null}
     */
    private function resolvePersonaSummary(User $user): array
    {
        // Cache the persona summary per ISO week — moods don't shift by the
        // hour, and the narrator pulls 12 weeks of history regardless.
        $discriminator = Carbon::now()->isoFormat('GGGG-[W]WW');
        $subjectType = AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE;

        $row = Analysis::query()
            ->forSubject($subjectType, $user->id, AnalysisType::PersonaSummary, $discriminator)
            ->first();

        return Analysis::toPayload($row, AnalysisType::PersonaSummary, $subjectType, $user->id, $discriminator);
    }

    /**
     * @return array{id: int|null, status: string, content: string|null, type: string, subject_type: string, subject_id: int, discriminator: string|null}
     */
    private function resolveProfileVoice(User $user): array
    {
        $subjectType = AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE;

        $row = Analysis::query()
            ->forSubject($subjectType, $user->id, AnalysisType::AkuProfileVoice)
            ->first();

        return Analysis::toPayload($row, AnalysisType::AkuProfileVoice, $subjectType, $user->id);
    }

    /**
     * @return list<array{id: int, category: string, value_sec: int, set_at: string, activity_id: int|null, activity_name: string|null}>
     */
    private function topPrs(User $user): array
    {
        $rows = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->with(['activity:id', 'activity.detail:id,activity_id,name'])
            ->orderByDesc('set_at')
            ->limit(self::TOP_PR_COUNT)
            ->get();

        $out = [];
        foreach ($rows as $pr) {
            $out[] = [
                'id' => $pr->id,
                'category' => $pr->category->value,
                'value_sec' => (int) $pr->value_sec,
                'set_at' => $pr->set_at->format('Y-m-d\TH:i:s'),
                'activity_id' => $pr->activity_id,
                'activity_name' => $pr->activity?->detail?->name,
            ];
        }

        return $out;
    }
}
