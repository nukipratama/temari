<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\Metrics\StreamSummary;
use Illuminate\Support\Carbon;

use function is_array;

/**
 * Temari, the round mascot. Her job is to put the numbers into a sentence.
 *
 * Two surfaces:
 *   - post-run speech bubble (per activity, references concrete signals)
 *   - daily greeting (per user/day, references the runner's vibe)
 *
 * Speech is templated Indonesian copy with deterministic variation seeded
 * by activity_id + mood — same run always gets the same line, but two
 * different runs in the same mood pick different phrasings. The
 * `generateSpeech()` seam exists so a future iteration can swap to an LLM
 * call (Anthropic SDK is already a project pattern) without touching the
 * downstream pipeline.
 *
 * Mood is the *expression* (face frame + sigil pattern). The vibe is the
 * runner's global "today" state; mood is Temari's reaction to one specific
 * run or to the day's vibe.
 */
class Temari
{
    /** Mascot moods (internal enum keys). */
    public const MOOD_BOUNCY = 'bouncy';

    public const MOOD_GLOW = 'glow';

    public const MOOD_WOBBLE = 'wobble';

    public const MOOD_DIM = 'dim';

    public const MOOD_SPINNING = 'spinning';

    public const MOOD_SQUISHED = 'squished';

    /** Map mood → 4-char sigil pattern code (renderer interprets the chars as stitch ops). */
    private const array SIGIL_FOR_MOOD = [
        self::MOOD_BOUNCY => 'orct',     // open-round-curve-twist
        self::MOOD_GLOW => 'ssss',       // star spike pattern
        self::MOOD_WOBBLE => 'wvwv',     // wave alternating
        self::MOOD_DIM => 'dddd',        // dot field
        self::MOOD_SPINNING => 'splr',   // spiral
        self::MOOD_SQUISHED => 'fhfh',   // flat-horizon
    ];

    public function postRunLine(Activity $activity, ActivityDetail $detail): StoryLine
    {
        // Hit the PR ledger once and feed the answer into both mood inference
        // and the speech context — the prior version asked the DB twice per run.
        $hasPr = PersonalRecord::query()->where('activity_id', $activity->id)->exists();
        $mood = $this->moodForActivity($detail, $hasPr);
        $speech = $this->generateSpeech($mood, $this->contextFor($detail, $hasPr));

        return StoryLine::query()->updateOrCreate(
            [
                'user_id' => $activity->user_id,
                'activity_id' => $activity->id,
            ],
            [
                'kind' => StoryLine::KIND_POST_RUN,
                'for_date' => null,
                'mood' => $mood,
                'speech' => $speech,
                'sigil_pattern' => $this->sigilFor($mood),
            ],
        );
    }

