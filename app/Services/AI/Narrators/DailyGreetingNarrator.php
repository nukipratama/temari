<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Narrators\Concerns\ReadsPreviousDailyNarrative;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Story\Vibe;
use Illuminate\Support\Carbon;

class DailyGreetingNarrator
{
    use ReadsPreviousDailyNarrative;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 1-2 kalimat greeting, maksimal 30 kata.

        Sesuaikan tone dengan vibe state pengguna:
        - pumped/fresh/bouncy: energik, antusias, mengajak. "Halo! Kamu lagi
          fresh nih, sayang kalau gak dipake lari."
        - worn_down/cooked: lembut, permisif. "Halo. Badan lagi capek ya,
          istirahat juga progres."
        - stretched_thin: empatik, gak ngedesak. "Halo. Semoga harimu
          tenang, kapanpun kamu siap aku nunggu."
        - hibernating: mengajak pelan-pelan. "Halo! Udah beberapa hari gak
          lari, gimana kalau jalan kaki dulu?"

        Gunakan field `name` kalau ada untuk personalisasi ("Halo, Budi!").
        Boleh pakai 1 emoji yang cocok.

        KESINAMBUNGAN: kalau prev_narrative ada (greeting hari sebelumnya),
        lanjutkan benang sapaannya, variasikan cara membuka, dan jangan ulang
        kalimat yang sama persis. Kalau prev_narrative null, tulis berdiri
        sendiri tanpa menyinggung sapaan sebelumnya.

        ANTI-PATTERN:
        - "Halo. Semoga harimu tenang, kapanpun kamu siap lari aku nunggu."
          -- muncul terus untuk semua vibe.
        - Time-locked greeting ("Selamat pagi").
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(User $user, string $vibeState, ?Carbon $asOf = null): string
    {
        $decoded = $this->caller->call(
            kind: 'daily_greeting',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->context($user, $vibeState, $asOf ?? Carbon::today()),
            schemaName: 'TemariDailyGreeting',
            requiredKeys: ['speech'],
            options: new ChatCallOptions(userId: $user->id, maxTokens: 400),
        );

        return (string) $decoded['speech'];
    }

    /**
     * @return array{name: string, vibe: string, vibe_label: string, prev_narrative: string|null}
     */
    public function context(User $user, string $vibeState, Carbon $asOf): array
    {
        return [
            'name' => $user->firstName(),
            'vibe' => $vibeState,
            'vibe_label' => Vibe::label($vibeState),
            'prev_narrative' => $this->previousDailyNarrative(
                AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
                $user->id,
                AnalysisType::DailyGreeting,
                $asOf,
            ),
        ];
    }
}
