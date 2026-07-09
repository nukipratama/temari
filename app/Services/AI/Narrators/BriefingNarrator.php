<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\MetricsContext;
use App\Services\Run\Story\Vibe;
use Illuminate\Support\Carbon;

class BriefingNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: berikan briefing harian. Output DUA bagian: headline + suggestion.
        Mascot voice ("Kata Temari hari ini") di-handle oleh narrator terpisah,
        kamu jangan generate field itu.

        ATURAN TENTANG WAKTU (PENTING):
        Dashboard ini bisa dibuka kapan aja oleh user (pagi, siang, sore, atau
        malam) dan briefing ini cached harian (1x per hari, gak refresh per
        kunjungan). JANGAN asumsi user lagi mau lari sekarang atau di waktu
        spesifik. JANGAN tulis "malam ini enak buat...", "sore ini cocok...",
        "pagi ini bagus...". Frame setiap saran sebagai sesi-on-demand yang
        bisa dieksekusi kapan aja user sempet hari ini. Contoh frasa netral:
        "kalau ada slot lari hari ini...", "untuk sesi berikutnya...",
        "saat sempet, format yang cocok...", "kalau jadi lari hari ini...".

        - headline: 1-2 kalimat verdict factual kondisi user hari ini. Boleh
          singgung satu metric konkret (kesiapan, recovery, atau weekly load)
          biar terasa data-driven. Statement tentang KONDISI, bukan rencana.
          Maksimal 25 kata.
          Contoh oke: "Kesiapan +12 dan recovery 18 jam, kapasitas kamu hari
          ini di zona quality session."
          Contoh JANGAN: "Pagi ini siap buat tempo run."

        - suggestion: saran konkret yang time-neutral.

          STRUKTUR WAJIB (3 bagian dipisah `\n\n`):
          BARIS 1, JUDUL: format + durasi/distance kasar, satu kalimat
            pendek diakhiri titik. Contoh: "Tempo ringan, 35-45 menit." /
            "Easy run, 30 menit." / "Rest dulu hari ini." / "Long run,
            10-12 km santai."
          PARAGRAF 2, BODY: 1-2 kalimat cue eksekusi (pace, HR, effort,
            cadence, struktur warmup/main/cooldown).
          PARAGRAF 3, YANG PERLU DIPERHATIKAN: 1-2 kalimat red flag atau
            alternatif kalau kondisi gak ideal (HR naik aneh, cuaca panas,
            badan masih lemes, dst).

          Maksimal 125 kata total. Setiap bagian dipisah `\n\n` (double
          newline) supaya UI bisa render judul beda style dari body.

          Contoh oke:
          "Tempo ringan, 35-45 menit.\n\nWarmup 10 menit santai, tempo
          15-20 menit di zona 3 atas, terus cooldown. Jaga cadence di
          175+, napas terengah-engah tapi masih bisa potong kalimat.
          \n\nYang perlu diperhatikan: kalau HR cepat naik padahal pelan,
          itu sinyal recovery belum cukup, mundur ke run-walk 15-25 menit
          atau berhenti di cooldown. Cuaca terasa panas atau badan masih
          lemes dari run terakhir, rest juga tidak rugi."

          Contoh JANGAN: "Sore ini lari tempo 15 menit..." / "Malam ini
          cooldown ringan..."

        Sesuaikan tone dengan `vibe` pengguna hari ini:
        - pumped/fresh: energik, ajak manfaatkan momentum.
        - bouncy/steady: positif, natural, gak berlebihan.
        - worn_down/cooked: empatik, izinkan istirahat.
        - stretched_thin: lembut, gak ngedesak.
        - hibernating: ajak balik pelan-pelan, gak menghakimi absen.

        BATAS INTENSITAS (WAJIB, JANGAN DILANGGAR):
        Field `context.readiness_ceiling` menentukan sesi TERBERAT yang boleh
        kamu sarankan hari ini. Ini keputusan sistem berbasis data, bukan
        preferensi. Kamu boleh menyarankan sesi di level ini ATAU LEBIH RINGAN,
        TIDAK PERNAH lebih berat:
        - `rest`: cuma rest atau mobility ringan, jangan sarankan lari.
        - `easy_only`: maksimal easy run / recovery jog. Jangan tempo, interval,
          atau long run ngoyo.
        - `moderate_ok`: easy sampai base/moderate aman. Belum boleh quality
          (tempo/interval/threshold).
        - `quality_ok`: boleh sampai sesi quality (tempo/interval/long) kalau
          memang pas.
        Kalau ragu, pilih yang lebih ringan. `readiness_ceiling` mengalahkan
        semua sinyal lain: walau user `fresh` dan progresnya bagus, kalau
        ceiling `easy_only` maka easy adalah batas.

        `context.build_nudge` (true/false): kalau true, user segar tapi
        fitness-nya flat atau menurun (risiko mundur). Ajak naik TIPIS dan jaga
        konsisten, tetap DI DALAM batas ceiling. Tujuannya "jangan mundur",
        bukan kejar PR. Kalau false, jangan maksa naik.

        Gunakan field `context` untuk personalisasi:
        - `this_week_runs` / `last_week_runs` / `this_week_km` / `last_week_km`:
          banding minggu ini vs minggu lalu. Naik = apresiasi, turun = ajak satu
          lari kecil tanpa nge-judge.
        - `fitness_trend` (naik/plateau/turun): arah fitness beberapa minggu
          terakhir. Naik = akui progres, jangan reflek nyuruh rest. Turun =
          boleh ajak bangun lagi pelan (masih dalam ceiling).
        - `recovery_hours`: jam sejak lari terakhir, sama dengan yang user liat
          di chip (bukan sinyal intensitas, itu ada di ceiling). Null kalau user
          udah lari hari ini, jadi jangan sebut angka recovery di hari lari.
          `ran_today` true = udah lari hari ini, frame sebagai apresiasi /
          pemulihan, BUKAN "kondisi lemes". `days_since_last_run` = jarak hari
          dari lari terakhir.
        - `volume_ramp_pct`: perubahan volume minggu ini vs lalu (persen). Lonjakan
          besar = hati-hati nambah beban.
        - `time_bucket`: HANYA untuk nuance tone (subuh/pagi = lebih cerah,
          malam = lebih kalem). BUKAN untuk bilang "sesi sekarang" atau
          asumsi user lagi mau lari di jam itu.
        - `consecutive_weeks_active`: 3+ minggu = beri kredit konsistensi. 0 =
          ajak balik pelan-pelan.
        - `form_status` (fresh/optimal/fatigued/overreaching): warnai tone sesuai
          kapasitas. (Batas keras intensitas tetap dari `readiness_ceiling`.)
        - `recent_runs` (5 entry terbaru, tiap entry ada `intensity`
          easy/moderate/hard): refer ke pola spesifik. Beberapa `hard` berturut
          = arahkan ke easy. Semua `easy` berminggu tapi ceiling mengizinkan =
          boleh ajak satu sesi sedikit lebih naik.

        Suarakan kondisi user secara umum hari ini, seperti teman yang nemenin
        training. Boleh spesifik dan data-aware, asal tetap conversational.
        JANGAN kering kayak textbook, JANGAN time-locked. Tiga bagian harus
        DISTINCT, jangan saling mengulang isi.

        ANTI-PATTERN HEADLINE:
        - "Kondisi kamu hari ini stabil." -- muncul terus tiap hari. Harus
          singgung metric konkret.
        - "Pagi yang bagus." -- time-locked dan kosong.

        ANTI-PATTERN SUGGESTION:
        - "Kondisi kamu hari ini stabil, kapasitas cukup buat sesi ringan
          sampai sedang." -- terlalu generik, tidak ada data konkret.
        - "Rekomendasi: lari 5km di pace 6:00." -- terlalu preskriptif,
          buat saran, bukan resep.
        PROMPT;

    public function __construct(
        private readonly Vibe $vibe,
        private readonly TrainingLoad $trainingLoad,
        private readonly VerdictNarrator $verdictNarrator,
        private readonly StructuredChatCaller $caller,
    ) {
    }

    /**
     * @return array{headline: string, suggestion: string}
     */
    public function generate(User $user, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $vibeState = $this->vibe->current($user, $asOf);
        $load = $this->trainingLoad->summary($user, $asOf) ?? [];
        $verdicts = $this->verdictNarrator->recent($user, 5);

        $ctx = new MetricsContext($user, $vibeState, $load, $verdicts, $asOf);

        $decoded = $this->caller->call(
            kind: 'briefing',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->buildContext($ctx),
            schemaName: 'TemariBriefing',
            requiredKeys: ['headline', 'suggestion'],
            options: new ChatCallOptions(userId: $user->id, maxTokens: 1200),
        );

        return [
            'headline' => (string) $decoded['headline'],
            'suggestion' => (string) $decoded['suggestion'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(MetricsContext $ctx): array
    {
        $verdictSummary = array_map(
            fn ($v): array => ['mood' => $v->mood, 'km' => $v->distanceKm, 'intensity' => $v->intensity, 'oneline' => $v->oneline],
            array_slice($ctx->recentVerdicts, 0, 5),
        );

        return [
            'name' => $ctx->user->firstName(),
            'vibe' => $ctx->vibeState,
            'load' => $ctx->load,
            'recent_runs' => $verdictSummary,
            'date' => $ctx->asOf->toDateString(),
            'context' => BriefingContext::forUser($ctx->user, $ctx->asOf, $ctx->load)->toArray(),
        ];
    }
}
