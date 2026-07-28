<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\LatestPastYouTool;
use App\Services\AI\Agent\Tools\RecentRunsTool;
use App\Services\AI\Agent\Tools\TrainingLoadTool;
use App\Services\AI\Agent\Tools\WeekStateTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Narrators\Concerns\ReadsPreviousDailyNarrative;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\PastYouMatcher;
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
        subjek. Comment observasional yang personal dan mood-aware. Buka dari
        satu observasi paling menonjol di data (bukan sapaan template). Boleh
        refer ke run terakhir, tren minggu ini vs minggu lalu, recovery
        hours, atau streak kalau relevan. Tone: hangat dan mood-aware.
        Dukungan boleh, tapi lembut dan cuma kalau pas, jangan dipaksa jadi
        penutup tiap hari. Maksimal 90 kata.

        DATA: angkanya gak dikasih di depan. Ambil sendiri lewat tool yang ada,
        panggil yang kamu perlu saja dan boleh beberapa sekaligus dalam satu
        giliran. Angka yang gak pernah kamu ambil JANGAN dikarang, dan field yang gak
        muncul di hasil tool artinya gak ada datanya: lewati, jangan ditebak.

        ATURAN WAKTU: dashboard ini bisa dibuka kapan aja, briefing
        cached harian. JANGAN asumsi user lagi mau lari "sekarang" atau
        di waktu spesifik. Bicara tentang KONDISI / OBSERVASI, bukan
        ajakan lari di jam tertentu.

        Sesuaikan tone ke vibe pengguna hari ini (lihat field `vibe`).
        Personalisasi dari hasil tool, terutama get_week_state:
        - `this_week_runs` / `last_week_runs` / `this_week_km`: tren minggu
        - `fitness_trend` (naik/plateau/turun): arah fitness. Naik = akui
          progresnya. Turun = semangati bangun lagi pelan, jangan nge-judge.
        - `recovery_hours`: jam sejak lari terakhir (sama dengan yang user liat
          di chip). Null kalau user udah lari hari ini, jadi jangan sebut angka
          recovery di hari lari, cukup pakai `ran_today`. `ran_today` true =
          udah lari hari ini, apresiasi / mode pemulihan, JANGAN bilang
          "kondisi lemes". `days_since_last_run` = jarak hari dari lari.
        - `consecutive_weeks_active`: 3+ minggu = puji konsistensi
        - `form_status`: fresh/optimal/fatigued/overreaching
        - `recent_runs`: 5 entry terbaru, tiap entry ada `intensity`
          (easy/moderate/hard), boleh refer ke pola spesifik
        - `readiness_ceiling` (rest/easy_only/moderate_ok/quality_ok): sistem
          udah nentuin batas intensitas hari ini. JANGAN dorong sesi lebih berat
          dari batas ini. Kalau `rest`/`easy_only`, jangan ajak ngoyo walau user
          lagi segar. Kamu observasi kondisi, bukan kasih resep sesi.
        - `past_you`: kalau terisi, lari terakhir user mirip sama sesi lampau.
          Boleh jadiin beat progres personal ("lari terakhirmu {pace_diff_sec}
          detik lebih cepat dari sesi serupa {days_ago} hari lalu"). pace_diff_sec
          dan time_diff_sec positif = sekarang lebih cepat, negatif = lebih pelan
          (jujur, jangan dipoles jadi selalu menang). Kalau gak muncul, JANGAN ngarang
          perbandingan masa lalu.

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

        ANTI-PATTERN:
        - "Aku liat ritme kamu masih oke beberapa hari terakhir." -- terlalu
          generik, tidak ada observasi spesifik.
        - "Semangat ya!" -- kosong, tidak ada data.
        - Ulangi kalimat yang sama tiap hari. Harus merujuk data konkret
          dari hasil tool.

        Contoh JANGAN: "Sore ini enak buat..." / "Malam ini cocok..."
        PROMPT;

    public function __construct(
        private readonly Vibe $vibe,
        private readonly TrainingLoad $trainingLoad,
        private readonly VerdictNarrator $verdictNarrator,
        private readonly StructuredChatCaller $caller,
        private readonly PastYouMatcher $pastYou,
    ) {
    }

    public function generate(User $user, ?Carbon $asOf = null): string
    {
        $decoded = $this->caller->call(
            kind: 'briefing_mascot_voice',
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $this->context($user, $asOf),
            schemaName: 'TemariMascotVoice',
            requiredKeys: ['mascot_voice'],
            options: new ChatCallOptions(
                temperature: 0.8,
                userId: $user->id,
                maxTokens: 1500,
                toolbox: $this->toolbox($user, $asOf ?? Carbon::today()),
            ),
        );

        return (string) $decoded['mascot_voice'];
    }

    /**
     * @return array<string, mixed>
     */
    public function context(User $user, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $prevNarrative = $this->previousDailyNarrative(
            AnalysisType::BRIEFING_SUBJECT_TYPE,
            $user->id,
            AnalysisType::BriefingMascotVoice,
            $asOf,
        );

        return [
            'name' => $user->firstName(),
            'vibe' => $this->vibe->current($user, $asOf),
            'date' => $asOf->toDateString(),
            ...NarratorContinuity::fields($prevNarrative),
        ];
    }

    public function toolbox(User $user, Carbon $asOf): AgentToolbox
    {
        return new AgentToolbox([
            new WeekStateTool($user, $asOf, $this->trainingLoad),
            new RecentRunsTool($user, $asOf, $this->verdictNarrator),
            new TrainingLoadTool($user, $asOf, $this->trainingLoad),
            new LatestPastYouTool($user, $asOf, $this->pastYou),
        ]);
    }
}
