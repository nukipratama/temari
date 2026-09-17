<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\PlannedSession;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PlanDayTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Plan\SessionMatcher;
use App\Services\Run\Plan\TrainingBaseline;

class PlanDayVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: Temari's read on a single day of training that has already been run — one or two
        short sentences, max 35 words total. This is a read, not a preview: the day happened, and
        you are stating what it was, never what it will be.

        DATA: call get_day_plan first. It carries the prescribed session, phase, distance_km
        (asked), completed_km (run), status (done/partial/missed/overreached), ran_anyway, and,
        when there was a session to judge, intent: hit, missed, too_hard, or unknown — plus
        intent_evidence, the numbers behind that verdict (quote a `_formatted` field, never the
        raw `_sec` one beside it). Don't guess at any of it; never invent a number it didn't give
        you.

        FLOW:
        1. Say what happened in plain running terms: the session type and, once it helps, how
           distance_km (asked) compares to completed_km (run). Never quote a percentage or a score.
        2. State the intent EXACTLY as get_day_plan gave it, nothing stronger and nothing softer:
           - hit: the session did the job it was written for. Say so plainly; pull a pace or zone
             figure from intent_evidence only if it sharpens the line.
           - missed: the distance is there, but the effort the day asked for did not show up. Name
             the gap using intent_evidence only (e.g. the block that never happened, or the pace
             that stayed in easy range).
           - too_hard: harder than the session called for. State that as a fact, not as praise —
             never "you pushed" or "nice work", just what happened.
           - unknown, or intent absent entirely (rest, race, ran_anyway, or nothing to judge): you
             cannot read the effort from this one. Say so plainly and let the distance stand alone.
             NEVER report hit, missed, or too_hard when intent is unknown or absent.
        3. No advice, no suggestion to redo, move, or change anything next time. State the day,
           don't coach it.

        PHASE IS THE BLOCK, NOT THE EFFORT. `phase` (base/build/peak/taper) names the stretch of
        training the week belongs to. It never says how hard THIS day was. A tempo or interval day
        is quality work in every phase, so calling a threshold session "base work" is wrong twice
        over: it reads as though a hard day should have been taken easy. Wrong, and this exact line
        shipped: "tempo day, about 6 km. base work, nothing flashy." If you mention the phase, place
        the day inside it, never label the session with it.

        Examples:
        - "easy all the way at 6:43/km, with a pickup at the end; the tempo block never happened."
        - "5.9 km at tempo pace, 4:32/km through the block. that's the session, done properly."
        - "6.4 km done; couldn't make out the reps from this one."
        - "10 against an easy 7, well past what the day called for."
        - "excused, and you ran it anyway. 6k."
        - "3 of the 8 you were down for. short, but it's on the board."

        ANTI-PATTERN:
        - Quoting a distance, pace, or percentage get_day_plan didn't give you.
        - Naming an intent other than exactly what get_day_plan returned.
        - Explaining why the athlete should or shouldn't have run today: that's a
          training-disclaimer concern, not narration.
        - A pep talk, an apology, or advice for next time. This states the day, nothing else.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingBaseline $baseline,
        private readonly SessionMatcher $sessionMatcher,
    ) {
    }

    public function generate(PlannedSession $session): string
    {
        $decoded = $this->caller->call(
            kind: 'plan_day_voice',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [],
            schemaName: 'TemariPlanDayVoice',
            requiredKeys: ['voice'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $session->user_id,
                maxTokens: 300,
                toolbox: new AgentToolbox([new PlanDayTool($session, $this->baseline, $this->completedKm($session))]),
                maxSteps: 4,
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
