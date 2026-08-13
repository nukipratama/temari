<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\MonthTotalsTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use Illuminate\Support\Carbon;

class MonthlyRecapNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: 3-4 sentences reading the user's running month. Give room to tell a
        story, but keep it tight, don't ramble.

        DATA: none of the month's numbers are handed to you up front. Call
        get_month_totals to pull them before narrating -- the scope below only
        makes sense once you have it, don't guess or narrate without it.

        Scope: total km + number of runs + longest run + mood distribution
        (blazing/easy/wobbly/gassed/overloaded/chill) + PR count + weekly progress within
        that month.

        THE SCOREBOARD IS THE MONTH'S OWN ARC. You only fetched this month, so the
        comparison lives inside it: weekly_distance_km from the first week to the
        last, and `fitness` ctl_start against ctl_end. Say which way it went and by
        how much. A month that climbed every week and a month that faded are two
        different stories, and the fade is one you're allowed to tell plainly.
        NEVER compare against a previous month's numbers, you never fetched them:
        prev_narrative is a thread to pick up, not a source of figures.

        Expected structure:
        1. Open with a concrete number (total km, number of runs).
        2. Mood narrative (ONLY if mood_mix is populated): which mood dominated and
           what it means. Use the mood_mix data, mention a percentage if it stands
           out (e.g. "60% of your sessions were chill, only 2 were blazing"). If
           mood_mix is empty or missing, SKIP this step silently, go straight to the
           highlight, don't mention that mood data isn't available.
        3. Highlight: longest run, PR count (pr_count) if any, weekly progress from
           weekly_distance_km (e.g. "climbing every week" or "steady around 10 km"),
           or fitness direction from `fitness` (ctl_end vs ctl_start: up = base is
           building, down = fitness is fading). Use whichever stands out most.
        4. Close: 1 short reflection or nudge for next month. If
           `fitness.form_status_end` is overreaching/fatigued, lean toward recovery,
           don't push for more load. If it's missing, skip it.

        Match the posture to the dominant mood. This sets how hard you lean, never
        whether you tell the truth about the numbers:
        - Mostly blazing/easy: they put the work in. State what it added up to, once.
        - Mostly gassed/overloaded: back off. Acknowledge the cost, point at recovery,
          no score talk.
        - Mostly chill: patient base building, and patient is not the same as
          stalled. If the weekly distances were flat all month and nothing in
          `fitness` explains it, that's fair to name once, flatly.
        - An even mix: the variety is doing its job. Say so without ceremony.

        Good examples of the shape, not sentences to reuse:
        - "112 km over 14 runs, and the last week of the month was your biggest.
          you finished going uphill, which is the harder way to end a month."
        - "Four weeks, and the distance came down every single one of them. no PRs,
          no bad days either. it was a quiet month."
        - "60% of your sessions came in chill and your base barely moved. patient is
          a strategy, but a month of it is a long time to stay comfortable."

        ANTI-PATTERN:
        - "Your rhythm kept going this month" with no specifics.
        - The same formula every month.
        - Lecturing, or handing out a schedule.
        - Closing warm out of habit. A flat month gets a flat closing line.
        - "Amazing month", "keep crushing it", "you've got this". Delete on sight.
        - Exclamation points, and emoji.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(User $user, string $month): string
    {
        $context = $this->context($user, $month);

        $decoded = $this->caller->call(
            kind: 'monthly_recap',
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $context,
            schemaName: 'TemariMonthlyRecap',
            requiredKeys: ['narrative'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $user->id,
                maxTokens: 1500,
                toolbox: new AgentToolbox([new MonthTotalsTool($user, $month)]),
                maxSteps: 4,
            ),
        );

        return (string) $decoded['narrative'];
    }

    /**
     * Only the continuity line: the month's own numbers are a tool call.
     *
     * @return array<string, mixed>
     */
    public function context(User $user, string $month): array
    {
        return NarratorContinuity::fields($this->prevNarrative($user, $month));
    }

    /**
     * The previous chain link's recap narrative for continuity: the prior
     * calendar month's MonthlyRecap content, if that row is Done. The monthly
     * chain is keyed by the discriminator month (Y-m) under a single user
     * subject, so "previous" is the calendar month before $month. Returns null
     * when no Done predecessor exists (first ever month, or it is not yet
     * narrated), so the narrator opens standalone. The chain (kickoff +
     * AnalyzeMonthlyRecapJob propagation) guarantees the predecessor is Done
     * before this month narrates, so steady-state always sees the prior thread.
     */
    public function prevNarrative(User $user, string $month): ?string
    {
        $previousMonth = Carbon::createFromFormat('Y-m', $month)
            ?->subMonthNoOverflow()
            ->format('Y-m');

        if ($previousMonth === null) {
            return null;
        }

        return Analysis::query()
            ->forSubject(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, $previousMonth)
            ->where('status', AnalysisStatus::Done)
            ->value('content');
    }


}
