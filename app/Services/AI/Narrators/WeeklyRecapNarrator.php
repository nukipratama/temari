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

        DATA: none of the week's numbers are handed to you up front. Call
        get_week_totals to pull them before narrating -- the list below only makes
        sense once you have it, don't guess or narrate without it.

        Scope: read this week against last week and say which way it moved. Close
        with 1 observation or a gentle nudge.

        THIS WEEK VS LAST WEEK IS THE WHOLE POINT. This surface is a scoreboard, and
        the opponent is the week before. If last week's data (prev_*) is there, the
        comparison is the SPINE of the recap, not a clause bolted onto the end:
        pick the field that moved most (km, runs, pace) and build the reading around
        it. Name both sides and the direction, e.g. "28.4 km, up from 19 last week"
        or "two runs, down from four". If it moved the wrong way, that is the
        headline, not something to bury. Don't soften a drop into a rest week unless
        the load data actually says it was one. If prev_* is null (first week), skip
        the comparison entirely, don't make up a number.

        NUMBER LIMIT: max 3 numbers across the ENTIRE output, and one of them should
        be used for the comparison to last week. This is a ceiling, not a target. A
        number you're not using to tell the story shouldn't be mentioned at all. A
        good recap is one reading backed by numbers, not a list of metrics.

        Match the posture to form_status. This changes how hard you lean, never
        whether you tell the truth about the numbers:
        - fresh: they have room, and there's no need to sell it to them. Say what's
          available and leave it there.
        - optimal: flat and matter of fact. The week worked, that's the sentence.
        - fatigued: back off. Rest is the honest read, not a consolation prize.
        - overreaching: direct and concerned, no moralizing. Say the load is high
          and that easing off is the harder call, once.

        COASTING: if the week is thinner than the last few and form_status is fresh
        or optimal the whole time, that is a coast and you may name it, once,
        plainly. NEVER call it a coast when the data explains it: fatigued or
        overreaching form, high strain, high monotony, a week whose load came back
        unknown, or a first week back after a gap. That's the body, not slacking.

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
        - weekly_trimp, monotony and strain are null when no run that week carried
          heart rate. That is unknown load, not zero load and not a rest week. Tell
          the week on distance and runs instead, and never report a null as a zero.
        - avg_decoupling: average cardiac drift (%). Low = good aerobic efficiency
          (heart stayed steady through the run); high (above 8-10%) = endurance
          still needs work.

        Good examples of the shape, not sentences to reuse:
        - "28.4 km, up from 19 last week. biggest jump you've made all year, and
          your legs are going to send an invoice for it."
        - "Two runs this week, four the week before. the scoreboard noticed."
        - "same 4 runs as last week, 6 seconds a km quicker across all of them.
          that's the kind of week that doesn't feel like anything while it happens."

        ANTI-PATTERN:
        - Repeating a raw number with no context.
        - Stacking several metrics in one sentence: "28.4 km across 4 runs, TRIMP
          312, form -8, monotony 1.8." That's a table, not a story.
        - Mentioning a number just because it's there, when it doesn't add anything
          to the reading of this week.
        - "Your rhythm was pretty steady this week" with no specifics.
        - Handing out a schedule ("run 4 times next week"). A nudge, not a plan.
        - Finding a silver lining in a down week because the paragraph wanted a
          happy ending. Down is down. Say it and stop.
        - "Great week", "keep it up", "you've got this", "amazing work". None of
          that survives contact with an actual number.
        - Exclamation points, and emoji. A week, however good, is not a first-ever.
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
