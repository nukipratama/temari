<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use Illuminate\Support\Carbon;

class PersonaSummaryNarrator
{
    private const int LOOKBACK_WEEKS = 12;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 2-3 kalimat (maksimal 75 kata) yang ngebaca persona lari pengguna
        berdasarkan distribusi mood lari mereka 12 minggu terakhir.

        Mood vocabulary Daybreak: nyala (cerah-stabil), enteng (ringan-cepat),
        oleng (kepayahan tapi selesai), lemes (overload), mumet (intervals
        / dizzy-but-running), adem (recovery / shake-out).

        Struktur:
        1. Identitas dominan: mood apa yang paling sering dan apa artinya
           tentang gaya lari mereka. Sebut persentase atau rasio kalau relevan.
        2. Nuansa: mood kedua yang menonjol, kontras atau pelengkap.
        3. 1 dorongan halus yang sejalan dengan persona itu.

        Kalau persona_mix_recent (6 minggu terakhir) beda arah dari
        persona_mix_earlier (6 minggu sebelumnya), sebut PERGESERAN-nya, mis.
        "belakangan lebih sering nyala dibanding bulan lalu yang lebih adem".
        Kalau mirip atau salah satu kosong, jangan dipaksakan.

        Contoh arah:
        - "60% sesi kamu adem, 25% enteng. Kamu tipe runner yang ngebangun
          base pelan-pelan, gak buru-buru. Musim depan, ada ruang buat
          nambah 1 tempo seminggu."
        - "Nyala dan oleng hampir 50:50. Kamu suka push tapi kadang
          kebablasan. Satu easy run di antara quality session bisa jadi
          keseimbangan."

        ANTI-PATTERN:
        - "Pola lari kamu cenderung easy-dominan" tanpa penjelasan lanjutan.
        - Formula yang sama tiap refresh.
        - Label klinis ("Anda seorang base builder").
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(User $user): string
    {
        $decoded = $this->caller->call(
            kind: 'persona_summary',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->context($user),
            schemaName: 'TemariPersonaSummary',
            requiredKeys: ['narrative'],
            options: new ChatCallOptions(temperature: 0.75, userId: $user->id, maxTokens: 1500),
        );

        return (string) $decoded['narrative'];
    }

    /**
     * @return array{lookback_weeks: int, total_runs: int, persona_mix: list<array{mood: string, count: int, percent: float}>, persona_mix_recent: list<array{mood: string, count: int, percent: float}>, persona_mix_earlier: list<array{mood: string, count: int, percent: float}>}
     */
    public function context(User $user): array
    {
        $mix = $this->personaMix($user);
        $sample = array_sum(array_map(static fn (array $row): int => $row['count'], $mix));
        $halfAgo = Carbon::now()->subWeeks(intdiv(self::LOOKBACK_WEEKS, 2));

        return [
            'lookback_weeks' => self::LOOKBACK_WEEKS,
            'total_runs' => $sample,
            'persona_mix' => $mix,
            'persona_mix_recent' => $this->moodMixBetween($user, $halfAgo, null),
            'persona_mix_earlier' => $this->moodMixBetween($user, Carbon::now()->subWeeks(self::LOOKBACK_WEEKS), $halfAgo),
        ];
    }

    /**
     * @return list<array{mood: string, count: int, percent: float}>
     */
    public function personaMix(User $user): array
    {
        return $this->moodMixBetween($user, Carbon::now()->subWeeks(self::LOOKBACK_WEEKS), null);
    }

    /**
     * Mood distribution (descending by count) for the user's post-run story
     * lines created in [$from, $to). A null $to leaves the window open-ended
     * (everything from $from onward), so it captures runs logged right now.
     *
     * @return list<array{mood: string, count: int, percent: float}>
     */
    private function moodMixBetween(User $user, Carbon $from, ?Carbon $to): array
    {
        $rows = StoryLine::query()
            ->where('user_id', $user->id)
            ->whereNotNull('activity_id')
            ->where('created_at', '>=', $from)
            ->when($to !== null, fn ($q) => $q->where('created_at', '<', $to))
            ->selectRaw('mood, COUNT(*) as c')
            ->groupBy('mood')
            ->pluck('c', 'mood');

        $total = (int) $rows->sum();
        if ($total === 0) {
            return [];
        }

        $out = [];
        foreach ($rows as $mood => $count) {
            $count = (int) $count;
            $out[] = [
                'mood' => (string) $mood,
                'count' => $count,
                'percent' => round(($count / $total) * 100, 1),
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $out;
    }
}
