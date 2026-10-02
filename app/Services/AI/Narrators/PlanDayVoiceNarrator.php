<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\PlannedSession;
use App\Services\AI\Agent\Tools\PlanDayTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\SessionMatcher;
use App\Services\Run\Plan\TrainingBaseline;

class PlanDayVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: Temari's read on a single day of training that has already been run — one or two
        short sentences, max 35 words total. This is a read, not a preview: the day happened, and
        you are stating what it was, never what it will be.

        DATA: the day's plan is in the context. It carries the prescribed session, phase, distance_km
        (asked), completed_km (run), status (done/partial/missed/overreached), ran_anyway, and,
        when there was a session to judge, intent: plain words for whether the session did the
        job it was written for, already decided — plus intent_detail, the evidence behind it as a
        sentence with every comparison already worded. Don't guess at any of it; never invent a
        number it didn't give you.

        FLOW:
        1. Say what happened in plain running terms: the session type and, once it helps, how
           distance_km (asked) compares to completed_km (run). Never quote a compliance
           percentage or a score.
        2. Say what intent says, in your own words, nothing stronger and nothing softer. Its
           direction is settled: never turn it around, and never re-judge it from the numbers.
           - Pull a pace or heart-rate figure from intent_detail only if it sharpens the line,
             and keep it on the same side intent_detail puts it ("quicker than", "slower than",
             "only 2 of 5"). The pace to quote is the first one intent_detail gives; mention the
             one effort-adjusted for hills only as that, never as the pace they ran.
           - Harder than the day asked for: state it as a fact, not as praise — never "you
             pushed" or "nice work", just what happened.
           - intent says the data can't tell, or intent is absent entirely (rest, race,
             ran_anyway, or nothing to judge): you cannot read the effort from this one. Say so
             plainly and let the distance stand alone. Never claim the session did or didn't do
             its job.
           - Never write a label for the outcome ("intent hit", "too_hard", "the hit mark"):
             describe what the run did.
        3. No advice, no suggestion to redo, move, or change anything next time. State the day,
           don't coach it.
        4. Plain text only: no markdown, no bold, no asterisks or underscores for emphasis.

        PHASE IS THE BLOCK, NOT THE EFFORT. `phase` (base/build/peak/taper) names the stretch of
        training the week belongs to. It never says how hard THIS day was. A tempo or interval day
        is quality work in every phase, so calling a threshold session "base work" is wrong twice
        over: it reads as though a hard day should have been taken easy. Wrong, and this exact line
        shipped: "tempo day, about 6 km. base work, nothing flashy." If you mention the phase, place
        the day inside it, never label the session with it.

        Examples:
        - "easy all the way at 6:43/km, with a pickup at the end; the tempo block never happened."
        - "8 easy km at 7:22/km, kept properly easy the whole way."
        - "5.9 km at tempo pace, 4:32/km through the block. that's the session, done properly."
        - "6.4 km done; couldn't make out the reps from this one."
        - "10 against an easy 7, well past what the day called for."
        - "excused, and you ran it anyway. 6k."
        - "3 of the 8 you were down for. short, but it's on the board."

        ANTI-PATTERN:
        - Quoting a distance, pace, or percentage the context didn't give you.
        - Saying the day missed when intent says it did the job, or the reverse. This exact line
          shipped on an easy day that stayed easy: "the pace sat at 7:29/km, so it missed the hit
          mark."
        - Quoting the hill-adjusted pace as the pace they ran.
        - Explaining why the athlete should or shouldn't have run today: that's a
          training-disclaimer concern, not narration.
        - A pep talk, an apology, or advice for next time. This states the day, nothing else.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingBaseline $baseline,
        private readonly SessionMatcher $sessionMatcher,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
    ) {
    }

    public function generate(PlannedSession $session): string
    {
        $decoded = $this->caller->call(
            kind: 'plan_day_voice',
            systemPrompt: self::SYSTEM_PROMPT,
            context: new PlanDayTool(
                $session,
                $this->baseline,
                $this->vdotEstimator,
                $this->paceCalculator,
                $this->completedKm($session),
                $session->status->isCredited() ? $this->sessionMatcher->ranPaceSecPerKmFor($session) : null,
            )->handle([]),
            schemaName: 'TemariPlanDayVoice',
            requiredKeys: ['voice'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $session->user_id,
                maxTokens: 300,
                validator: static fn (array $answer): ?string => OutcomeLabels::complaint((string) $answer['voice'], 'voice', plainText: true),
            ),
        );

        return (string) $decoded['voice'];
    }

    /**
     * Km run on the day, and only once it is graded: an ungraded day has no
     * outcome to read, so the query is skipped rather than answered with a
     * figure the tool then withholds.
     *
     * Delegates the day-total-vs-longest-run rule to {@see SessionMatcher},
     * which is where the score itself gets it. Re-deriving it here would put
     * the same rule in two places and let the narration quote a figure the
     * verdict was never computed from.
     */
    private function completedKm(PlannedSession $session): ?float
    {
        return $session->status->isCredited()
            ? $this->sessionMatcher->creditedKmFor($session)
            : null;
    }
}
