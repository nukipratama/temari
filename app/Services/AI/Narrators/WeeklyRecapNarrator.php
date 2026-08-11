<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\AI\Analysis;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\WeekTotalsTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;

class WeeklyRecapNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: 3-4 sentences reading the user's week. Give room to tell a story, but
        keep it tight, don't ramble.

        Scope: sum up this week's VIBE using concrete data. Close with 1 observation
        or a gentle nudge.

        NUMBER LIMIT: max 3 numbers across the ENTIRE output, and one of them should
        be used for the comparison to last week. This is a ceiling, not a target. A
        number you're not using to tell the story shouldn't be mentioned at all. A
        good recap is one reading backed by numbers, not a list of metrics.

        If last week's data (prev_*) is available, you MUST work in 1 concrete
        week-over-week comparison: direction and the difference, e.g. "up 4 km from
        last week", "pace 8 seconds faster", "frequency dropped from 4 to 2 runs".
        If prev_* is null (first week), skip the comparison, don't make up a number.

        Match the tone to form_status:
        - fresh: energetic, encourage making use of it. "You're feeling fresh,
          worth trying a quality session next week."
        - optimal: positive, appreciate the consistency. "The balance is right,
          keep it up."
        - fatigued: empathetic, suggest rest rather than pushing. "This week was
          heavy enough, resting isn't a loss."
        - overreaching: concerned, a gentle warning. "Load's high, ease off a
          little next week."

        The list below is for YOU TO READ so you understand the week, not a list you
        have to mention. Read all of it, then pick the ONE that best explains this
        week and tell that. The rest is just background that shapes the tone, it
        doesn't need to show up as a number:
        - runs, distance_km: how much and how regularly.
        - pace_sec_per_km: only interesting if it changed noticeably.
        - weekly_trimp: weekly load.
        - form (CTL - ATL): positive = fresh, negative = fatigued.
        - monotony: > 2 = too uniform, encourage variety.
        - strain: > 500 = heavy.
        - avg_decoupling: average cardiac drift (%). Low = good aerobic efficiency
          (heart stayed steady through the run); high (above 8-10%) = endurance
          still needs work.

        ANTI-PATTERN:
        - Repeating a raw number with no context.
        - Stacking several metrics in one sentence: "28.4 km across 4 runs, TRIMP
          312, form -8, monotony 1.8." That's a table, not a story.
        - Mentioning a number just because it's there, when it doesn't add anything
          to the reading of this week.
        - "Your rhythm was pretty steady this week" with no specifics.
        - Handing out a schedule ("run 4 times next week"). A nudge, not a plan.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(WeeklySnapshot $snapshot): string
    {
        $decoded = $this->caller->call(
            kind: 'weekly_recap',
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $this->context($snapshot),
            schemaName: 'TemariWeeklyRecap',
            requiredKeys: ['narrative'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $snapshot->user_id,
                maxTokens: 1500,
                toolbox: new AgentToolbox([new WeekTotalsTool($snapshot)]),
                maxSteps: 4,
            ),
        );

        return (string) $decoded['narrative'];
    }

    /**
     * Only the continuity line: the week's own numbers are a tool call.
     *
     * @return array<string, mixed>
     */
    public function context(WeeklySnapshot $snapshot): array
    {
        return NarratorContinuity::fields($this->prevNarrative($snapshot));
    }

    /**
     * The previous chain link's recap narrative for continuity: the most recent
     * earlier week with runs > 0 whose WeeklyRecap is Done. This follows the
     * chain's own definition of "previous" (runs > 0, gap-skipping), not the
     * exact calendar-prior week, so a zero-run week between two running weeks
     * does not sever the thread. Returns null when no such Done predecessor
     * exists (first ever week, or the predecessor not yet narrated), so the
     * narrator opens standalone. The chain (kickoff + AnalyzeWeeklyRecapJob
     * propagation) guarantees the predecessor is Done before this week narrates,
     * so steady-state always sees the prior thread.
     */
    public function prevNarrative(WeeklySnapshot $snapshot): ?string
    {
        $previousLink = WeeklySnapshot::query()
            ->where('user_id', $snapshot->user_id)
            ->where('week_ending', '<', $snapshot->week_ending)
            ->where('runs', '>', 0)
            ->whereHas('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::WeeklyRecap)
                ->where('status', AnalysisStatus::Done))
            ->orderByDesc('week_ending')
            ->first();

        if ($previousLink === null) {
            return null;
        }

        return Analysis::query()
            ->forSubject(WeeklySnapshot::class, $previousLink->id, AnalysisType::WeeklyRecap)
            ->where('status', AnalysisStatus::Done)
            ->value('content');
    }


}
