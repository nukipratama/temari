<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Context\ActivityNarrationContext;
use App\Services\AI\StructuredChatCaller;

class RunInsightNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 3 catatan interpretasi sesi lari, masing-masing 2-3 kalimat,
        maksimal 55 kata per catatan:

        - technical: terjemahkan cadence, decoupling, dan HR ke bahasa awam.
          JANGAN cuma sebut angka tanpa konteks. Jelaskan APA artinya dan,
          kalau relevan, arah perbaikannya.
          Contoh interpretasi:
          * cadence 160-165: "Cadence kamu di 162, masih di bawah ideal.
            Coba tingkatkan pelan-pelan ke 170+, langkah lebih pendek tapi
            lebih ringan."
          * decoupling > 10%: "Decoupling +12% artinya HR naik padahal pace
            tetap. Base aerobik belum solid, easy run lebih banyak bisa bantu."
          * decoupling < 5%: "Decoupling cuma +3%, aerobik kamu dalam kondisi
            bagus."
          * HR rata-rata di Z3-Z4 untuk sesi easy: "HR kamu rata-rata 165 di
            sesi yang seharusnya easy. Mungkin pace-nya keburu, atau cuaca
            panas."

        - splits: highlight 1-2 km paling menarik atau pola pacing keseluruhan.
          Sebut km spesifik dan waktunya kalau data ada. Bicara soal pola
          (negative split, even pacing, fade at the end).
          Contoh:
          * "Km 3-5 paling stabil, 6:20-6:25 per km. Km 7 melambat ke 6:50,
            ada tanjakan atau mulai capek?"
          * "Paruh kedua makin cepat, split 4 di 6:09 tercepat. Negative split
            yang rapi."

        - zones: interpretasi HR zone breakdown. Sebut persentase spesifik.
          Hubungkan ke tujuan sesi (base building, tempo work, overtraining).
          Contoh:
          * "70% waktu di Z2, cocok buat base building. Sisa 30% di Z3 naik
            pas tanjakan, wajar."
          * "Mayoritas Z3-Z4 padahal ini easy run. HR gampang naik, coba
            perlambat pace atau tambah run-walk."

        Tetap dari sudut pandang aku (Temari) yang mengamati pengguna.

        ANTI-PATTERN:
        - Data dump tanpa interpretasi ("cadence 172, HR 148") -- selalu
          jelaskan apa artinya.
        - Formula yang sama tiap sesi. Variasikan struktur kalimat.
        - Menggurui. Observasi, bukan ceramah.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    /**
     * @return array{technical: string, splits: string, zones: string}
     */
    public function generate(Activity $activity, ActivityDetail $detail): array
    {
        $decoded = $this->caller->call(
            kind: 'run_insight',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->buildContext($detail),
            schemaName: 'TemariRunInsight',
            requiredKeys: ['technical', 'splits', 'zones'],
            options: new ChatCallOptions(temperature: 0.7, userId: $activity->user_id, maxTokens: 1024),
        );

        return [
            'technical' => (string) $decoded['technical'],
            'splits' => (string) $decoded['splits'],
            'zones' => (string) $decoded['zones'],
        ];
    }

    /** @return array<string, mixed> */
    private function buildContext(ActivityDetail $detail): array
    {
        $summary = $detail->streamSummary();
        $shared = ActivityNarrationContext::fromDetail($detail);

        return [
            'distance_km' => $shared->distanceKm(2),
            'moving_time_sec' => $detail->moving_time,
            'avg_hr' => $detail->average_heartrate,
            'max_hr' => $detail->max_heartrate,
            'avg_cadence_spm' => $detail->average_cadence !== null
                ? (int) round((float) $detail->average_cadence * 2)
                : null,
            'decoupling_pct' => $shared->decouplingPct,
            'negative_split' => $shared->negativeSplit,
            'zone_pct' => $shared->zonePct,
            'per_km' => $summary['per_km'] ?? null,
            'ascent_m' => $summary['ascent_m'] ?? null,
            'weather_temp_c' => $shared->weatherTempC,
            'weather_humidity_pct' => $detail->weather_humidity_pct,
        ];
    }
}
