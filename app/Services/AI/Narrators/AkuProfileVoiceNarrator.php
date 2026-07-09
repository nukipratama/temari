<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Enums\PrCategory;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\LifetimeStats;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\ProgressionSeriesBuilder;
use Illuminate\Support\Carbon;

class AkuProfileVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 2-3 kalimat (maksimal 70 kata) Temari menyapa pengguna di halaman
        profil. Temari ngebaca ringkasan perjalanan lari pengguna: total km, total
        lari, lari terjauh, rekor, aksesori yang udah kebuka, streak mingguan,
        jam lari favorit, skor VDOT, dan tren progres jarak tertentu.

        Tone: hangat, personal, gak generik. Sebutkan angka spesifik
        (total km, jumlah lari). Kalau ada rekor, akui. Kalau aksesori baru
        kebuka, congrats. Kalau baru mulai, dorong. Gak pake em-dash.

        Kalau weekly_streak >= 2, akui ritmenya (mis. "konsisten 4 minggu
        beruntun"). Kalau favorite_time ada, selipkan karakternya secara natural
        (pagi = anak pagi, malam = pelari malam), jangan dipaksa kalau null.

        Kalau vdot tersedia, sebutkan skornya sebagai gambaran level kebugaran
        (mis. "VDOT 45, lumayan buat intermediate runner"). Kalau ada
        progression_signal dengan delta_sec > 0, akui improvement-nya (mis.
        "5K kamu makin pedes, turun 2 menit dalam 3 bulan").

        Kalau easy_pace_sec ada, boleh (gak wajib) kontraskan sama pace lari
        harian pengguna kalau itu terasa relevan, mis. "target easy km kamu
        sekitar 7:15/km, cocokin lagi pace santaimu ke situ." Cuma selipan
        kecil, jangan jadi fokus utama, dan jangan dipaksakan kalau gak ada
        cerita yang pas.

        form_status (kondisi beban terkini: fresh/optimal/fatigued/overreaching)
        cuma buat nyelarasin nada, bukan subjek utama. Jangan dorong "gas terus"
        kalau lagi fatigued/overreaching, dan jangan kontradiksi sama recap.
        Fokus tetap ke identitas dan progres jangka panjang. Kalau null, abaikan.

        Bahasa: Indonesia, istilah running tetap bahasa Inggris (pace, cadence,
        HR, split, easy, tempo).
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $trainingPaceCalculator,
        private readonly ProgressionSeriesBuilder $progressionSeriesBuilder,
        private readonly LifetimeStats $lifetimeStats,
    ) {
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
        // Reuse the cached lifetime aggregate shared with /kalender so the two
        // surfaces never drift, instead of recomputing SUM/MAX/MIN here.
        $lifetime = $this->lifetimeStats->forUser($user);
        $totalRuns = $lifetime['total_runs'];
        $totalKm = $lifetime['total_km'];
        $longestKm = $lifetime['longest_km'];
        $firstRunAt = $lifetime['first_run_at'];
        $monthsSince = $firstRunAt !== null ? (int) Carbon::parse($firstRunAt)->diffInMonths(now()) : null;

        $prCount = PersonalRecord::query()->where('user_id', $user->id)->count();
        $unlockCount = UserUnlock::query()->where('user_id', $user->id)->count();
        $totalAccessories = count(config('temari_unlocks', []));

        $vdot = $this->vdotEstimator->estimate($user);
        $vdotScore = $vdot['vdot'] ?? null;
        $paces = $this->trainingPaceCalculator->fromVdotResult($vdot);

        $progressionSignal = $this->buildProgressionSignal($user);

        return [
            'name' => $user->first_name ?? $user->name,
            'total_runs' => $totalRuns,
            'total_km' => $totalKm,
            'longest_run_km' => $longestKm,
            'months_running' => $monthsSince,
            'pr_count' => $prCount,
            'unlocked_accessories' => $unlockCount,
            'total_accessories' => $totalAccessories,
            'weekly_streak' => WeeklySnapshot::consecutiveWeekStreak($user->id),
            'favorite_time' => $this->favoriteTimeBucket($user),
            'strava_connected' => $user->stravaConnection !== null,
            'vdot' => $vdotScore,
            'easy_pace_sec' => $paces['easy'] ?? null,
            'marathon_pace_sec' => $paces['marathon'] ?? null,
            'threshold_pace_sec' => $paces['threshold'] ?? null,
            'interval_pace_sec' => $paces['interval'] ?? null,
            'progression_signal' => $progressionSignal,
            'form_status' => WeeklySnapshot::latestFormStatus($user->id),
        ];
    }

    /**
     * The time-of-day bucket (pagi/siang/sore/malam) the user runs in most
     * often, or null when they have no timestamped runs.
     */
    private function favoriteTimeBucket(User $user): ?string
    {
        $byHour = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('start_date_local')
            ->selectRaw('HOUR(start_date_local) AS h, COUNT(*) AS c')
            ->groupBy('h')
            ->pluck('c', 'h');

        if ($byHour->isEmpty()) {
            return null;
        }

        $buckets = ['pagi' => 0, 'siang' => 0, 'sore' => 0, 'malam' => 0];
        foreach ($byHour as $hour => $count) {
            $buckets[$this->timeBucket((int) $hour)] += (int) $count;
        }

        return array_keys($buckets, max($buckets))[0];
    }

    private function timeBucket(int $hour): string
    {
        return match (true) {
            $hour >= 4 && $hour < 10 => 'pagi',
            $hour >= 10 && $hour < 15 => 'siang',
            $hour >= 15 && $hour < 19 => 'sore',
            default => 'malam',
        };
    }

    /**
     * Pick the distance category with the biggest absolute improvement
     * and return its label + delta, so Temari can mention it.
     *
     * @return array{label: string, delta_sec: int}|null
     */
    private function buildProgressionSignal(User $user): ?array
    {
        $categories = [
            PrCategory::Km5,
            PrCategory::Km10,
            PrCategory::HalfMarathon,
            PrCategory::Marathon,
        ];

        $records = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->whereIn('category', $categories)
            ->orderBy('category')
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        $best = null;
        $bestDelta = 0;

        foreach ($categories as $category) {
            $pr = $records->first(fn (PersonalRecord $r): bool => $r->category === $category);
            if ($pr === null) {
                continue;
            }

            $series = $this->progressionSeriesBuilder->buildMany($user, [$pr], fn () => null);
            $key = $category->value;
            $data = $series[$key] ?? null;

            if ($data === null) {
                continue;
            }

            if (count($data['times_sec']) < 2) {
                continue;
            }

            $worst = max($data['times_sec']);
            $bestTime = min($data['times_sec']);
            $delta = (int) ($worst - $bestTime);

            if ($delta > $bestDelta) {
                $bestDelta = $delta;
                $best = [
                    'label' => $pr->category->label(),
                    'delta_sec' => $delta,
                ];
            }
        }

        return $best;
    }
}
