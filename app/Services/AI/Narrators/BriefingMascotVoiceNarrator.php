<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Narrators\Concerns\ReadsPreviousDailyNarrative;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\MetricsContext;
use App\Services\Run\Story\Vibe;
use Illuminate\Support\Carbon;

/**
 * Generates ONLY the "Kata Temari hari ini" mascot voice line. Split from
 * {@see BriefingNarrator} so its LLM call is independent from the briefing
 * headline + suggestion. Retrying one surface doesn't burn tokens for the
 * other.
 */
class BriefingMascotVoiceNarrator
{
    use ReadsPreviousDailyNarrative;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 2-4 kalimat dalam suara Temari (mascot), pakai "aku" sebagai
        subjek. Comment observasional yang personal dan mood-aware. Boleh
        refer ke run terakhir, tren minggu ini vs minggu lalu, recovery
        hours, atau streak kalau relevan. Tone: hangat, supportive, gak
        menggurui. Maksimal 80 kata.

        ATURAN WAKTU: dashboard ini bisa dibuka kapan aja, briefing
        cached harian. JANGAN asumsi user lagi mau lari "sekarang" atau
        di waktu spesifik. Bicara tentang KONDISI / OBSERVASI, bukan
        ajakan lari di jam tertentu.

        Sesuaikan tone ke vibe pengguna hari ini (lihat field `vibe`).
        Gunakan field `context` untuk personalisasi:
        - `this_week_runs` / `last_week_runs` / `this_week_km`: tren minggu
        - `recovery_hours`: <24 = lemes, 24-48 = wajar, >48 = segar
        - `consecutive_weeks_active`: 3+ minggu = puji konsistensi
        - `form_status`: fresh/optimal/fatigued/overreaching
        - `recent_runs`: 5 entry terbaru, boleh refer ke pola spesifik

        VARIASI MOOD:
        - fresh: antusias, ajak manfaatkan. "Kamu lagi segar nih, dua hari
          gak lari, energi kepake semua."
        - fatigued: lembut, kasih izin istirahat. "Tubuh kamu udah kerja
          keras beberapa hari. Gak apa-apa istirahat."
        - overreaching: concerned tapi gak ceramah. "Load-nya tinggi, aku
          liat strain di atas 500. Mundur dulu ya."

        Contoh oke: "Aku liat tiga hari terakhir km kamu naik tipis, bagus.
        Tapi dari mood verdict-mu, sesi tempo udah dua kali berturut.
        Kalau jadi lari lagi, aku saranin mundur sedikit ke easy."

        KESINAMBUNGAN: kalau prev_narrative ada (Kata Temari hari sebelumnya),
        lanjutkan benang ceritanya, tunjukkan progres dari sana ke hari ini,
        dan variasikan cara membuka. Jangan mengulang kalimat atau angka yang
        sama persis. Kalau prev_narrative null, tulis berdiri sendiri tanpa
        menyinggung hari sebelumnya.

        ANTI-PATTERN:
        - "Aku liat ritme kamu masih oke beberapa hari terakhir." -- terlalu
          generik, tidak ada observasi spesifik.
        - "Semangat ya!" -- kosong, tidak ada data.
        - Ulangi kalimat yang sama tiap hari. Harus merujuk data konkret
          dari context.

        Contoh JANGAN: "Sore ini enak buat..." / "Malam ini cocok..."
        PROMPT;

    public function __construct(
        private readonly Vibe $vibe,
        private readonly TrainingLoad $trainingLoad,
        private readonly VerdictNarrator $verdictNarrator,
        private readonly StructuredChatCaller $caller,
    ) {
    }

    public function generate(User $user, ?Carbon $asOf = null): string
    {
        $decoded = $this->caller->call(
            kind: 'briefing_mascot_voice',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->context($user, $asOf),
            schemaName: 'TemariMascotVoice',
            requiredKeys: ['mascot_voice'],
            options: new ChatCallOptions(userId: $user->id, maxTokens: 1500),
        );

        return (string) $decoded['mascot_voice'];
    }

    /**
     * @return array<string, mixed>
     */
    public function context(User $user, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $vibeState = $this->vibe->current($user, $asOf);
        $load = $this->trainingLoad->summary($user, $asOf) ?? [];
        $verdicts = $this->verdictNarrator->recent($user, 5);

        return $this->buildContext(new MetricsContext($user, $vibeState, $load, $verdicts, $asOf));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(MetricsContext $ctx): array
    {
        $verdictSummary = array_map(
            fn ($v): array => ['mood' => $v->mood, 'km' => $v->distanceKm, 'oneline' => $v->oneline],
            array_slice($ctx->recentVerdicts, 0, 5),
        );

        return [
            'name' => $ctx->user->firstName(),
            'vibe' => $ctx->vibeState,
            'load' => $ctx->load,
            'recent_runs' => $verdictSummary,
            'date' => $ctx->asOf->toDateString(),
            'context' => BriefingContext::forUser($ctx->user, $ctx->asOf)->toArray(),
            'prev_narrative' => $this->previousDailyNarrative(
                AnalysisType::BRIEFING_SUBJECT_TYPE,
                $ctx->user->id,
                AnalysisType::BriefingMascotVoice,
                $ctx->asOf,
            ),
        ];
    }
}
