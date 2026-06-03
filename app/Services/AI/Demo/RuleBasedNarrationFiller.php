<?php

declare(strict_types=1);

namespace App\Services\AI\Demo;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
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
            AnalysisType::BriefingMascotVoice => $this->briefingMascotVoice($row->subject_id),
            AnalysisType::BriefingFeaturedKartuVoice => $this->briefingFeaturedKartuVoice($row->subject_id),
            AnalysisType::PostRunSpeech => $this->postRunSpeech($row->subject_id),
            AnalysisType::DailyGreeting => $this->dailyGreeting(),
            AnalysisType::RunInsightTechnical => $this->runInsightTechnical($row->subject_id),
            AnalysisType::RunInsightSplits => $this->runInsightSplits(),
            AnalysisType::RunInsightZones => $this->runInsightZones(),
            AnalysisType::WeeklyRecap => $this->weeklyRecap(),
            AnalysisType::PrContext => $this->prContext(),
            AnalysisType::TrendCaption => $this->trendCaption(),
            AnalysisType::CardFlavor => $this->cardFlavor($row->subject_id),
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

    private function briefingMascotVoice(int $seed): string
    {
        return $this->select([
            'Aku liat ritme kamu masih oke beberapa hari terakhir. Pertahanin pelan-pelan, gak usah dipaksa kalau lagi gak mood.',
            'Beberapa hari ini kamu cukup konsisten. Lanjut santai aja, aku nemenin.',
            'Ritme kamu kebaca stabil. Gak perlu ngoyo, yang penting jalan terus.',
        ], $seed);
    }

    private function briefingFeaturedKartuVoice(int $seed): string
    {
        return $this->select([
            'Kartu ini nyimpen cerita lari yang berkesan. Buka lagi pas kamu butuh dorongan.',
            'Ada satu kartu yang menonjol minggu ini. Simpan sebagai pengingat kamu bisa.',
            'Kartu ini bukti sesi yang pantas dikenang. Pajang aja, gak rugi.',
        ], $seed);
    }

    private function postRunSpeech(int $activityId): string
    {
        $detail = ActivityDetail::query()->where('activity_id', $activityId)->first();
        if ($detail === null) {
            return 'Selesai juga. Konsisten kayak gini yang aku suka.';
        }
        $km = $detail->distance !== null ? number_format($detail->distance / 1000, 1) : '?';

        return $this->select([
            "Lari {$km} km kelar. Pace-nya keangkut sampai akhir, bagus.",
            "Selesai {$km} km. Ritme kamu rapi, aku suka.",
            "{$km} km masuk. Konsisten kayak gini yang bikin progres.",
        ], $activityId);
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

    /**
     * Card flavor woven from the card's own context (rarity + special move +
     * distance + first badge), seeded by card id so each card reads differently
     * while staying deterministic. Mirrors the per-rarity pools of the real
     * {@see \App\Services\AI\Narrators\CardFlavorNarrator}, minus the LLM.
     */
    private function cardFlavor(int $cardId): string
    {
        $card = RunCard::query()->with('activity.detail')->find($cardId);
        if ($card === null) {
            return 'Kartu ini lahir dari sesi yang tenang tapi solid.';
        }

        $move = $card->special_move;
        $distance = $card->activity->detail?->distance;
        $km = $distance !== null ? number_format((float) $distance / 1000, 1) : null;

        $pool = self::FLAVOR_POOLS[$card->rarity->value];
        $templates = $km === null
            ? array_values(array_filter($pool, fn (string $t): bool => ! str_contains($t, '{km}')))
            : $pool;
        if ($templates === []) {
            $templates = $pool; // every pool keeps km-less variants; stay non-empty regardless
        }

        $base = strtr($this->select($templates, $cardId), ['{move}' => $move, '{km}' => (string) $km]);
        $badgeClause = $this->badgeClause($card->badges, $cardId);

        return $badgeClause === null ? $base : $base . ' ' . $badgeClause;
    }

    /**
     * Per-rarity flavor templates. `{move}` and `{km}` are filled from the card;
     * km-less templates exist so a GPS-free run never renders an empty number.
     *
     * @var array<string, non-empty-list<string>>
     */
    private const array FLAVOR_POOLS = [
        'common' => [
            '"{move}" mungkin biasa, tapi tetap kamu jalanin sampai habis.',
            'Lari {km} km yang kalem, dicatat karena konsisten itu mahal.',
            'Gak ada drama di "{move}", cuma ritme yang rapi.',
        ],
        'uncommon' => [
            '"{move}" terasa pas, ada rasa yang nyangkut di sesi ini.',
            'Lari {km} km yang berkesan, bukan sekadar angka.',
            'Ada momen di "{move}" yang bikin kamu mau inget lagi.',
        ],
        'rare' => [
            '"{move}" jarang ketemu, simpan baik-baik.',
            'Lari {km} km langka yang gak datang tiap minggu.',
            'Sesuatu di "{move}" bikin sesi ini beda dari biasanya.',
        ],
        'epic' => [
            '"{move}" luar biasa, kerja kerasnya kebaca jelas.',
            'Lari {km} km yang patut dipajang, ini bukan sesi sembarangan.',
            '"{move}" level beda, kamu lagi naik kelas.',
        ],
        'legendary' => [
            '"{move}" legendaris, sesi yang bakal kamu ceritain lama.',
            'Lari {km} km yang masuk buku sejarah lari kamu.',
            '"{move}" sekali seumur progres, rayain.',
        ],
    ];

    /**
     * Short badge-driven coda, picked deterministically when the card carries a
     * badge. Returns null when there's nothing notable to add.
     *
     * @param  array<int, string>|null  $badges
     */
    private function badgeClause(?array $badges, int $seed): ?string
    {
        if ($badges === null || $badges === []) {
            return null;
        }

        $clauses = [
            RunCard::BADGE_NEGATIVE_SPLIT => 'Paruh kedua malah makin nyala.',
            RunCard::BADGE_HARI_PANAS => 'Padahal hari lagi gerah-gerahnya.',
            RunCard::BADGE_PEJUANG_HUJAN => 'Hujan pun gak bikin kamu mundur.',
            RunCard::BADGE_ANAK_PAGI => 'Berangkat pas dunia masih sepi.',
            RunCard::BADGE_LONG_SLOW_DISTANCE => 'Jarak panjang, sabar dijaga.',
            RunCard::BADGE_TAHAN_DIRI => 'Pace ditahan rapi dari awal.',
        ];

        // Highlight one of the card's badges, chosen by seed so multi-badge
        // cards don't all lean on the same coda.
        $known = array_values(array_filter($badges, fn (string $b): bool => isset($clauses[$b])));
        if ($known === []) {
            return null;
        }

        return $clauses[$known[abs($seed) % count($known)]];
    }

    /**
     * @param  non-empty-list<string>  $pool
     */
    private function select(array $pool, int $seed): string
    {
        return $pool[abs($seed) % count($pool)];
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
