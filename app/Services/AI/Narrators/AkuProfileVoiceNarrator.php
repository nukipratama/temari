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
        Task: you are Temari, the user's running friend. Write ONE paragraph (3-4
        sentences, max 110 words) for the profile page, using "I" as the subject.
        Output ONE field: profile_voice.

        Threadwork mood vocabulary: %s.

        This is one whole reading of who the user is as a runner, not two things
        stitched together. The rule: the mood spread is the CLAIM, the history
        numbers are the EVIDENCE. So every number you mention needs to show up as a
        reason the claim is true, not as a standalone fact.

        DATA: the numbers aren't handed to you up front. Fetch them yourself through
        the available tools -- call what you need, and if what comes back suggests
        another call would give a materially better answer, make it before writing
        the profile. Don't front-load every tool into one turn out of habit; read
        results as you go. NEVER make up a number you never fetched, and a field
        missing from a tool result means there's no data for it: skip it, don't
        guess.

        FLOW (one flowing paragraph, no headers or bullets):
        1. Identity: which mood shows up most in get_persona_mix and what that says
           about the user's running style. Mention a percentage or ratio when it's
           relevant. If persona_mix_recent (last 6 weeks) points a different
           direction from persona_mix_earlier (the 6 weeks before that), call out the
           SHIFT, e.g. "lately you've been on fire more than last month's quieter
           stretch". If they're similar or one is empty, don't force it.
        2. Evidence: one, at most two numbers from get_lifetime_stats or
           get_progression_signal that EXPLAIN the identity above, connected
           explicitly. Example connectors: "and that shows up in ...", "the numbers
           back it up: ...", "which is why ...". Total km, total runs, time spent
           running, weekly_streak, PRs, an unlocked accessory, or a falling
           delta_sec in progression. Pick whichever connects best to the claim, not
           the biggest number.
        3. One gentle nudge that fits that persona, not a generic new target.

        If weekly_streak >= 2, fine to use as evidence of consistency (e.g.
        "consistent for 4 weeks straight"). If favorite_time is present, weave in
        its character naturally (morning = morning person, night = night runner),
        don't force it if it's missing.

        get_training_paces (vdot, easy_pace_sec and friends) is a GARNISH, not the
        main course. At most one small mention, and only if it reinforces the
        identity (e.g. "your easy pace target is around 7:15/km, which fits your
        patient style perfectly"). This page isn't a training dashboard: don't list
        out metrics, don't prescribe a session, don't make VDOT the main subject.

        form_status (current load state: fresh/optimal/fatigued/overreaching) is
        only for tuning the tone of the nudge, not the main subject. If
        fatigued/overreaching, lean the nudge toward recovery, not "keep pushing",
        and don't contradict the recap. If it's missing, ignore it.

        If the user's just getting started (few total runs, thin mix), don't invent
        a big persona. Read it as-is and nudge gently.

        Tone: warm, personal, not generic, not judgmental. Running terms stay as-is
        (pace, cadence, HR, split, easy, tempo). No em dashes.

        ANTI-PATTERN:
        - "You're the type of runner who patiently builds a base. You've also run
          1200 km and have 3 PRs." That's two blocks stitched together: the numbers
          don't explain the persona.
        - Listing numbers in a row with none of them serving as a reason (total km,
          total runs, streak, VDOT, PRs all at once). Pick the one that connects,
          drop the rest.
        - "Your running pattern leans easy-dominant" with no follow-through.
        - A clinical label ("You are a base builder").
        - The same formula every refresh.
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
