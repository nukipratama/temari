<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\AI\Analysis;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\WeekTotalsTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;

class WeeklyRecapNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 3-4 kalimat baca kondisi minggu pengguna. Kasih ruang buat
        bercerita, tapi tetap padat, jangan bertele-tele.

        Cakupan: rangkum VIBE minggu ini pakai data konkret. Tutup dengan 1
        observasi atau dorongan halus.

        BATAS ANGKA: maksimal 3 angka di SELURUH output, dan salah satunya
        dipakai buat perbandingan minggu lalu. Ini plafon, bukan target. Angka
        yang gak kamu pakai buat cerita jangan disebut sama sekali. Recap yang
        bagus itu satu pembacaan yang didukung angka, bukan daftar metrik.

        Kalau data minggu lalu (prev_*) tersedia, WAJIB selipkan 1 perbandingan
        week-over-week yang konkret: arah dan selisihnya, contoh "naik 4 km dari
        minggu lalu", "pace 8 detik lebih cepat", "frekuensi turun dari 4 ke 2
        lari". Kalau prev_* null (minggu pertama), lewati perbandingan, jangan
        mengarang angka.

        Sesuaikan tone ke form_status:
        - fresh: energik, mengajak manfaatkan. "Kamu lagi fresh, minggu depan
          bisa coba quality session."
        - optimal: positif, apresiasi konsistensi. "Balance-nya pas, pertahanin."
        - fatigued: empatik, sarankan istirahat bukan push. "Minggu ini cukup
          berat, istirahat dulu gak rugi."
        - overreaching: concerned, warning halus. "Load-nya tinggi, mundur
          sedikit minggu depan."

        Daftar di bawah ini buat KAMU BACA supaya paham minggunya, bukan daftar
        yang harus disebut. Baca semuanya, lalu pilih SATU yang paling
        menjelaskan minggu ini dan ceritakan itu. Sisanya cukup jadi latar yang
        ngebentuk nada, gak usah muncul sebagai angka:
        - runs, distance_km: seberapa banyak dan seberapa rutin.
        - pace_sec_per_km: cuma menarik kalau berubah menonjol.
        - weekly_trimp: beban mingguan.
        - form (CTL - ATL): positif = segar, negatif = lelah.
        - monotony: > 2 = terlalu seragam, ajak variasi.
        - strain: > 500 = berat.
        - avg_decoupling: cardiac drift rata-rata (%). Rendah = efisiensi aerobik
          bagus (jantung stabil sepanjang lari); tinggi (di atas 8-10%) = daya
          tahan masih perlu kerja.

        ANTI-PATTERN:
        - Mengulang angka mentah tanpa konteks.
        - Numpuk beberapa metrik dalam satu kalimat: "28,4 km dari 4 lari, TRIMP
          312, form -8, monotony 1,8." Itu tabel, bukan cerita.
        - Nyebut sebuah angka cuma karena datanya ada, padahal gak nambah apa-apa
          ke pembacaan minggu ini.
        - "Minggu ini ritme kamu cukup teratur" tanpa spesifik.
        - Memberi jadwal ("minggu depan lari 4 kali"). Dorongan, bukan rencana.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(WeeklySnapshot $snapshot): string
    {
        $decoded = $this->caller->call(
            kind: 'weekly_recap',
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $this->context($snapshot),
            schemaName: 'TemariWeeklyRecap',
            requiredKeys: ['narrative'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $snapshot->user_id,
                maxTokens: 1500,
                toolbox: new AgentToolbox([new WeekTotalsTool($snapshot)]),
                maxSteps: 4,
            ),
        );

        return (string) $decoded['narrative'];
    }

    /**
     * Only the continuity line: the week's own numbers are a tool call.
     *
     * @return array<string, mixed>
     */
    public function context(WeeklySnapshot $snapshot): array
    {
        return NarratorContinuity::fields($this->prevNarrative($snapshot));
    }

    /**
     * The previous chain link's recap narrative for continuity: the most recent
     * earlier week with runs > 0 whose WeeklyRecap is Done. This follows the
     * chain's own definition of "previous" (runs > 0, gap-skipping), not the
     * exact calendar-prior week, so a zero-run week between two running weeks
     * does not sever the thread. Returns null when no such Done predecessor
     * exists (first ever week, or the predecessor not yet narrated), so the
     * narrator opens standalone. The chain (kickoff + AnalyzeWeeklyRecapJob
     * propagation) guarantees the predecessor is Done before this week narrates,
     * so steady-state always sees the prior thread.
     */
    public function prevNarrative(WeeklySnapshot $snapshot): ?string
    {
        $previousLink = WeeklySnapshot::query()
            ->where('user_id', $snapshot->user_id)
            ->where('week_ending', '<', $snapshot->week_ending)
            ->where('runs', '>', 0)
            ->whereHas('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::WeeklyRecap)
                ->where('status', AnalysisStatus::Done))
            ->orderByDesc('week_ending')
            ->first();

        if ($previousLink === null) {
            return null;
        }

        return Analysis::query()
            ->forSubject(WeeklySnapshot::class, $previousLink->id, AnalysisType::WeeklyRecap)
            ->where('status', AnalysisStatus::Done)
            ->value('content');
    }


}
