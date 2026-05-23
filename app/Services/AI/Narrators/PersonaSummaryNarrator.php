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
        Tugas: 2 kalimat (maksimal 45 kata) yang ngebaca persona lari pengguna
        berdasarkan distribusi mood lari mereka 12 minggu terakhir.

        Mood vocabulary Daybreak: nyala (cerah-stabil), enteng (ringan-cepat),
        oleng (kepayahan tapi selesai), lemes (overload), mumet (intervals
        / dizzy-but-running), adem (recovery / shake-out).

        Output: tone hangat, jujur, gak menghakimi. Mulai dari kesan dominannya
        (mis. "Larimu lebih sering adem ketimbang nyala") lalu tutup dengan
        1 dorongan halus yang sejalan dengan persona itu.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(User $user): string
    {
        $mix = $this->personaMix($user);
        $sample = array_sum(array_map(static fn (array $row): int => $row['count'], $mix));

        $decoded = $this->caller->call(
            kind: 'persona_summary',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [
                'lookback_weeks' => self::LOOKBACK_WEEKS,
                'total_runs' => $sample,
                'persona_mix' => $mix,
            ],
            schemaName: 'TemariPersonaSummary',
            requiredKeys: ['narrative'],
            options: new ChatCallOptions(temperature: 0.75, userId: $user->id, maxTokens: 1500),
        );

        return (string) $decoded['narrative'];
    }

    /**
     * @return list<array{mood: string, count: int, percent: float}>
     */
    public function personaMix(User $user): array
    {
        $cutoff = Carbon::now()->subWeeks(self::LOOKBACK_WEEKS);

        $rows = StoryLine::query()
            ->where('user_id', $user->id)
            ->whereNotNull('activity_id')
            ->where('created_at', '>=', $cutoff)
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