    public function dailyGreeting(User $user, string $vibe, ?Carbon $forDate = null): StoryLine
    {
        $date = $forDate?->toDateString() ?? Carbon::today()->toDateString();
        $mood = $this->moodForVibe($vibe);

        return StoryLine::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'for_date' => $date,
            ],
            [
                'kind' => StoryLine::KIND_DAILY_GREETING,
                'activity_id' => null,
                'mood' => $mood,
                'speech' => $this->generateGreeting($vibe),
                'sigil_pattern' => $this->sigilFor($mood),
            ],
        );
    }

    private function sigilFor(string $mood): string
    {
        return self::SIGIL_FOR_MOOD[$mood] ?? self::SIGIL_FOR_MOOD[self::MOOD_DIM];
    }

    /**
     * Mood inference for a *specific* activity (different from the global daily vibe).
     * Order matters — first matching rule wins, so the most-prestigious moods come first.
     */
    private function moodForActivity(ActivityDetail $detail, bool $hasPr): string
    {
        $summary = is_array($detail->stream_summary) ? $detail->stream_summary : [];
        $hardShare = StreamSummary::hardZoneShare($summary);
        $decoupling = (float) ($summary['decoupling_pct'] ?? 0);
        $hotWeather = (int) ($detail->weather_temp_c ?? 0) >= 31;

        return match (true) {
            $hasPr => self::MOOD_GLOW,
            $hardShare >= 50.0 => self::MOOD_SPINNING,
            $decoupling > 8.0 => self::MOOD_WOBBLE,
            $hotWeather => self::MOOD_SQUISHED,
            ($summary['negative_split'] ?? false) === true => self::MOOD_BOUNCY,
            default => self::MOOD_DIM,
        };
    }

    /** Maps the runner's global vibe state to a daily-greeting mascot mood. */
    private function moodForVibe(string $vibe): string
    {
        return match ($vibe) {
            Vibe::PUMPED, Vibe::FRESH => self::MOOD_GLOW,
            Vibe::BOUNCY => self::MOOD_BOUNCY,
            Vibe::WORN_DOWN => self::MOOD_WOBBLE,
            Vibe::COOKED => self::MOOD_SQUISHED,
            Vibe::STRETCHED_THIN => self::MOOD_SPINNING,
            Vibe::HIBERNATING => self::MOOD_DIM,
            default => self::MOOD_DIM,
        };
    }

    /**
     * Concrete signals the speech generator can reference, packaged once
     * so individual phrasings can pluck what they want.
     *
     * @return array<string, mixed>
     */
    private function contextFor(ActivityDetail $detail, bool $hasPr): array
    {
        $summary = is_array($detail->stream_summary) ? $detail->stream_summary : [];
        $zonePct = StreamSummary::zonePct($summary);
        $dominantZone = $zonePct === [] ? null : array_search(max($zonePct), $zonePct, strict: true);

        return [
            'distance_km' => round(((float) ($detail->distance ?? 0)) / 1000, 1),
            'dominant_zone' => is_string($dominantZone) ? $dominantZone : null,
            'decoupling_pct' => $summary['decoupling_pct'] ?? null,
            'negative_split' => $summary['negative_split'] ?? null,
            'weather_temp_c' => $detail->weather_temp_c,
            'weather_rain' => $detail->weather_rain_detected,
            'has_pr' => $hasPr,
        ];
    }

    /**
     * Templated Indonesian copy. Variation is seeded so the same activity
     * always renders the same line, but different activities in the same
     * mood pick different phrasings.
     *
     * Swap-point for an LLM call later: replace this method's body with an
     * Anthropic SDK call, keep the (mood, context) → string contract.
     *
     * @param  array<string, mixed>  $context
     */
    public function generateSpeech(string $mood, array $context): string
    {
        $templates = $this->templatesFor($mood);
        $distance = $context['distance_km'] ?? 0;
        $zone = $context['dominant_zone'] ?? 'Z2';
        $decoupling = $context['decoupling_pct'] ?? null;
        // crc32() returns a signed int on 32-bit platforms; abs() before
        // modulo so the index never goes negative regardless of host.
        $template = $templates[abs(crc32($mood.':'.$distance.':'.$zone)) % count($templates)];

        return strtr($template, [
            ':distance' => (string) $distance,
            ':zone' => (string) $zone,
            ':drift' => is_numeric($decoupling) ? sprintf('%+.1f%%', (float) $decoupling) : '',
        ]);
    }

    /**
     * @return list<string>
     */
    private function templatesFor(string $mood): array
    {
        return match ($mood) {
            self::MOOD_GLOW => [
                'PR baru! :distance km dengan dominasi :zone — selamat, Temari ikut bangga.',
                ':distance km dan satu rekor jatuh. Catat ya, hari ini istimewa.',
                'Ini bukan run biasa: PR di :distance km. Pertahankan rasa percaya dirinya.',
            ],
            self::MOOD_SPINNING => [
                'Sesi keras :distance km, banyak waktu di :zone. Recovery besok ya.',
                'Otot kamu kerja ekstra hari ini — :zone dominan. Hydrate + istirahat.',
                ':distance km dengan effort tinggi. Temari catat sebagai sesi kunci minggu ini.',
            ],
            self::MOOD_WOBBLE => [
                'HR drift :drift di :distance km — aerobic base butuh perhatian. Banyakin easy run dulu.',
                'Decoupling mulai naik :drift. Coba kurangi intensity, perpanjang easy block.',
                ':distance km tapi cardiac drift :drift. Bukan sinyal panik, sinyal "sabar dulu".',
            ],
            self::MOOD_SQUISHED => [
                'Cuaca panas tetap kamu lawan, :distance km selesai. Konteksnya tough.',
                ':distance km dalam kondisi terik — HR pasti agak naik, itu wajar.',
                'Tropical run :distance km. Temari mention ini kalau kamu tanya kenapa pace turun.',
            ],
            self::MOOD_BOUNCY => [
                'Negative split di :distance km. Pacing kamu makin matang.',
                'Paruh kedua lebih cepat — sinyal aerobic system lagi enak. :distance km mantap.',
                ':distance km dengan finish kuat. Mode mantap mode mantap.',
            ],
            default => [
                ':distance km tercatat, dominan :zone. Konsisten = juara jangka panjang.',
                'Satu sesi lagi di rekening, :distance km. Tidak setiap run perlu spektakuler.',
                ':distance km, :zone dominan. Lanjut pelan, lanjut pasti.',
            ],
        };
    }

    private function generateGreeting(string $vibe): string
    {
        $label = Vibe::label($vibe);

        return match ($vibe) {
            Vibe::PUMPED => "Pagi! Vibe hari ini: {$label}. Manfaatin momentumnya — sesi berikutnya pasti seru.",
            Vibe::FRESH => "Pagi! Vibe hari ini: {$label}. Form positif, race day deket — jangan ngotot ya.",
            Vibe::BOUNCY => "Pagi! Vibe hari ini: {$label}. Aerobic system kamu enak banget — pertahankan.",
            Vibe::WORN_DOWN => "Pagi. Vibe hari ini: {$label}. Pertimbangkan recovery atau easy day.",
            Vibe::COOKED => "Pagi. Vibe hari ini: {$label}. Rest day bukan kelemahan — itu strategi.",
            Vibe::STRETCHED_THIN => "Pagi. Vibe hari ini: {$label}. Volume naik kecepatan, jangan dipaksa.",
            Vibe::HIBERNATING => "Pagi! Vibe hari ini: {$label}. Saatnya keluar pintu lagi?",
            default => "Pagi! Vibe hari ini: {$label}. Hari berjalan, langkah berjalan.",
        };
    }
}
