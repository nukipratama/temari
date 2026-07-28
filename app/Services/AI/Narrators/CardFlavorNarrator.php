<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\RunCard;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\CardIdentityTool;
use App\Services\AI\Agent\Tools\EffortContextTool;
use App\Services\AI\Agent\Tools\KmSplitsTool;
use App\Services\AI\Agent\Tools\RunSummaryTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\RelativeEffort;

class CardFlavorNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: berikan 1 kalimat flavor maksimal 30 kata untuk kartu aktivitas.
        Setiap kartu punya rarity (common, uncommon, rare, epic, legendary) +
        special move + badges. Saat menyebut rarity dalam kalimat, gunakan
        label Bahasa Indonesia: Biasa / Berkesan / Langka / Istimewa / Legendaris.

        DATA: kartunya gak dikasih di depan. Ambil sendiri lewat tool yang ada,
        mulai dari get_card_identity, dan boleh panggil beberapa sekaligus dalam
        satu giliran. Angka yang gak pernah kamu ambil JANGAN dikarang. Kalau
        lari di balik kartu ini gak punya data detail, tool-nya memang gak
        tersedia: tulis dari kartunya saja.

        Rajut kombinasi badge, pacing, dan cuaca jadi 1 kalimat yang
        nunjukin kenapa kartu ini spesial. Sebut nama special move-nya kalau
        unik, sebut badge spesifik kalau ada, sebut cuaca kalau ekstrem
        ("cuaca 33 derajat" atau "hujan").

        TAPI: nama badge dan nama special move itu label, bukan cerita. Jangan
        cuma dirangkai pakai kata sambung. Sebut apa yang bikin label itu
        kepasang, angkanya atau kejadiannya, baru namanya kebaca berarti.
        Contoh salah: "dapet badge Z2 Master, dibawa oleh special move Calm &
        Steady." Itu dua nama yang ditempel, gak ada isinya.
        Contoh benar: "90% waktunya kamu tahan di Z2, sabar banget, pantes
        move-nya 'Calm & Steady'."

        ANGIN: sebut angin cuma kalau kencang atau bergust (weather_wind_speed_kmh
        atau weather_wind_gust_kmh tinggi) DAN dia punya peran, misalnya headwind
        yang bikin negative split makin berkesan. Angin bukan detail wajib tiap
        kartu, kalau adem lewati saja.

        HUJAN: cek weather_rain_source. "observed" boleh tegas ("pas hujan").
        "forecast" cuma prakiraan, jadi hedge ("kayaknya sempat gerimis"), jangan
        klaim "hujan deras".

        PACING: negative_split true = paruh kedua makin cepat, boleh dipuji.
        decoupling_pct rendah = efisiensi aerobik bagus. Tapi kalau kedua field
        ini gak muncul (gak ada data stream), JANGAN klaim soal pacing atau negative
        split sama sekali, fokus ke badge, cuaca, atau special move aja.

        ANTI-PATTERN:
        - Kalimat generik yang bisa berlaku untuk kartu mana pun.
        - Mengulang formula yang sama untuk rarity yang sama.

        Contoh oke:
        - "'Langkah Sunyi' dikasih label Langka karena negative split di
          paruh kedua, pace-nya malah naik pas hujan deras."
        - "Kartu Biasa, tapi special move-nya 'Pagi Baru' dan cuaca 8 derajat
          bikin sesi ini pantas dicatat."
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly RelativeEffort $relativeEffort,
    ) {
    }

    public function generate(RunCard $card): string
    {
        $card->loadMissing('activity.detail');

        $decoded = $this->caller->call(
            kind: 'card_flavor',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [],
            schemaName: 'TemariCardFlavor',
            requiredKeys: ['flavor'],
            options: new ChatCallOptions(
                temperature: 0.8,
                userId: $card->activity->user_id,
                maxTokens: 400,
                toolbox: $this->toolbox($card),
            ),
        );

        return (string) $decoded['flavor'];
    }

    /**
     * The card's own identity, plus the run behind it when that run still has
     * its detail row — a card whose activity was never detailed simply has
     * fewer reads, rather than tools that answer null to everything.
     */
    public function toolbox(RunCard $card): AgentToolbox
    {
        $activity = $card->activity;
        $detail = $activity->detail;

        if ($detail === null) {
            return new AgentToolbox([new CardIdentityTool($card)]);
        }

        return new AgentToolbox([
            new CardIdentityTool($card),
            new RunSummaryTool($activity, $detail),
            new KmSplitsTool($activity, $detail),
            new WeatherTool($activity, $detail),
            new EffortContextTool($activity, $detail, $this->relativeEffort),
        ]);
    }
}
