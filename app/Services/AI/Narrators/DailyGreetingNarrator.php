<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\RecentRunsTool;
use App\Services\AI\Agent\Tools\WeekStateTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Narrators\Concerns\ReadsPreviousDailyNarrative;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\Vibe;
use Illuminate\Support\Carbon;

class DailyGreetingNarrator
{
    use ReadsPreviousDailyNarrative;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 1-2 kalimat greeting, maksimal 30 kata.

        DATA: angkanya gak dikasih di depan. Ambil sendiri lewat tool yang ada,
        panggil yang kamu perlu saja dan boleh beberapa sekaligus dalam satu
        giliran. Angka yang gak pernah kamu ambil JANGAN dikarang, dan field yang gak
        muncul di hasil tool artinya gak ada datanya: lewati, jangan ditebak.

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

        ANTI-PATTERN:
        - "Halo, [nama]! [kondisi]mu masih [x], enak banget kalau dipake lari."
          -- pola sapa yang sama terus buat tiap hari. Ganti-ganti pembukanya.
        - "Halo. Semoga harimu tenang, kapanpun kamu siap lari aku nunggu."
          -- muncul terus untuk semua vibe.
        - Time-locked greeting ("Selamat pagi").
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingLoad $trainingLoad,
        private readonly VerdictNarrator $verdictNarrator,
    ) {
    }

    public function generate(User $user, string $vibeState, ?Carbon $asOf = null): string
    {
        $decoded = $this->caller->call(
            kind: 'daily_greeting',
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $this->context($user, $vibeState, $asOf ?? Carbon::today()),
            schemaName: 'TemariDailyGreeting',
            requiredKeys: ['speech'],
            options: new ChatCallOptions(
                userId: $user->id,
                maxTokens: 400,
                toolbox: $this->toolbox($user, $asOf ?? Carbon::today()),
            ),
        );

        return (string) $decoded['speech'];
    }

    /**
     * @return array{name: string, vibe: string, vibe_label: string, prev_narrative: string|null, prev_opener: string|null}
     */
    public function context(User $user, string $vibeState, Carbon $asOf): array
    {
        $prevNarrative = $this->previousDailyNarrative(
            AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
            $user->id,
            AnalysisType::DailyGreeting,
            $asOf,
        );

        return [
            'name' => $user->firstName(),
            'vibe' => $vibeState,
            'vibe_label' => Vibe::label($vibeState),
            ...NarratorContinuity::fields($prevNarrative),
        ];
    }

    /**
     * The greeting knows the vibe because the caller decided it, but until now
     * it could not tell a three-day gap from a three-week one.
     */
    public function toolbox(User $user, Carbon $asOf): AgentToolbox
    {
        return new AgentToolbox([
            new WeekStateTool($user, $asOf, $this->trainingLoad),
            new RecentRunsTool($user, $asOf, $this->verdictNarrator),
        ]);
    }
}
