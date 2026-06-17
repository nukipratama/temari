<?php

declare(strict_types=1);

namespace App\Services\AI\RuleBased;

use App\Enums\Badge;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;

/**
 * Rule-based content per AnalysisType. Used by:
 * - DemoSeedCommand to backfill Analysis rows without spending LLM tokens
 * - BriefingComposer when Azure OpenAI is unconfigured (empty env)
 *
 * Output is deterministic and Temari-voiced. Where the subject's real data is
 * available it drives the copy (delegating run-insight types to the shared
 * {@see RuleBasedInsightBuilder} so demo output matches production), falling
 * back to seeded variants only when the subject row is missing. Users with a
 * configured Azure can re-trigger via "Baca ulang" to get real LLM output.
 */
final readonly class RuleBasedNarrationFiller
{
    public function __construct(
        private RuleBasedInsightBuilder $insightBuilder,
    ) {
    }

    public function fillFor(Analysis $row): string
    {
        $seed = $this->seedFor($row);

        return match ($row->analysis_type) {
            AnalysisType::BriefingHeadline => $this->briefingHeadline($seed),
            AnalysisType::BriefingSuggestion => $this->briefingSuggestion($seed),
            AnalysisType::BriefingMascotVoice => $this->briefingMascotVoice($seed),
            AnalysisType::BriefingFeaturedKartuVoice => $this->briefingFeaturedKartuVoice($seed),
            AnalysisType::PostRunSpeech => $this->postRunSpeech($seed),
            AnalysisType::DailyGreeting => $this->dailyGreeting($seed),
            AnalysisType::RunInsightTechnical => $this->runInsightTechnical($seed),
            AnalysisType::RunInsightSplits => $this->runInsightSplits($seed),
            AnalysisType::RunInsightZones => $this->runInsightZones($seed),
            AnalysisType::WeeklyRecap => $this->weeklyRecap($seed),
            AnalysisType::PrContext => $this->prContext($seed),
            AnalysisType::TrendCaption => $this->trendCaption($seed),
            AnalysisType::CardFlavor => $this->cardFlavor($seed),
            AnalysisType::PersonaSummary => $this->personaSummary($seed),
            AnalysisType::AkuProfileVoice => $this->akuProfileVoice($seed),
            AnalysisType::MonthlyRecap => $this->monthlyRecap($seed),
        };
    }

    /**
     * Deterministic selection seed for a row. The discriminator (when present)
     * is folded in so discriminator-keyed types (monthly/weekly recap, daily
     * briefing) produce distinct content per discriminator instead of repeating
     * the same subject-only variant. A null discriminator leaves the seed equal
     * to subject_id, preserving determinism for non-discriminated types.
     */
    private function seedFor(Analysis $row): int
    {
        if ($row->discriminator === null) {
            return $row->subject_id;
        }

        return $row->subject_id + (int) crc32($row->discriminator);
    }

    private function briefingHeadline(int $seed): string
    {
        return $this->select([
            'Kondisi kamu hari ini **stabil**, kapasitas cukup buat sesi ringan sampai sedang.',
            'Form lagi oke, recovery cukup. Bisa lari tapi gak usah ngoyo.',
            'Kesiapan positif, badan udah recharge. Kalau mau lari, ada tenaga.',
            'Bebas aja hari ini, kapasitas masih aman buat apa pun yang kamu mau.',
        ], $seed);
    }

    private function briefingSuggestion(int $seed): string
    {
        return $this->select([
            "Tempo ringan, 35-45 menit.\n\nWarmup 10 menit santai, tempo 15-20 menit di zona 3 atas, terus cooldown. Jaga cadence di 175+, napas terengah-engah tapi masih bisa potong kalimat.\n\nYang perlu diperhatikan: kalau HR cepat naik padahal pelan, mundur ke run-walk 15-25 menit atau berhenti di cooldown. Cuaca terasa panas atau badan masih lemes, rest juga tidak rugi.",
            "Easy run, 30-40 menit.\n\nJaga pace di zona nyaman, napas masih bisa ngobrol. Cadence di 170+ biar langkah ringan, gak usah buru-buru.\n\nYang perlu diperhatikan: kalau kaki berat atau HR naik aneh di awal, mungkin recovery belum cukup. Mundur ke jalan cepat 20 menit gak apa-apa.",
            "Long run santai, 8-12 km.\n\nPace conversational, jangan tergoda ngejar waktu. Bawa air kalau cuaca panas, istirahat sebentar di pertengahan gak masalah.\n\nYang perlu diperhatikan: jarak panjang butuh pace stabil. Kalau km 5 udah merasa dipaksa, potong jadi 6-8 km. Lebih baik pendek tapi rapi daripada panjang tapi berantakan.",
        ], $seed);
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

    private function detailFor(int $activityId): ?ActivityDetail
    {
        return ActivityDetail::query()->where('activity_id', $activityId)->first();
    }

    private function postRunSpeech(int $activityId): string
    {
        $detail = $this->detailFor($activityId);
        if ($detail === null) {
            return 'Selesai juga. Konsisten kayak gini yang aku suka.';
        }
        $km = $detail->distance !== null ? number_format($detail->distance / 1000, 1) : '?';

        $base = $this->select([
            "Lari {$km} km kelar. Pace-nya keangkut sampai akhir, bagus.",
            "Selesai {$km} km. Ritme kamu rapi, aku suka.",
            "{$km} km masuk. Konsisten kayak gini yang bikin progres.",
        ], $activityId);

        return $base . $this->postRunCoda($detail, $activityId);
    }

    /**
     * One short data-driven coda for the post-run line, picked from whichever
     * real signal the run actually carries (negative split / heat / rain),
     * seeded so a given run always reads the same. Empty when nothing stands out.
     */
    private function postRunCoda(ActivityDetail $detail, int $seed): string
    {
        $summary = is_array($detail->stream_summary) ? $detail->stream_summary : [];
        $codas = [];
        if (($summary['negative_split'] ?? false) === true) {
            $codas[] = ' Paruh kedua malah lebih kencang, mantap.';
        }
        if ($detail->weather_rain_detected === true) {
            $codas[] = ' Hujan-hujan tetap jalan, salut.';
        } elseif ($detail->weather_temp_c !== null && $detail->weather_temp_c >= 31) {
            $codas[] = " Padahal {$detail->weather_temp_c} derajat, gerah banget.";
        }

        return $codas === [] ? '' : $codas[abs($seed) % count($codas)];
    }

    private function dailyGreeting(int $seed): string
    {
        return $this->select([
            'Halo. Semoga harimu tenang, kapanpun kamu siap lari aku nunggu.',
            'Halo! Aku udah siap kalau kamu mau lari hari ini. Tapi gak buru-buru juga gak apa-apa.',
            'Pagi. Udara masih seger, tapi kalau belum mood, aku tetap di sini.',
            'Halo. Hari baru, peluang baru buat lari. Atau istirahat, kamu yang tentuin.',
            'Pagi. Aku cek data kamu, mungkin ada yang menarik hari ini. Yuk liat.',
        ], $seed);
    }

    private function runInsightTechnical(int $activityId): string
    {
        $activity = Activity::query()->with('detail')->find($activityId);
        if ($activity?->detail === null) {
            return 'Detail teknis-nya belum kebaca lengkap.';
        }

        // Same code path as production, so demo + unconfigured-env output is the
        // run's real cadence / decoupling / HR story, not a generic template.
        return $this->insightBuilder->runInsightTechnical($activity, $activity->detail);
    }

    private function runInsightSplits(int $activityId): string
    {
        $detail = $this->detailFor($activityId);
        if ($detail === null) {
            return 'Splits-nya belum kebaca lengkap.';
        }

        return $this->insightBuilder->runInsightSplits($detail);
    }

    private function runInsightZones(int $activityId): string
    {
        $detail = $this->detailFor($activityId);
        if ($detail === null) {
            return 'Distribusi zone-nya belum kebaca lengkap.';
        }

        return $this->insightBuilder->runInsightZones($detail);
    }

    private function weeklyRecap(int $snapshotId): string
    {
        $snapshot = WeeklySnapshot::query()->find($snapshotId);
        if ($snapshot === null || $snapshot->runs === null || $snapshot->runs < 1) {
            return $this->select([
                'Minggu ini ritme kamu cukup teratur. Volume lari masuk akal, recovery juga keurus.',
                'Volume minggu ini oke, gak kebanyakan tapi gak juga kosong. Balance yang sehat.',
                'Satu minggu lagi kelar. Jarak dan frekuensi lari kamu masuk akal, terus pelan-pelan aja.',
                'Minggu yang konsisten tanpa drama. Kadang kayak gini yang dibutuhin, stabil naik.',
            ], $snapshotId);
        }

        $km = number_format((float) $snapshot->distance_km, 1);
        $runs = $snapshot->runs;
        $closer = match ($snapshot->form_status) {
            'fresh' => 'Badan lagi seger, ada ruang buat naik pelan-pelan.',
            'optimal' => 'Kondisi pas banget, pertahanin ritme ini.',
            'fatigued' => 'Mulai kerasa capek, sisipin recovery minggu depan.',
            'overreaching' => 'Bebannya udah tinggi, jangan lupa istirahat cukup.',
            default => 'Stabil terus pelan-pelan aja.',
        };

        return $this->select([
            "{$km} km dalam {$runs} lari minggu ini. {$closer}",
            "Minggu ini kekumpul {$km} km dari {$runs} sesi. {$closer}",
            "{$runs} lari, total {$km} km. {$closer}",
        ], $snapshotId);
    }

    private function prContext(int $seed): string
    {
        return $this->select([
            'PR-nya hasil dari konsistensi minggu-minggu sebelumnya, bukan kebetulan.',
            'Ini bukan keberuntungan, ini hasil kerja keras yang kekumpul pelan-pelan.',
            'PR baru! Setiap detik yang dipotong itu bukti latihan yang gak putus.',
            'Rekor baru kebuka. Kamu udah bayar mahal pelan-pelan, ini hasilnya.',
        ], $seed);
    }

    private function trendCaption(int $seed): string
    {
        return $this->select([
            'Tren beberapa minggu terakhir relatif rata. Solid base.',
            'Garis volume relatif stabil, gak naik drastis tapi gak turun juga. Fondasi yang aman.',
            'Belum ada pergerakan besar di tren, tapi stabil bukan berarti diam. Base lagi dibangun.',
            'Volume konsisten beberapa minggu terakhir. Makin lama makin solid.',
        ], $seed);
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
            Badge::NegativeSplit->value => 'Paruh kedua malah makin nyala.',
            Badge::HariPanas->value => 'Padahal hari lagi gerah-gerahnya.',
            Badge::PejuangHujan->value => 'Hujan pun gak bikin kamu mundur.',
            Badge::AnakPagi->value => 'Berangkat pas dunia masih sepi.',
            Badge::LongSlowDistance->value => 'Jarak panjang, sabar dijaga.',
            Badge::TahanDiri->value => 'Pace ditahan rapi dari awal.',
            Badge::AnakMalam->value => 'Malam makin larut, kamu makin jalan.',
            Badge::Pendaki->value => 'Elevasi gede, tenaga ekstra.',
            Badge::PertamaKali->value => 'Langkah pertama yang gak bakal dilupain.',
            Badge::Rajin->value => 'Tiga hari berturut, disiplin abis.',
            Badge::Kilat->value => 'Pace di bawah 5 per km, kencang.',
            Badge::Jauh->value => 'Half marathon ke atas, jarak serius.',
            Badge::Z2Master->value => 'Mayoritas waktu di Z2, sabar banget.',
            Badge::AnakDingin->value => 'Pagi buta tapi semangat udah nyala.',
            Badge::Keras->value => 'HR tinggi dari awal sampai akhir.',
            Badge::Santai->value => 'Beneran easy, HR dijaga rendah.',
            Badge::Berturut->value => 'Seminggu penuh tanpa skip, keren.',
            Badge::HariSpesial->value => 'Lari pas hari libur nasional.',
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

    private function akuProfileVoice(int $seed): string
    {
        return $this->select([
            'Aku catat semua perjalanan kamu di sini: **kartu**, **rekor**, **aksesori**, ceritanya. Ayo terus jalan.',
            'Seluruh cerita lari kamu ada di sini. Dari kartu pertama sampai rekor terbaru, aku simpan semua.',
            'Ini ruang kamu. **Kartu**, **rekor**, **aksesori** yang udah kamu kumpulin, aku jaga baik-baik.',
            'Semua yang kamu dapetin dari lari ada di sini. Aku terus catat, kamu terus jalan.',
        ], $seed);
    }

    private function personaSummary(int $seed): string
    {
        return $this->select([
            'Pola lari kamu cenderung easy-dominan, sesekali quality. Tipe runner yang ngebangun pelan-pelan.',
            'Lari kamu lebih sering santai daripada push. Sabar ngebangun base, gak buru-buru. Respek.',
            'Gaya lari kamu steady, gak banyak drama. Consistency over intensity, dan itu strategi yang bagus.',
            'Kamu tipe yang sabar ngebangun fondasi. Easy dominan, sesekali quality. Pelan tapi pasti.',
        ], $seed);
    }

    private function monthlyRecap(int $seed): string
    {
        return $this->select([
            'Bulan ini ritme kamu jalan terus. Gak ngotot, gak juga ngilang. Konsisten yang aku suka.',
            'Sebulan penuh lari yang teratur. Volume masuk akal, effort juga dijaga. Bulan yang solid.',
            'Bulan yang tanpa skip berarti. Kamu datang, lari, pulang. Pola yang sehat.',
            'Bulan ini kamu pilih konsistensi daripada intensitas. Dan itu pilihan yang bagus.',
        ], $seed);
    }
}
