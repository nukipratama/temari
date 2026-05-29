<?php

declare(strict_types=1);

namespace App\Services\AI\Demo;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisType;

/**
 * Rule-based content per AnalysisType. Used by:
 * - DemoSeedCommand to backfill Analysis rows without spending LLM tokens
 * - BriefingComposer when Azure OpenAI is unconfigured (empty env)
 *
 * Output is deterministic and intentionally flat. It is not Temari's natural
 * voice. Users with a configured Azure can re-trigger via "Baca ulang" to get
 * real LLM output.
 */
final class RuleBasedNarrationFiller
{
    public function fillFor(Analysis $row): string
    {
        return match ($row->analysis_type) {
            AnalysisType::BriefingHeadline => $this->briefingHeadline(),
            AnalysisType::BriefingSuggestion => $this->briefingSuggestion(),
            AnalysisType::BriefingMascotVoice => $this->briefingMascotVoice(),
            AnalysisType::BriefingFeaturedKartuVoice => $this->briefingFeaturedKartuVoice(),
            AnalysisType::PostRunSpeech => $this->postRunSpeech($row->subject_id),
            AnalysisType::DailyGreeting => $this->dailyGreeting(),
            AnalysisType::RunInsightTechnical => $this->runInsightTechnical($row->subject_id),
            AnalysisType::RunInsightSplits => $this->runInsightSplits(),
            AnalysisType::RunInsightZones => $this->runInsightZones(),
            AnalysisType::WeeklyRecap => $this->weeklyRecap(),
            AnalysisType::PrContext => $this->prContext(),
            AnalysisType::TrendCaption => $this->trendCaption(),
            AnalysisType::CardFlavor => $this->cardFlavor(),
            AnalysisType::PersonaSummary => $this->personaSummary(),
            AnalysisType::MonthlyRecap => $this->monthlyRecap(),
        };
    }

    private function briefingHeadline(): string
    {
        return 'Kondisi kamu hari ini **stabil**, kapasitas cukup buat sesi ringan sampai sedang.';
    }

    private function briefingSuggestion(): string
    {
        return "Tempo ringan, 35-45 menit.\n\nWarmup 10 menit santai, tempo 15-20 menit di zona 3 atas, terus cooldown. Jaga cadence di 175+, napas terengah-engah tapi masih bisa potong kalimat.\n\nYang perlu diperhatikan: kalau HR cepat naik padahal pelan, mundur ke run-walk 15-25 menit atau berhenti di cooldown. Cuaca terasa panas atau badan masih lemes, rest juga tidak rugi.";
    }

    private function briefingMascotVoice(): string
    {
        return 'Aku liat ritme kamu masih oke beberapa hari terakhir. Pertahanin pelan-pelan, gak usah dipaksa kalau lagi gak mood.';
    }

    private function briefingFeaturedKartuVoice(): string
    {
        return 'Kartu ini cerita tentang lari yang berkesan. Simpan sebagai pengingat pas momen itu terasa berat.';
    }

    private function postRunSpeech(int $activityId): string
    {
        $detail = ActivityDetail::query()->where('activity_id', $activityId)->first();
        if ($detail === null) {
            return 'Selesai juga. Konsisten kayak gini yang aku suka.';
        }
        $km = $detail->distance !== null ? number_format($detail->distance / 1000, 1) : '?';

        return "Lari {$km} km kelar. Pace-nya keangkut sampai akhir, bagus.";
    }

    private function dailyGreeting(): string
    {
        return 'Halo. Semoga harimu tenang, kapanpun kamu siap lari aku nunggu.';
    }

    private function runInsightTechnical(int $activityId): string
    {
        $detail = ActivityDetail::query()->where('activity_id', $activityId)->first();
        if ($detail === null) {
            return 'Detail teknis-nya belum kebaca lengkap.';
        }
        $cadence = $detail->average_cadence !== null ? (int) round($detail->average_cadence * 2) : null;
        $hr = $detail->average_heartrate !== null ? (int) round($detail->average_heartrate) : null;

        $parts = [];
        if ($cadence !== null) {
            $parts[] = "cadence rata-rata {$cadence}";
        }
        if ($hr !== null) {
            $parts[] = "HR rata-rata {$hr}";
        }

        return $parts === [] ? 'Sesi ini metrik-nya konsisten.' : 'Sesi ini ' . implode(', ', $parts) . '.';
    }

    private function runInsightSplits(): string
    {
        return 'Pacing kamu cenderung stabil dari awal sampai akhir. Negative split kecil di bagian akhir lebih baik daripada positive split besar.';
    }

    private function runInsightZones(): string
    {
        return 'Distribusi zone-nya didominasi easy/zone 2. Cocok buat base building, gak overstrain.';
    }

    private function weeklyRecap(): string
    {
        return 'Minggu ini ritme kamu cukup teratur. Volume lari masuk akal, recovery juga keurus.';
    }

    private function prContext(): string
    {
        return 'PR-nya hasil dari konsistensi minggu-minggu sebelumnya, bukan kebetulan.';
    }

    private function trendCaption(): string
    {
        return 'Tren beberapa minggu terakhir relatif rata. Solid base.';
    }

    private function cardFlavor(): string
    {
        return 'Kartu ini lahir dari sesi yang tenang tapi solid.';
    }

    private function personaSummary(): string
    {
        return 'Pola lari kamu cenderung easy-dominan, sesekali quality. Tipe runner yang ngebangun pelan-pelan.';
    }

    private function monthlyRecap(): string
    {
        return 'Bulan ini ritme kamu jalan terus. Gak ngotot, gak juga ngilang. Konsisten yang aku suka.';
    }
}
