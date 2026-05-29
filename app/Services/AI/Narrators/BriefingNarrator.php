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

          Maksimal 90 kata total. Setiap bagian dipisah `\n\n` (double
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

        Sesuaikan tone dengan mood pengguna hari ini (lihat field `vibe`). Untuk
        mood spesifik briefing: glow=energik, bouncy=excited dan mengajak, wobble=
        empatik, squished=concerned, dim=lembut, spinning=reflektif.

        Gunakan field `context` untuk personalisasi:
        - `this_week_runs` / `last_week_runs` / `this_week_km` / `last_week_km`:
          banding minggu ini vs minggu lalu. Naik = apresiasi, turun = ajak satu
          lari kecil tanpa nge-judge.
        - `recovery_hours`: <24 jam = easy atau rest; 24-48 jam = base/moderate
          aman; >48 jam = oke untuk sesi quality / tempo / interval.
        - `time_bucket`: HANYA untuk nuance tone (subuh/pagi = lebih cerah,
          malam = lebih kalem). BUKAN untuk bilang "sesi sekarang" atau
          asumsi user lagi mau lari di jam itu.
        - `consecutive_weeks_active`: 3+ minggu = beri kredit konsistensi. 0 =
          ajak balik pelan-pelan.
        - `form_status` (fresh/optimal/fatigued/overreaching): bentuk tone
          suggestion sesuai kapasitas. Overreaching = wajib rest, bukan
          quality session.
        - `recent_runs` (5 entry terbaru): boleh refer ke pola spesifik (misal
          "tiga lari terakhir tempo terus" → suggestion balance dengan easy).

        Suarakan kondisi user secara umum hari ini, seperti teman yang nemenin
        training. Boleh spesifik dan data-aware, asal tetap conversational.
        JANGAN kering kayak textbook, JANGAN time-locked. Tiga bagian harus
        DISTINCT, jangan saling mengulang isi.
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
            options: new ChatCallOptions(userId: $user->id, maxTokens: 2000),
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
        ];
    }
}
