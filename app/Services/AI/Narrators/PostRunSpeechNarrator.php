<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PastYouTool;
use App\Services\AI\Agent\Tools\PersonalRecordsTool;
use App\Services\AI\Agent\Tools\RunSummaryTool;
use App\Services\AI\Agent\Tools\TerrainTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Narrators\Concerns\ReadsPreviousActivityNarrative;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Story\PastYouMatcher;

class PostRunSpeechNarrator
{
    use ReadsPreviousActivityNarrative;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: cerita post-run hangat setelah pengguna selesai lari. Rangkai
        2-4 kalimat (maksimal 75 kata) jadi satu cerita kecil beralur.

        LENSA KAMU: di halaman ini ada empat blok yang berdiri sebelahan. Tiga
        lainnya sudah membedah mekanik larinya: "Terjemahan teknis" (cadence,
        decoupling, HR), "Split paling seru" (bentuk pacing, km mana yang
        kencang), dan "Zona HR" (sebaran zone). Itu bukan bagian kamu.

        Bagian kamu adalah yang gak bisa mereka sentuh: ARTI lari ini. Suasananya
        (jam berapa, cuaca, medan), posisinya dalam perjalanan pengguna (dibanding
        sesi serupa dulu, dibanding rekor, dibanding lari sebelumnya), dan
        rasanya. Mereka jawab "apa yang terjadi"; kamu jawab "kenapa lari ini
        berarti".

        Karena itu JANGAN membedah pacing, split per km, cadence, decoupling,
        atau sebaran zone. Bukan karena datanya rahasia, tapi karena blok di
        sebelah kamu sudah menceritakannya, dan pengguna membaca keduanya
        sekaligus. Kalau satu-satunya hal menarik dari lari ini memang mekanik,
        lebih baik ceritakan jarak, kebiasaan, atau kehadirannya hari itu.
        Menyebut effort secara umum ("kerasa berat", "santai") tetap boleh, itu
        rasa, bukan pembacaan angka.

        DATA: angka larinya gak dikasih di depan. Ambil sendiri lewat tool yang
        ada, panggil yang kamu perlu saja dan boleh beberapa sekaligus dalam satu
        giliran. Angka yang gak pernah kamu ambil JANGAN dikarang, dan null tetap
        null: lewati, jangan ditebak.

        Buka dari sorotan, bukan dari status atau basa-basi. Sesuaikan tone ke
        mood di field `mood` (kode Daybreak), ikut kalibrasi mood di persona.

        Soal hujan: kalau weather_rain true, lihat weather_rain_source. "observed"
        boleh disebut tegas ("sempat kehujanan"). "forecast" cuma prakiraan, belum
        tentu benar kejadian, jadi hedge ("prakiraan sempat gerimis", "kayaknya
        sempat rintik"), jangan bilang "hujan deras" atau klaim pasti.

        JANGAN PERNAH menyebut "PR" atau "personal record" kecuali kamu sudah
        panggil get_personal_records DAN daftarnya berisi. Kalau daftarnya kosong
        (atau gak kamu ambil), rayakan sorotan nyata lain (jarak, konsistensi,
        finish, atau cuaca), bukan PR yang tidak ada.

        DIRI KAMU DULU: kalau `past_you` dari get_past_you terisi (ada lari serupa
        di masa lalu), boleh jadikan hook buka atau tutup yang personal, misal
        "dibanding sesi serupa {days_ago} hari lalu, pace-mu {pace_diff_sec}
        detik lebih cepat". pace_diff_sec dan time_diff_sec positif = sekarang
        LEBIH CEPAT, negatif = lebih pelan (akui apa adanya, jangan dipoles jadi
        selalu menang). hr_diff_bpm positif = HR lebih tinggi sekarang. Kalau
        `past_you` null, JANGAN mengarang perbandingan masa lalu.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly PastYouMatcher $pastYou,
    ) {
    }

    public function generate(Activity $activity, ActivityDetail $detail, string $mood): string
    {
        $decoded = $this->caller->call(
            kind: 'post_run_speech',
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $this->context($activity, $detail, $mood),
            schemaName: 'TemariPostRunSpeech',
            requiredKeys: ['speech'],
            options: new ChatCallOptions(
                temperature: 0.8,
                userId: $activity->user_id,
                maxTokens: 1500,
                toolbox: $this->toolbox($activity, $detail),
            ),
        );

        return (string) $decoded['speech'];
    }

    /**
     * Only what no tool can serve: the mood this speech was asked to carry, and
     * the continuity line the content-filter retry has to be able to strip.
     *
     * @return array<string, mixed>
     */
    public function context(Activity $activity, ActivityDetail $detail, string $mood): array
    {
        $prevNarrative = $this->previousActivityNarrative(
            $activity,
            $detail,
            AnalysisType::PostRunSpeech,
        );

        return [
            'mood' => $mood,
            ...NarratorContinuity::fields($prevNarrative),
        ];
    }

    /**
     * The reads this speech may pull, each bound to this activity.
     *
     * Deliberately narrower than run insight's, and narrower still than it was:
     * no per-km table, no zone breakdown, and no longer the three insight blocks
     * as prose either. Handing it any of them produced a fourth telling of the
     * same run alongside the three lenses that had already told it, on a page
     * where all four sit side by side. What is left is the material for the one
     * lens the others cannot hold: where this run sits against the athlete's own
     * history, and what the day around it was like.
     */
    public function toolbox(Activity $activity, ActivityDetail $detail): AgentToolbox
    {
        return new AgentToolbox([
            new RunSummaryTool($activity, $detail),
            new TerrainTool($activity, $detail),
            new WeatherTool($activity, $detail),
            new PersonalRecordsTool($activity, $detail),
            new PastYouTool($activity, $detail, $this->pastYou),
        ]);
    }
}
