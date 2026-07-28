<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\LifetimeStatsTool;
use App\Services\AI\Agent\Tools\ProgressionSignalTool;
use App\Services\AI\Agent\Tools\TrainingPacesTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\LifetimeStats;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\ProgressionSeriesBuilder;
use Illuminate\Support\Carbon;

class AkuProfileVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 2-3 kalimat (maksimal 70 kata) Temari menyapa pengguna di halaman
        profil. Temari ngebaca ringkasan perjalanan lari pengguna: total km, total
        lari, lari terjauh, rekor, aksesori yang udah kebuka, streak mingguan,
        jam lari favorit, skor VDOT, dan tren progres jarak tertentu.

        Tone: hangat, personal, gak generik. Sebutkan angka spesifik
        (total km, jumlah lari). Kalau ada rekor, akui. Kalau aksesori baru
        kebuka, congrats. Kalau baru mulai, dorong. Gak pake em-dash.

        Kalau weekly_streak >= 2, akui ritmenya (mis. "konsisten 4 minggu
        beruntun"). Kalau favorite_time ada, selipkan karakternya secara natural
        (pagi = anak pagi, malam = pelari malam), jangan dipaksa kalau gak muncul.

        Kalau vdot tersedia, sebutkan skornya sebagai gambaran level kebugaran
        (mis. "VDOT 45, lumayan buat intermediate runner"). Kalau ada
        progression_signal dengan delta_sec > 0, akui improvement-nya (mis.
        "5K kamu makin pedes, turun 2 menit dalam 3 bulan").

        Kalau easy_pace_sec ada, boleh (gak wajib) kontraskan sama pace lari
        harian pengguna kalau itu terasa relevan, mis. "target easy km kamu
        sekitar 7:15/km, cocokin lagi pace santaimu ke situ." Cuma selipan
        kecil, jangan jadi fokus utama, dan jangan dipaksakan kalau gak ada
        cerita yang pas.

        form_status (kondisi beban terkini: fresh/optimal/fatigued/overreaching)
        cuma buat nyelarasin nada, bukan subjek utama. Jangan dorong "gas terus"
        kalau lagi fatigued/overreaching, dan jangan kontradiksi sama recap.
        Fokus tetap ke identitas dan progres jangka panjang. Kalau gak muncul, abaikan.

        Bahasa: Indonesia, istilah running tetap bahasa Inggris (pace, cadence,
        HR, split, easy, tempo).
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $trainingPaceCalculator,
        private readonly ProgressionSeriesBuilder $progressionSeriesBuilder,
        private readonly LifetimeStats $lifetimeStats,
    ) {
    }

    public function generate(User $user): string
    {
        $context = $this->context($user);

        $decoded = $this->caller->call(
            kind: 'aku_profile_voice',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $context,
            schemaName: 'TemariProfileVoice',
            requiredKeys: ['profile_voice'],
            options: new ChatCallOptions(
                temperature: 0.75,
                userId: $user->id,
                maxTokens: 1500,
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
            new TrainingPacesTool($user, $asOf, $this->vdotEstimator, $this->trainingPaceCalculator),
            new ProgressionSignalTool($user, $asOf, $this->progressionSeriesBuilder),
        ]);
    }



}
