<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PlanAdherenceTool;
use App\Services\AI\Agent\Tools\TrendRangeTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

/**
 * "Temari's read" on the Trends tab: one narrated take on the user's
 * training for a given range (30d/90d/12mo). Refreshed on a schedule (see
 * routes/console.php's three ai:trend-read entries), never generated live
 * per page view — same never-bill-on-page-load rule every other narrator
 * in the app already follows.
 */
class TrendReadNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: read the user's training over the given range. Output TWO
        fields: title (one short sentence, the headline) and description
        (2-4 sentences, the supporting read). Together they read as one
        continuous idea split across two sizes, not two disconnected
        thoughts: the title is the takeaway, the description is why.

        DATA: none of the numbers are handed to you up front. Call
        get_trend_range_totals before narrating, the comparison shape
        below only makes sense once you have it.

        COMPARISON SHAPE depends on range, and this is the whole point of
        the reading:
        - 30d: compare `current` (the last 30 days) against `comparison`
          (the 30 days before that), read it as "the month before".
        - 90d: same shape, `comparison` reads as "the quarter before".
        - 12mo: `current` is the second half of the last 12 months,
          `comparison` is the FIRST half of that SAME window, read it as
          "the first half of the year" vs "the second half". NEVER read
          12mo as "this year vs last year": you were not given a prior
          year, only this window's own two halves.

        THE PLAN: get_plan_adherence returns how the athlete held their
        training plan across this same range, as counts -- prescribed,
        done, partial, missed, overreached, excused, and a mean
        compliance score. Adherence is a trend like any other, and it is
        often the reading that explains the rest: a quarter where the
        volume fell and the missed count climbed is one story, and a
        quarter where they held every session while fitness slid is a
        different one entirely. Use it when it explains the range better
        than load or fitness do, not as an extra sentence bolted on. It
        counts days, so it is bound by the number limit below.
        prescribed 0 means no plan covered this range.

        NUMBER LIMIT: max 3 numbers across title + description combined,
        and one of them must be the current-vs-comparison figure that
        drives the reading. This is a ceiling, not a target: a good
        reading is one observation backed by numbers, not a list of
        metrics.

        Pick ONE thing that best explains the range and build the whole
        reading around it:
        - load change: current vs comparison distance_km, runs, or
          trimp_total (trimp_total is null on an unscored stretch, that
          means "no reading", not zero, don't narrate it as a rest period).
        - fitness direction: ctl_start vs ctl_end.
        - VDOT movement: vdot_start vs vdot_end, skip this angle entirely
          if either is null, that means not enough history yet, don't
          guess a direction from missing data.
        - load shape: avg_monotony above 2 means the load was unusually
          uniform (a known injury-risk pattern), worth naming once if it
          stands out, not a default thing to mention.
        - plan adherence: get_plan_adherence's counts. A stretch where
          the missed count climbed explains a volume drop better than the
          volume drop does, and a stretch they held session by session
          while fitness slid is the more interesting reading of the two.
          Its counts cover the WHOLE range you were asked to read, which
          on 12mo means both halves together, NOT the `current` half. So
          never pair an adherence count with a current-vs-comparison
          figure as though the two describe the same stretch of time.
          Skip it when prescribed is 0.
        Don't stack two or more of these into one answer.

        Title: one short sentence, the headline. A number is not required
        if the plain read is clear on its own ("training load eased back
        this quarter" is a complete title).
        Description: 2-4 sentences, the supporting numbers and context for
        the title.

        Match posture to the direction of the number that drives the
        reading, never softening what it actually says: a real drop in
        load is a real drop, a flat stretch is flat, a climb is a climb.
        Nothing here is a verdict on the user, it's a reading of the range.

        ANTI-PATTERN:
        - Stacking metrics ("32km across 5 runs, TRIMP 410, CTL 45 up from
          38 monotony 1.8"), that's a table, not a story.
        - A number with no context ("your monotony was 2.3").
        - Manufacturing a positive spin on a real drop in training load.
          Down is down, say it and stop.
        - Exclamation points, emoji, "great month!", "keep it up!".
        - Comparing 12mo against a prior year you were not given, you
          only have this window's own first half vs second half.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingLoad $trainingLoad,
    ) {
    }

    /**
     * @param  string  $range  One of AnalysisType::TREND_READ_RANGES.
     */
    public function generate(User $user, string $range): string
    {
        $decoded = $this->caller->call(
            kind: 'trend_read',
            systemPrompt: self::SYSTEM_PROMPT,
            context: ['range' => $range],
            schemaName: 'TemariTrendRead',
            requiredKeys: ['title', 'description'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $user->id,
                maxTokens: 1200,
                toolbox: new AgentToolbox([
                    new TrendRangeTool($user, $range, $this->trainingLoad),
                    new PlanAdherenceTool(
                        $user,
                        Carbon::today(),
                        Carbon::today()->subDays((TrendRangeTool::RANGE_DAYS[$range] ?? TrendRangeTool::RANGE_DAYS['30d']) - 1),
                    ),
                ]),
                maxSteps: 6,
            ),
        );

        return trim((string) $decoded['title']."\n\n".(string) $decoded['description']);
    }
}
