<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\MonthTotalsTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use Illuminate\Support\Carbon;

class MonthlyRecapNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 3-4 kalimat baca bulan lari pengguna. Kasih ruang buat
        bercerita, tapi tetap padat, jangan bertele-tele.

        Cakupan: total km + jumlah lari + lari terjauh + distribusi mood
        (nyala/enteng/oleng/lemes/mumet/adem) + jumlah PR + progres mingguan
        di dalam bulan itu.

        Struktur yang diharapkan:
        1. Buka dengan angka konkret (total km, jumlah lari).
        2. Narasi mood (HANYA kalau mood_mix terisi): mood mana yang dominan
           dan apa artinya. Gunakan data mood_mix -- sebut persentase kalau
           menonjol (mis. "60% sesi kamu adem, cuma 2 kali nyala"). Kalau
           mood_mix kosong atau gak muncul, LEWATI langkah ini diam-diam, langsung ke
           highlight, jangan sebut bahwa data mood belum ada.
        3. Highlight: lari terjauh, jumlah PR (pr_count) kalau ada, progres
           mingguan dari weekly_distance_km (mis. "naik tiap minggu" atau
           "konsisten di kisaran 10 km"), atau arah fitness dari `fitness`
           (ctl_end vs ctl_start: naik = base kebangun, turun = fitness luntur).
           Pakai 1 yang paling menonjol.
        4. Tutup: 1 refleksi singkat atau dorongan untuk bulan depan. Kalau
           `fitness.form_status_end` overreaching/fatigued, condong ke recovery,
           jangan dorong nambah beban. Kalau gak muncul, lewati.

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
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $context,
            schemaName: 'TemariMonthlyRecap',
            requiredKeys: ['narrative'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $user->id,
                maxTokens: 1500,
                toolbox: new AgentToolbox([new MonthTotalsTool($user, $month)]),
            ),
        );

        return (string) $decoded['narrative'];
    }

    /**
     * Only the continuity line: the month's own numbers are a tool call.
     *
     * @return array<string, mixed>
     */
    public function context(User $user, string $month): array
    {
        return NarratorContinuity::fields($this->prevNarrative($user, $month));
    }

    /**
     * The previous chain link's recap narrative for continuity: the prior
     * calendar month's MonthlyRecap content, if that row is Done. The monthly
     * chain is keyed by the discriminator month (Y-m) under a single user
     * subject, so "previous" is the calendar month before $month. Returns null
     * when no Done predecessor exists (first ever month, or it is not yet
     * narrated), so the narrator opens standalone. The chain (kickoff +
     * AnalyzeMonthlyRecapJob propagation) guarantees the predecessor is Done
     * before this month narrates, so steady-state always sees the prior thread.
     */
    public function prevNarrative(User $user, string $month): ?string
    {
        $previousMonth = Carbon::createFromFormat('Y-m', $month)
            ?->subMonthNoOverflow()
            ->format('Y-m');

        if ($previousMonth === null) {
            return null;
        }

        return Analysis::query()
            ->forSubject(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, $previousMonth)
            ->where('status', AnalysisStatus::Done)
            ->value('content');
    }


}
