<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\HrZonesTool;
use App\Services\AI\Agent\Tools\KmSplitsTool;
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
        2-4 kalimat (maksimal 75 kata) jadi satu cerita kecil beralur: buka dari
        satu sorotan, tunjukin satu titik menarik di tengah (misalnya pace sempat
        pecah lalu balik, atau finish yang nyala), lalu tutup dengan enak.

        DATA: angka larinya gak dikasih di depan. Ambil sendiri lewat tool yang
        ada, panggil yang kamu perlu saja dan boleh beberapa sekaligus dalam satu
        giliran. Angka yang gak pernah kamu ambil JANGAN dikarang, dan null tetap
        null: lewati, jangan ditebak.

        Kamu menerima tiga analisis teknis yang sudah jadi di field insights:
        - technical: terjemahan teknis (cadence, decoupling, HR).
        - splits: split atau pola pacing paling menarik.
        - zones: interpretasi HR zone.
        Tafsirkan ketiganya jadi satu cerita manusiawi. Pilih SATU sorotan paling
        menonjol buat dibuka, sisanya jadi latar. JANGAN mendaftar semua angka
        atau mengulang isi analisis mentah-mentah.

        Buka dari sorotan itu, bukan dari status atau basa-basi. Kalau ada,
        sematkan detail suasana (jam lari, cuaca, medan) biar kerasa nyata.
        Sesuaikan tone ke mood di field `mood` (kode Daybreak), ikut kalibrasi
        mood di persona.

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

    /**
     * @param  array{technical: string, splits: string, zones: string}  $insights
     */
    public function generate(Activity $activity, ActivityDetail $detail, string $mood, array $insights): string
    {
        $decoded = $this->caller->call(
            kind: 'post_run_speech',
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $this->context($activity, $detail, $mood, $insights),
            schemaName: 'TemariPostRunSpeech',
            requiredKeys: ['speech'],
            options: new ChatCallOptions(
                userId: $activity->user_id,
                maxTokens: 1500,
                toolbox: $this->toolbox($activity, $detail),
            ),
        );

        return (string) $decoded['speech'];
    }

    /**
     * Only what no tool can serve: the mood this speech was asked to carry, the
     * three insight blocks written moments ago in the same job (they are not
     * persisted yet, so there is nothing to read), and the continuity line the
     * content-filter retry has to be able to strip.
     *
     * @param  array{technical: string, splits: string, zones: string}  $insights
     * @return array<string, mixed>
     */
    public function context(Activity $activity, ActivityDetail $detail, string $mood, array $insights): array
    {
        $prevNarrative = $this->previousActivityNarrative(
            $activity,
            $detail,
            AnalysisType::PostRunSpeech,
        );

        return [
            'mood' => $mood,
            'insights' => $insights,
            ...NarratorContinuity::fields($prevNarrative),
        ];
    }

    /**
     * The reads this speech may pull, each bound to this activity.
     */
    public function toolbox(Activity $activity, ActivityDetail $detail): AgentToolbox
    {
        return new AgentToolbox([
            new RunSummaryTool($activity, $detail),
            new KmSplitsTool($activity, $detail),
            new HrZonesTool($activity, $detail),
            new TerrainTool($activity, $detail),
            new WeatherTool($activity, $detail),
            new PersonalRecordsTool($activity, $detail),
            new PastYouTool($activity, $detail, $this->pastYou),
        ]);
    }
}
