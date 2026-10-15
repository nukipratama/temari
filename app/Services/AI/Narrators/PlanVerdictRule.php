<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

/**
 * The one effort verdict the post-run speech, the run insight and the post-run
 * briefing all repeat, so the blocks a runner reads about one run never
 * disagree on whether it went as asked. See
 * `docs/decisions/run-narrators-share-the-days-verdict.md`.
 */
final class PlanVerdictRule
{
    public const string RULE = <<<'PROMPT'
        THE DAY'S VERDICT: once a planned day was judged, get_planned_sessions
        carries `intent` (the verdict in plain words) and `intent_detail` (the
        numbers behind it). Code made that call against the part of the day the
        session was written for: the hard block or the reps on a tempo or
        interval day, the whole effort on an easy or long day, across every run
        that day. Every block about this run repeats that one verdict.
        - Never contradict it, and never soften it into its opposite. If intent
          says the run went harder than asked, never call it easier, and the
          other way round.
        - Never compare the run's overall average pace with target_pace_sec or
          target_pace_formatted. On a tempo or interval day that target is for
          the hard work only; the warm-up and cool-down sit outside it. When you
          need a number for how the session went, take it from intent_detail.
        - eased_from means readiness eased the day from that session to the
          one shown. Say so once, in plain words ("the plan eased today's tempo
          to an easy run"). When intent says the original session was completed
          anyway, name that too: the athlete ran the session the plan stepped
          back from.
        - On an easy or long day intent judges the effort the day asked for.
          Heart rate sitting in Z3 on a hot day can stand beside an easy verdict:
          say the heart rate ran hot while the effort stayed easy, never that the
          day stopped being easy, unless intent says it ran harder than asked.
        - completed_km covers every run that day. When this run is one of
          several, say what the day came to, not this run on its own.
        - A null intent means the day isn't judged yet, and an intent that says
          the data can't tell means just that. Either way, hand down no verdict
          of your own on whether the effort was too hard or too easy.
        PROMPT;

    /** The prompt with the rule set inside its own plan block, where the model reads it. */
    public static function into(string $prompt): string
    {
        return str_replace('{plan_verdict}', self::RULE, $prompt);
    }
}
