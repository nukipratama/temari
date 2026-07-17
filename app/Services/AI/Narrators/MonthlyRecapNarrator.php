<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
           mood_mix kosong atau null, LEWATI langkah ini diam-diam, langsung ke
           highlight, jangan sebut bahwa data mood belum ada.
        3. Highlight: lari terjauh, jumlah PR (pr_count) kalau ada, progres
           mingguan dari weekly_distance_km (mis. "naik tiap minggu" atau
           "konsisten di kisaran 10 km"), atau arah fitness dari `fitness`
           (ctl_end vs ctl_start: naik = base kebangun, turun = fitness luntur).
           Pakai 1 yang paling menonjol.
        4. Tutup: 1 refleksi singkat atau dorongan untuk bulan depan. Kalau
           `fitness.form_status_end` overreaching/fatigued, condong ke recovery,
           jangan dorong nambah beban. Kalau null, lewati.

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
            options: new ChatCallOptions(temperature: 0.7, userId: $user->id, maxTokens: 1500),
        );

        return (string) $decoded['narrative'];
    }

    /**
     * @return array{month: string, total_runs: int, total_distance_km: float, longest_run_km: float, pr_count: int, weekly_distance_km: list<float>, mood_mix: list<array{mood: string, count: int, percent: float}>, fitness: array{ctl_start: float|null, ctl_end: float|null, form_status_end: string|null}|null, prev_narrative: string|null, prev_opener: string|null}
     */
    public function context(User $user, string $month): array
    {
        [$start, $end] = $this->monthBounds($month);
        $prevNarrative = $this->prevNarrative($user, $month);

        $details = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->whereBetween('start_date_local', [$start, $end])
            ->get(['start_date_local', 'distance']);

        $runs = $details->count();
        $totalMeters = (float) $details->sum('distance');
        $longestMeters = (float) $details->max('distance');

        $prCount = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->whereBetween('set_at', [$start, $end])
            ->count();

        $weeklyKm = $this->weeklyDistanceKm($details, $start);

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
            'pr_count' => $prCount,
            'weekly_distance_km' => $weeklyKm,
            'mood_mix' => $moodMix,
            'fitness' => $this->fitnessArc($user, $start, $end),
            ...NarratorContinuity::fields($prevNarrative),
        ];
    }

    /**
     * The month's fitness arc from the weekly snapshots ending within it: CTL at
     * the start vs end (is the month building or shedding fitness) and the
     * closing form_status. Null when the month has no snapshots to read.
     *
     * @return array{ctl_start: float|null, ctl_end: float|null, form_status_end: string|null}|null
     */
    private function fitnessArc(User $user, Carbon $start, Carbon $end): ?array
    {
        $snapshots = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->whereBetween('week_ending', [$start, $end])
            ->orderBy('week_ending')
            ->get(['ctl_42d', 'form_status']);

        if ($snapshots->isEmpty()) {
            return null;
        }

        return [
            'ctl_start' => $snapshots->first()->ctl_42d,
            'ctl_end' => $snapshots->last()->ctl_42d,
            'form_status_end' => $snapshots->last()->form_status,
        ];
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

    /**
     * Per-week distance (km, 1dp) bucketed into the calendar weeks of the month,
     * so the narrator can read the within-month progression. Week 0 = the first
     * 7 days from the month start, and so on (4-5 buckets per month).
     *
     * @param  Collection<int, ActivityDetail>  $details
     * @return list<float>
     */
    private function weeklyDistanceKm(Collection $details, Carbon $start): array
    {
        $buckets = [];
        foreach ($details as $detail) {
            if ($detail->start_date_local === null) {
                continue;
            }
            $week = intdiv((int) $start->diffInDays($detail->start_date_local, absolute: false), 7);
            $buckets[$week] = ($buckets[$week] ?? 0.0) + (float) ($detail->distance ?? 0);
        }
        if ($buckets === []) {
            return [];
        }

        ksort($buckets);
        $weeks = [];
        for ($i = 0; $i <= max(array_keys($buckets)); $i++) {
            $weeks[] = round(($buckets[$i] ?? 0.0) / 1000, 1);
        }

        return $weeks;
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
