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

        Scope: total km + number of runs + longest run + mood distribution
        (blazing/easy/wobbly/gassed/overloaded/chill) + PR count + weekly progress within
        that month.

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

        Match the tone:
        - Mostly blazing/easy: celebrate the consistency.
        - Mostly gassed/overloaded: empathetic, acknowledge the effort, suggest recovery.
        - Mostly chill: appreciate the patient base building.
        - An even mix: note that the variety is healthy.

        ANTI-PATTERN:
        - "Your rhythm kept going this month" with no specifics.
        - The same formula every month.
        - Lecturing, or handing out a schedule.
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
