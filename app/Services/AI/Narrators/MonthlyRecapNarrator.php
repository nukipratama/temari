<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use Illuminate\Support\Carbon;

class MonthlyRecapNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 3-4 kalimat baca bulan lari pengguna, maksimal 85 kata.

        Cakupan: total km + jumlah lari + lari terjauh + distribusi mood
        (nyala/enteng/oleng/lemes/mumet/adem) untuk bulan itu.

        Struktur yang diharapkan:
        1. Buka dengan angka konkret (total km, jumlah lari).
        2. Narasi mood: mood mana yang dominan dan apa artinya. Gunakan data
           mood_mix -- sebut persentase kalau menonjol (mis. "60% sesi kamu
           adem, cuma 2 kali nyala").
        3. Highlight: lari terjauh, PR, atau pergeseran tren.
        4. Tutup: 1 refleksi singkat atau dorongan untuk bulan depan.

        Sesuaikan tone:
        - Mayoritas nyala/enteng: rayakan konsistensi.
        - Mayoritas lemes/mumet: empatik, akui effort, sarankan recovery.
        - Mayoritas adem: apresiasi base building sabar.
        - Campur adil: observasi bahwa variasinya sehat.

        ANTI-PATTERN:
        - "Bulan ini ritme kamu jalan terus" tanpa spesifik.
        - Mengulang formula yang sama tiap bulan.
        - Menggurui atau buat jadwal.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(User $user, string $month): string
    {
        $context = $this->context($user, $month);

        $decoded = $this->caller->call(
            kind: 'monthly_recap',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $context,
            schemaName: 'TemariMonthlyRecap',
            requiredKeys: ['narrative'],
            options: new ChatCallOptions(temperature: 0.7, userId: $user->id, maxTokens: 1500),
        );

        return (string) $decoded['narrative'];
    }

    /**
     * @return array{month: string, total_runs: int, total_distance_km: float, longest_run_km: float, mood_mix: list<array{mood: string, count: int, percent: float}>}
     */
    public function context(User $user, string $month): array
    {
        [$start, $end] = $this->monthBounds($month);

        $aggregate = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->whereBetween('start_date_local', [$start, $end])
            ->selectRaw('COUNT(*) AS runs, COALESCE(SUM(distance), 0) AS total_distance, COALESCE(MAX(distance), 0) AS longest')
            ->first();

        $runs = (int) ($aggregate?->getAttribute('runs') ?? 0);
        $totalMeters = (float) ($aggregate?->getAttribute('total_distance') ?? 0);
        $longestMeters = (float) ($aggregate?->getAttribute('longest') ?? 0);

        $moodRows = StoryLine::query()
            ->where('user_id', $user->id)
            ->whereNotNull('activity_id')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('mood, COUNT(*) as c')
            ->groupBy('mood')
            ->pluck('c', 'mood');

        $moodTotal = (int) $moodRows->sum();
        $moodMix = [];
        foreach ($moodRows as $mood => $count) {
            $count = (int) $count;
            $moodMix[] = [
                'mood' => (string) $mood,
                'count' => $count,
                'percent' => $moodTotal > 0 ? round(($count / $moodTotal) * 100, 1) : 0.0,
            ];
        }
        usort($moodMix, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'month' => $month,
            'total_runs' => $runs,
            'total_distance_km' => round($totalMeters / 1000, 1),
            'longest_run_km' => round($longestMeters / 1000, 2),
            'mood_mix' => $moodMix,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthBounds(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)?->startOfMonth() ?? Carbon::now()->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return [$start, $end];
    }
}
