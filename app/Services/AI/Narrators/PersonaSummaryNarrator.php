<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PersonaMixTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\AI\TemariPersona;
use App\Services\Run\Story\MoodMix;
use Illuminate\Support\Carbon;

class PersonaSummaryNarrator
{
    private const int LOOKBACK_WEEKS = 12;

    private const string SYSTEM_PROMPT_TEMPLATE = <<<'PROMPT'
        Tugas: 2-3 kalimat (maksimal 75 kata) yang ngebaca persona lari pengguna
        berdasarkan distribusi mood lari mereka 12 minggu terakhir.

        Mood vocabulary Daybreak: %s.

        Struktur:
        1. Identitas dominan: mood apa yang paling sering dan apa artinya
           tentang gaya lari mereka. Sebut persentase atau rasio kalau relevan.
        2. Nuansa: mood kedua yang menonjol, kontras atau pelengkap.
        3. 1 dorongan halus yang sejalan dengan persona itu.

        Kalau persona_mix_recent (6 minggu terakhir) beda arah dari
        persona_mix_earlier (6 minggu sebelumnya), sebut PERGESERAN-nya, mis.
        "belakangan lebih sering nyala dibanding bulan lalu yang lebih adem".
        Kalau mirip atau salah satu kosong, jangan dipaksakan.

        Contoh arah:
        - "60% sesi kamu adem, 25% enteng. Kamu tipe runner yang ngebangun
          base pelan-pelan, gak buru-buru. Musim depan, ada ruang buat
          nambah 1 tempo seminggu."
        - "Nyala dan oleng hampir 50:50. Kamu suka push tapi kadang
          kebablasan. Satu easy run di antara quality session bisa jadi
          keseimbangan."

        form_status (kondisi beban terkini: fresh/optimal/fatigued/overreaching)
        cuma buat nyelarasin nada dorongan, jangan kontradiksi sama recap. Kalau
        overreaching/fatigued, dorongan condong ke recovery, bukan nambah quality.
        Kalau null, abaikan.

        ANTI-PATTERN:
        - "Pola lari kamu cenderung easy-dominan" tanpa penjelasan lanjutan.
        - Formula yang sama tiap refresh.
        - Label klinis ("Anda seorang base builder").
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    private function systemPrompt(): string
    {
        return str_replace('%s', TemariPersona::MOOD_VOCAB, self::SYSTEM_PROMPT_TEMPLATE);
    }

    public function generate(User $user): string
    {
        $decoded = $this->caller->call(
            kind: 'persona_summary',
            systemPrompt: $this->systemPrompt(),
            context: $this->context($user),
            schemaName: 'TemariPersonaSummary',
            requiredKeys: ['narrative'],
            options: new ChatCallOptions(
                temperature: 0.75,
                userId: $user->id,
                maxTokens: 1500,
                toolbox: new AgentToolbox([new PersonaMixTool($user, Carbon::now())]),
            ),
        );

        return (string) $decoded['narrative'];
    }

    /**
     * Nothing: the persona is entirely a read of how the moods fell.
     *
     * @return array<string, mixed>
     */
    public function context(User $user): array
    {
        return [];
    }

    /**
     * @return list<array{mood: string, count: int, percent: float}>
     */
    public function personaMix(User $user): array
    {
        return MoodMix::between($user->id, Carbon::now()->subWeeks(self::LOOKBACK_WEEKS));
    }

}
