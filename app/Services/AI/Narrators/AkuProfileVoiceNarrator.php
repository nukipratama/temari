<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\UserUnlock;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use Illuminate\Support\Carbon;

class AkuProfileVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 2-3 kalimat (maksimal 60 kata) Temari menyapa pengguna di halaman
        profil. Temari ngebaca ringkasan perjalanan lari pengguna: total km, total
        lari, lari terjauh, rekor, aksesori yang udah kebuka, dan persona lari
        (distribusi mood 12 minggu terakhir).

        Tone: hangat, personal, gak generik. Sebutkan angka spesifik
        (total km, jumlah lari). Kalau ada rekor, akui. Kalau aksesori baru
        kebuka, congrats. Kalau baru mulai, dorong. Gak pake em-dash.

        Bahasa: Indonesia, istilah running tetap bahasa Inggris (pace, cadence,
        HR, split, easy, tempo).
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(User $user): string
    {
        $context = $this->context($user);

        $decoded = $this->caller->call(
            kind: 'aku_profile_voice',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $context,
            schemaName: 'TemariProfileVoice',
            requiredKeys: ['profile_voice'],
            options: new ChatCallOptions(temperature: 0.75, userId: $user->id, maxTokens: 1500),
        );

        return (string) $decoded['profile_voice'];
    }

    /**
     * @return array<string, mixed>
     */
    public function context(User $user): array
    {
        $detailAggregates = ActivityDetail::query()
            ->whereHas(
                'activity',
                fn ($q) => $q->where('user_id', $user->id),
            )
            ->selectRaw('SUM(distance) AS total_distance, MAX(distance) AS longest_distance, MIN(start_date_local) AS first_run_at')
            ->first();

        $totalRuns = $user->activities()->count();
        $totalKm = round((float) ($detailAggregates?->getAttribute('total_distance') ?? 0) / 1000, 1);
        $longestKm = round((float) ($detailAggregates?->getAttribute('longest_distance') ?? 0) / 1000, 2);
        $firstRunAt = $detailAggregates?->getAttribute('first_run_at');
        $monthsSince = $firstRunAt !== null ? (int) Carbon::parse($firstRunAt)->diffInMonths(now()) : null;

        $prCount = PersonalRecord::query()->where('user_id', $user->id)->count();
        $unlockCount = UserUnlock::query()->where('user_id', $user->id)->count();
        $totalAccessories = count(config('temari_unlocks', []));

        return [
            'name' => $user->first_name ?? $user->name,
            'total_runs' => $totalRuns,
            'total_km' => $totalKm,
            'longest_run_km' => $longestKm,
            'months_running' => $monthsSince,
            'pr_count' => $prCount,
            'unlocked_accessories' => $unlockCount,
            'total_accessories' => $totalAccessories,
            'strava_connected' => $user->stravaConnection !== null,
        ];
    }
}
