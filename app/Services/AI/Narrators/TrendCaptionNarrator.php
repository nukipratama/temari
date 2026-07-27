<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\WeeklyTrendTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

class TrendCaptionNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 1-2 kalimat caption maksimal 40 kata untuk chart Fitness/Form +
        Weekly Volume.

        Fokus ke tren (naik, turun, plateau, peak). Sebutkan konteks bila ada
        (PR week, recovery week, taper).

        Gunakan data `weeks` yang ada di context: bandingkan 4 minggu terakhir
        dengan 4 minggu sebelumnya. WAJIB sebut 1 sinyal terkuat dengan ANGKA
        konkret, pakai field turunan yang sudah dihitung:
        - ctl_delta_4w: perubahan CTL (fitness) 4 minggu terakhir, positif = naik.
        - volume_recent_4w_km vs volume_prev_4w_km: ayunan volume.
        Contoh "CTL naik 6 poin dalam 4 minggu" atau "volume turun dari 38 ke
        31 km". Kalau field turunan null (pengguna baru), baca data apa adanya,
        tapi tetap sebut minimal 1 angka konkret dari `weeks` (mis. volume
        minggu terakhir).

        SATU PEMBACAAN SAJA: pilih satu kondisi yang koheren, jangan
        kontradiktif dalam satu caption. Jangan gabung "form segar" dengan
        "mulai lelah", atau "fitness naik" dengan "lagi turun". Kalau sinyalnya
        campur, ambil yang paling kuat dan ceritakan itu saja.

        Contoh:
        - "Fitness naik 3 minggu berturut, volume juga meningkat. Base lagi
          dibangun solid."
        - "Tren volume turun 2 minggu terakhir, form positif. Kayaknya lagi
          taper atau recovery alami."
        - "CTL stagnan di 40-an, volume flat. Perlu variasi buat naik level."

        ANTI-PATTERN:
        - "Tren beberapa minggu terakhir relatif rata. Solid base." --
          terlalu generik.
        - Caption yang sama setiap refresh.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingLoad $trainingLoad,
    ) {
    }

    public function generate(User $user, Carbon $asOf): string
    {
        $decoded = $this->caller->call(
            kind: 'trend_caption',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->context($user, $asOf),
            schemaName: 'TemariTrendCaption',
            requiredKeys: ['caption'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $user->id,
                maxTokens: 600,
                toolbox: new AgentToolbox([new WeeklyTrendTool($user, $asOf, $this->trainingLoad)]),
            ),
        );

        return (string) $decoded['caption'];
    }

    /**
     * Nothing: the whole caption is a read of the trend.
     *
     * @return array<string, mixed>
     */
    public function context(User $user, Carbon $asOf): array
    {
        return [];
    }


}
