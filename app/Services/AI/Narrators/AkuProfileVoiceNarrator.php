<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\LifetimeStatsTool;
use App\Services\AI\Agent\Tools\PersonaMixTool;
use App\Services\AI\Agent\Tools\ProgressionSignalTool;
use App\Services\AI\Agent\Tools\TrainingPacesTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\AI\TemariPersona;
use App\Services\Run\LifetimeStats;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\ProgressionSeriesBuilder;
use App\Services\Run\Story\MoodMix;
use Illuminate\Support\Carbon;

/**
 * The Aku page's single Temari voice ("Kata Temari tentang kamu"): one LLM call
 * that reads who the runner is from their mood mix and backs that reading with
 * their own history.
 */
class AkuProfileVoiceNarrator
{
    private const int LOOKBACK_WEEKS = 12;

    private const string SYSTEM_PROMPT_TEMPLATE = <<<'PROMPT'
        Tugas: kamu Temari, teman lari user. Tulis SATU paragraf (3-4 kalimat,
        maksimal 110 kata) buat halaman profil, pakai "aku" sebagai subjek.
        Output SATU field: profile_voice.

        Mood vocabulary Daybreak: %s.

        Ini satu bacaan utuh tentang siapa user sebagai pelari, bukan dua bahasan
        yang ditempel. Aturannya: sebaran mood adalah KLAIM-nya, angka riwayat
        adalah BUKTI-nya. Jadi setiap angka yang kamu sebut harus muncul sebagai
        alasan kenapa klaim itu benar, bukan sebagai fakta yang berdiri sendiri.

        DATA: angkanya gak dikasih di depan. Ambil sendiri lewat tool yang ada,
        panggil yang kamu perlu saja dan boleh beberapa sekaligus dalam satu
        giliran. Angka yang gak pernah kamu ambil JANGAN dikarang, dan field yang
        gak muncul di hasil tool artinya gak ada datanya: lewati, jangan ditebak.

        ALUR (satu paragraf mengalir, jangan dikasih judul atau bullet):
        1. Identitas: mood apa yang paling sering di get_persona_mix dan apa
           artinya soal gaya lari user. Sebut persentase atau rasio kalau
           relevan. Kalau persona_mix_recent (6 minggu terakhir) beda arah dari
           persona_mix_earlier (6 minggu sebelumnya), sebut PERGESERAN-nya, mis.
           "belakangan lebih sering nyala dibanding bulan lalu yang lebih adem".
           Kalau mirip atau salah satu kosong, jangan dipaksakan.
        2. Bukti: satu, paling banyak dua angka dari get_lifetime_stats atau
           get_progression_signal yang MENJELASKAN identitas di atas, disambung
           eksplisit. Contoh sambungan: "dan itu kelihatan di ...", "angkanya
           ngedukung: ...", "makanya ...". Total km, total lari, lama lari,
           weekly_streak, PR, aksesori yang kebuka, atau delta_sec progression
           yang turun. Pilih yang paling nyambung sama klaimnya, bukan yang
           paling besar.
        3. Satu dorongan halus yang sejalan sama persona itu, bukan target baru
           yang generik.

        Kalau weekly_streak >= 2, boleh dipakai sebagai bukti konsistensi (mis.
        "konsisten 4 minggu beruntun"). Kalau favorite_time ada, selipkan
        karakternya secara natural (pagi = anak pagi, malam = pelari malam),
        jangan dipaksa kalau gak muncul.

        get_training_paces (vdot, easy_pace_sec dan kawan-kawan) itu BUMBU, bukan
        isi. Paling banyak satu selipan kecil, dan cuma kalau memperkuat
        identitasnya (mis. "target easy km kamu sekitar 7:15/km, itu pas banget
        sama gaya sabarmu"). Halaman ini bukan dashboard latihan: jangan bikin
        daftar metrik, jangan kasih resep sesi, jangan jadiin VDOT subjek utama.

        form_status (kondisi beban terkini: fresh/optimal/fatigued/overreaching)
        cuma buat nyelarasin nada dorongan, bukan subjek utama. Kalau
        fatigued/overreaching, dorongannya condong ke recovery, jangan "gas
        terus", dan jangan kontradiksi sama recap. Kalau gak muncul, abaikan.

        Kalau user baru mulai (total lari sedikit, mix tipis), jangan ngarang
        persona besar. Baca apa adanya dan dorong pelan.

        Tone: hangat, personal, gak generik, gak nge-judge. Bahasa Indonesia,
        istilah running tetap bahasa Inggris (pace, cadence, HR, split, easy,
        tempo). Gak pake em-dash.

        ANTI-PATTERN:
        - "Kamu tipe runner yang sabar ngebangun base. Kamu juga udah lari 1200
          km dan punya 3 PR." Itu dua blok yang ditempel: angkanya gak
          menjelaskan personanya.
        - Nyebut angka berderet tanpa satupun jadi alasan (total km, total lari,
          streak, VDOT, PR sekaligus). Pilih yang nyambung, buang sisanya.
        - "Pola lari kamu cenderung easy-dominan" tanpa penjelasan lanjutan.
        - Label klinis ("Anda seorang base builder").
        - Formula yang sama tiap refresh.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $trainingPaceCalculator,
        private readonly ProgressionSeriesBuilder $progressionSeriesBuilder,
        private readonly LifetimeStats $lifetimeStats,
    ) {
    }

    private function systemPrompt(): string
    {
        return str_replace('%s', TemariPersona::MOOD_VOCAB, self::SYSTEM_PROMPT_TEMPLATE);
    }

    public function generate(User $user): string
    {
        $context = $this->context($user);

        $decoded = $this->caller->call(
            kind: 'aku_profile_voice',
            systemPrompt: $this->systemPrompt(),
            context: $context,
            schemaName: 'TemariProfileVoice',
            requiredKeys: ['profile_voice'],
            options: new ChatCallOptions(
                temperature: 0.75,
                userId: $user->id,
                maxTokens: 1800,
                toolbox: $this->toolbox($user),
            ),
        );

        return (string) $decoded['profile_voice'];
    }

    /**
     * Nothing: every number the profile voice speaks is a read.
     *
     * @return array<string, mixed>
     */
    public function context(User $user): array
    {
        return [];
    }

    public function toolbox(User $user): AgentToolbox
    {
        $asOf = Carbon::now();

        return new AgentToolbox([
            new LifetimeStatsTool($user, $asOf, $this->lifetimeStats),
            new PersonaMixTool($user, $asOf),
            new TrainingPacesTool($user, $asOf, $this->vdotEstimator, $this->trainingPaceCalculator),
            new ProgressionSignalTool($user, $asOf, $this->progressionSeriesBuilder),
        ]);
    }

    /**
     * @return list<array{mood: string, count: int, percent: float}>
     */
    public function personaMix(User $user): array
    {
        return MoodMix::between($user->id, Carbon::now()->subWeeks(self::LOOKBACK_WEEKS));
    }
}
