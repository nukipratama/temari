<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\AI\Analysis;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\PaceCalculator;

class WeeklyRecapNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 3-4 kalimat baca kondisi minggu pengguna, maksimal 90 kata.

        Cakupan: rangkum VIBE minggu ini pakai data konkret. Sebutkan 1-2
        angka yang menonjol (total km, jumlah lari, perubahan pace, atau
        pergeseran form). Tutup dengan 1 observasi atau dorongan halus.

        Kalau data minggu lalu (prev_*) tersedia, WAJIB selipkan 1 perbandingan
        week-over-week yang konkret: arah dan selisihnya, contoh "naik 4 km dari
        minggu lalu", "pace 8 detik lebih cepat", "frekuensi turun dari 4 ke 2
        lari". Kalau prev_* null (minggu pertama), lewati perbandingan, jangan
        mengarang angka.

        KESINAMBUNGAN: kalau prev_narrative ada (recap minggu sebelumnya),
        lanjutkan benang ceritanya, tunjukkan progres dari sana ke minggu ini,
        dan variasikan cara membuka. Jangan mengulang kalimat atau angka yang
        sama persis. Kalau prev_narrative null, tulis berdiri sendiri tanpa
        menyinggung minggu sebelumnya.

        Sesuaikan tone ke form_status:
        - fresh: energik, mengajak manfaatkan. "Kamu lagi fresh, minggu depan
          bisa coba quality session."
        - optimal: positif, apresiasi konsistensi. "Balance-nya pas, pertahanin."
        - fatigued: empatik, sarankan istirahat bukan push. "Minggu ini cukup
          berat, istirahat dulu gak rugi."
        - overreaching: concerned, warning halus. "Load-nya tinggi, mundur
          sedikit minggu depan."

        Gunakan data yang tersedia:
        - runs, distance_km: bandingkan secara implisit (banyak/sedikit/
          konsisten).
        - pace_sec_per_km: catatan pace kalau ada perubahan menonjol.
        - weekly_trimp: indikator beban mingguan.
        - form (CTL - ATL): positif = segar, negatif = lelah.
        - monotony: > 2 = terlalu seragam, ajak variasi.
        - strain: > 500 = berat.

        ANTI-PATTERN:
        - Mengulang angka mentah tanpa konteks.
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
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->context($snapshot),
            schemaName: 'TemariWeeklyRecap',
            requiredKeys: ['narrative'],
            options: new ChatCallOptions(temperature: 0.7, userId: $snapshot->user_id, maxTokens: 1500),
        );

        return (string) $decoded['narrative'];
    }

    /**
     * @return array{week_ending: string, runs: int|null, distance_km: float|null, pace_sec_per_km: float|null, weekly_trimp: float|null, ctl_42d: float|null, atl_7d: float|null, form: float|null, form_status: string|null, monotony: float|null, strain: float|null, prev_runs: int|null, prev_distance_km: float|null, prev_pace_sec_per_km: float|null, prev_narrative: string|null}
     */
    public function context(WeeklySnapshot $snapshot): array
    {
        $previous = $this->previousWeek($snapshot);

        return [
            'week_ending' => $snapshot->week_ending->toDateString(),
            'runs' => $snapshot->runs,
            'distance_km' => $snapshot->distance_km,
            'pace_sec_per_km' => $this->paceFor($snapshot),
            'weekly_trimp' => $snapshot->weekly_trimp,
            'ctl_42d' => $snapshot->ctl_42d,
            'atl_7d' => $snapshot->atl_7d,
            'form' => $snapshot->form,
            'form_status' => $snapshot->form_status,
            'monotony' => $snapshot->monotony,
            'strain' => $snapshot->strain,
            'prev_runs' => $previous?->runs,
            'prev_distance_km' => $previous?->distance_km,
            'prev_pace_sec_per_km' => $previous === null ? null : $this->paceFor($previous),
            'prev_narrative' => $this->prevNarrative($snapshot),
        ];
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

    private function paceFor(WeeklySnapshot $snapshot): ?float
    {
        return PaceCalculator::secPerKm(
            $snapshot->distance_km === null ? null : $snapshot->distance_km * 1000,
            $snapshot->moving_time_sec,
        );
    }

    /** The user's snapshot for the week ending 7 days before this one, if any. */
    private function previousWeek(WeeklySnapshot $snapshot): ?WeeklySnapshot
    {
        return WeeklySnapshot::query()
            ->where('user_id', $snapshot->user_id)
            ->whereDate('week_ending', $snapshot->week_ending->copy()->subWeek())
            ->first();
    }
}
