<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Context\ActivityNarrationContext;
use App\Services\AI\Narrators\Concerns\ReadsPreviousActivityNarrative;
use App\Services\AI\StructuredChatCaller;

use function is_string;

class PostRunSpeechNarrator
{
    use ReadsPreviousActivityNarrative;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: cerita post-run hangat untuk pengguna setelah selesai lari, 2-3
        kalimat, maksimal 55 kata.

        Kamu menerima tiga analisis teknis yang sudah jadi di field insights:
        - technical: terjemahan teknis (cadence, decoupling, HR).
        - splits: split atau pola pacing paling menarik.
        - zones: interpretasi HR zone.
        Tugasmu MENAFSIRKAN ketiganya jadi satu cerita manusiawi yang
        menghubungkan satu angka paling menonjol ke perasaan. Saring dan
        rangkai, JANGAN mendaftar semua angka atau mengulang isi analisis
        mentah-mentah. Pilih satu sorotan, sisanya jadi latar.

        Sesuaikan tone dengan mood: glow=bangga (PR atau highlight); bouncy=excited
        (negative split atau strong finish); wobble=empatik (cardiac drift atau HR
        drift); spinning=catatan kelelahan (sesi keras); squished=acknowledge cuaca
        panas; dim=netral atau konsisten.

        Hangat dan menghubungkan angka ke perasaan. Jangan mengoreksi atau
        menggurui.

        JANGAN PERNAH menyebut "PR" atau "personal record" kecuali has_pr bernilai
        true. Kalau has_pr false, rayakan sorotan nyata lain (jarak, konsistensi,
        finish, atau cuaca), bukan PR yang tidak ada.

        KESINAMBUNGAN: kalau prev_narrative ada (post-run lari sebelumnya),
        lanjutkan benang ceritanya dan variasikan cara membuka, jangan mengulang
        kalimat yang sama persis. Kalau prev_narrative null (lari pertama), tulis
        berdiri sendiri tanpa menyinggung lari sebelumnya.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    /**
     * @param  array{technical: string, splits: string, zones: string}  $insights
     */
    public function generate(Activity $activity, ActivityDetail $detail, string $mood, array $insights): string
    {
        $decoded = $this->caller->call(
            kind: 'post_run_speech',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->context($activity, $detail, $mood, $insights),
            schemaName: 'TemariPostRunSpeech',
            requiredKeys: ['speech'],
            options: new ChatCallOptions(userId: $activity->user_id, maxTokens: 1024),
        );

        return (string) $decoded['speech'];
    }

    /**
     * @param  array{technical: string, splits: string, zones: string}  $insights
     * @return array<string, mixed>
     */
    public function context(Activity $activity, ActivityDetail $detail, string $mood, array $insights): array
    {
        $hasPr = PersonalRecord::query()->where('activity_id', $activity->id)->exists();
        $shared = ActivityNarrationContext::fromDetail($detail);
        $dominantZone = $shared->zonePct === []
            ? null
            : array_search(max($shared->zonePct), $shared->zonePct, strict: true);

        return [
            'mood' => $mood,
            'has_pr' => $hasPr,
            'insights' => [
                'technical' => $insights['technical'],
                'splits' => $insights['splits'],
                'zones' => $insights['zones'],
            ],
            'distance_km' => $shared->distanceKm(1),
            'dominant_zone' => is_string($dominantZone) ? $dominantZone : null,
            'decoupling_pct' => $shared->decouplingPct,
            'negative_split' => $shared->negativeSplit,
            'weather_temp_c' => $shared->weatherTempC,
            'weather_rain' => $shared->weatherRain,
            'prev_narrative' => $this->previousActivityNarrative(
                $activity,
                $detail,
                AnalysisType::PostRunSpeech,
            ),
        ];
    }
}
