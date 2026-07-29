<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\PersonalRecord;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PersonalRecordTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\VdotEstimator;

class PrContextNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 1-2 kalimat flavor untuk Personal Record, maksimal 35 kata.

        Highlight delta dari PR sebelumnya jika ada (sebutkan berapa detik
        lebih cepat). Kalau ini PR pertama di kategori, rayakan sebagai
        "PR pertama". Kalau gap-nya besar (>30 detik), soroti sebagai lompatan
        besar. Kalau tipis (<10 detik), akui effort konsisten.

        Contoh:
        - "PR 5km dipotong 12 detik dari yang lalu. Bukan kebetulan, ini
          hasil latihan yang konsisten."
        - "PR pertama di 10km! Langkah besar, kamu layak rayain."
        - "Dipotong tipis, cuma 3 detik, tapi PR tetap PR. Momentum naik."

        Tone: bangga, hangat, gak lebay.

        CUACA: kalau kondisi pas PR ekstrem (weather_temp_c tinggi di atas 30,
        atau weather_rain true), boleh sebut buat nambah bobot ("PR di tengah
        panas 32 derajat, respect"). weather_rain_source "forecast" cuma
        prakiraan, jadi hedge. Kalau adem, lewati, jangan dipaksa.

        EVENT TERKUAT: kalau is_strongest_event true, PR ini juga bikin kategori
        ini jadi event terkuat pengguna (VDOT tertinggi di antara semua jarak).
        Boleh diakui sebagai poin bangga, sebut skor vdot kalau enak ("sekarang
        ini event terkuatmu, VDOT 45"). Kalau false atau vdot gak muncul, jangan sebut
        VDOT sama sekali.

        ANTI-PATTERN:
        - "PR-nya hasil dari konsistensi minggu-minggu sebelumnya, bukan
          kebetulan." -- formula yang muncul terus.
        - Hyperbola ("INCREDIBLE!!!").
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly VdotEstimator $vdotEstimator,
    ) {
    }

    public function generate(PersonalRecord $pr): string
    {
        $decoded = $this->caller->call(
            kind: 'pr_context',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->context($pr),
            schemaName: 'TemariPrContext',
            requiredKeys: ['flavor'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $pr->user_id,
                maxTokens: 500,
                toolbox: $this->toolbox($pr),
                maxSteps: 6,
            ),
        );

        return (string) $decoded['flavor'];
    }

    /**
     * Nothing: the record and the conditions it was set in are both reads.
     *
     * @return array<string, mixed>
     */
    public function context(PersonalRecord $pr): array
    {
        return [];
    }

    public function toolbox(PersonalRecord $pr): AgentToolbox
    {
        $pr->loadMissing('activity.detail');
        $activity = $pr->activity;
        $detail = $activity?->detail;

        $tools = [new PersonalRecordTool($pr, $this->vdotEstimator)];
        if ($activity !== null && $detail !== null) {
            $tools[] = new WeatherTool($activity, $detail);
        }

        return new AgentToolbox($tools);
    }
}
