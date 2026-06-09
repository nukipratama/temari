<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use Illuminate\Support\Carbon;

/**
 * Generates the mascot voice for the Featured Kartu hero panel on HariIni.
 * Split from {@see BriefingMascotVoiceNarrator} so the two surfaces can be
 * triggered and re-triggered independently without sharing LLM cost.
 */
class BriefingFeaturedKartuVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 2-3 kalimat dalam suara Temari (mascot), pakai "aku" sebagai
        subjek. Komentar tentang kartu yang dikasih ke pengguna, bisa tentang
        nama kartu, rarity-nya, atau kenapa lari itu layak dapat kartu.
        Tone: antusias tapi tetap hangat, bukan lebay. Maksimal 65 kata.

        Fokus ke field `featured_kartu` yang ada di context. Refer ke `name`,
        `rarity_label`, `km`, atau `tags` kalau relevan.

        VARIASI:
        - Observasi tentang special_move: kenapa nama itu cocok buat sesi ini.
        - Bandingkan dengan kartu sebelumnya kalau rarity naik.
        - Sebut badge atau km spesifik.

        Contoh oke: "Aku kasih kartu ini karena 12 km tadi beneran solid.
        'Langkah Sunyi' cocok buat pace kamu yang stabil dari awal sampe akhir."

        ANTI-PATTERN:
        - "Kartu ini nyimpen cerita lari yang berkesan." -- terlalu generik.
        - "Selamat pagi..." / "Hari ini..." -- sapaan umum, dilarang.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
    ) {
    }

    public function generate(User $user, ?Carbon $asOf = null): string
    {
        $asOf ??= Carbon::today();
        $featured = $this->pickFeaturedKartu($user);

        if ($featured === null) {
            return 'Belum ada kartu khusus buat kamu minggu ini. Terus lari, aku pantau!';
        }

        $decoded = $this->caller->call(
            kind: 'briefing_featured_kartu_voice',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [
                'name' => $user->firstName(),
                'featured_kartu' => $featured,
                'date' => $asOf->toDateString(),
            ],
            schemaName: 'TemariKartuVoice',
            requiredKeys: ['kartu_voice'],
            options: new ChatCallOptions(userId: $user->id, maxTokens: 500),
        );

        return (string) $decoded['kartu_voice'];
    }

    /**
     * Mirrors the JS `pickFeaturedKartu` logic: highest-rarity card from the
     * last 8 analyzed runs.
     *
     * @return array{name: string, rarity_label: string, km: string, tags: list<string>}|null
     */
    private function pickFeaturedKartu(User $user): ?array
    {
        $runs = ActivityDetail::query()
            ->select(['id', 'activity_id', 'distance'])
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->with(['activity.runCard:id,activity_id,rarity,special_move,badges'])
            ->orderByDesc('start_date_local')
            ->limit(8)
            ->get();

        $best = null;
        $bestRank = -1;

        foreach ($runs as $run) {
            $card = $run->activity->runCard;
            if ($card === null) {
                continue;
            }
            $rank = $card->rarity->rank();
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = [
                    'name' => $card->special_move,
                    'rarity_label' => $card->rarity->label(),
                    'km' => $run->distance !== null ? round($run->distance / 1000, 1).'km' : '-',
                    'tags' => array_slice((array) ($card->badges ?? []), 0, 3),
                ];
            }
        }

        return $best;
    }
}
