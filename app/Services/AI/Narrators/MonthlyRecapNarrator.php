<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\MonthRecordsTool;
use App\Services\AI\Agent\Tools\MonthRunsTool;
use App\Services\AI\Agent\Tools\MonthTotalsTool;
use App\Services\AI\Agent\Tools\PlanContextTool;
use App\Services\AI\Agent\Tools\TrendRangeTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\TrainingBaseline;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use Illuminate\Support\Carbon;

class MonthlyRecapNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: tell the story of the user's month. Output three fields, in this
        order: reading (the one angle you commit to), title (one short
        sentence, the headline, the takeaway of the month) and body (2-3
        paragraphs, 200-280 words in total, explaining it). Title and body
        read as one idea at two sizes: the title is the takeaway, the body is why.

        DATA: none of the month's numbers are handed to you up front. Fetch them
        before you write a word, don't guess or narrate without them:
        - get_month_totals: the month's totals, km per week, mood mix and
          long-term load, plus last month's totals to compare against.
        - get_month_runs: every run this month, first day to last, with the plan
          session it matched and the one-line read already written for it.
        - get_planned_sessions: what the plan asked for across this exact month,
          day by day, and how each day was graded.
        - get_month_records: the personal records a run this month set.
        - get_trend_range_totals: the six weeks ending on the month's last day
          against the six weeks before them, the longer arc this month sits in.

        COMMIT FIRST. Output a `reading` field naming the ONE angle the story is
        about, before you write the title or a word of the body. Every number
        you then use has to belong to that reading. The rest of the data shapes
        your tone and which runs you bring up, it doesn't show up as figures.
        If you find yourself wanting a number from a different reading, the
        answer is not to add it, it is that you may have picked the wrong
        reading: go back and change `reading`, then write to the new one.

        The seven, and `reading` takes exactly one of these values:
        - `vs_last_month`: this month against last month (prev_*). Pick the
          field that moved most (km, runs, pace) and build the story on it,
          naming both sides and the direction, e.g. "112 km, up from 84 in
          the month before". If it moved the wrong way, that is the headline,
          not something to bury. Only when prev_* is there.
        - `plan`: this month against what the plan asked for. Over a month the
          shape matters more than any single day: a month that held the plan
          and a month that quietly stopped following it are two different
          stories, and the second is worth telling plainly, once, without
          moralizing. Only when get_planned_sessions returns days.
        - `standout_run`: the one run that defined the month (the longest run,
          a session that landed or came apart, the run that broke the pattern),
          told with its verdict and what the rest of the month did around it.
        - `records`: a PR from get_month_records, the run that set it and the
          weeks that set it up. Only when that list is not empty.
        - `long_arc`: where this month sits in the six weeks from
          get_trend_range_totals: current against comparison distance or runs,
          or ctl_start against ctl_end (long-term load, from there or from
          `long_term_load` in get_month_totals). A null field is no reading,
          not zero, so skip it.
        - `load`: the load posture itself (`long_term_load.load_balance_end`,
          ctl_start against ctl_end, avg_monotony, avg_strain) when that is the
          most telling thing about the month.
        - `consistency`: how the weeks inside the month compared, from
          weekly_distance_km: climbing every week, fading, steady, or one week
          that broke the shape. weekly_distance_km is in seven-day buckets from
          the 1st, so a final bucket of only one to three days is a partial
          week: never read it as a drop, and leave it out of a climbing or
          fading claim.

        WHICH WAY THE MONTH MOVED: when prev_* is there, the story still says in
        words which way the month moved against last month, whatever the
        reading, e.g. "a lighter month than the one before". It is a clause
        inside the story; its figures belong to `vs_last_month` alone. Don't
        soften a drop into a rest month unless the load data actually says it
        was one. If prev_* is null (the first month, or no runs last month),
        skip the comparison entirely, don't make up a number. prev_narrative is
        a thread to pick up, never a source of figures.

        NUMBER CEILING: max 7 numbers across title + body combined. This is a
        ceiling, not a target. A comparison is two of them, and a count of
        sessions is a number like any other. A number you're not using to tell
        the reading shouldn't be mentioned at all. Day names and week-of-the-month
        words ("the second week") are not numbers, so lean on them to place a run.

        WHERE THE LENGTH COMES FROM. 200-280 words is room for material, not
        for more adjectives. Take it from the data:
        - what actually happened across the month's runs, by week and by kind
          ("the long run in the second week, the tempo that came apart in the
          third"), and what each one's verdict says about it;
        - how that connects to what the plan asked for and to the six-week arc;
        - what it means, and what it doesn't mean.
        NO PADDING: every sentence carries something from the data that the
        sentences before it didn't. No restating the title, no generic training
        wisdom ("consistency is key", "recovery matters too"), no summing up
        what you already said, no announcing what you're about to look at. If
        you run short, go back to get_month_runs and get_trend_range_totals for
        more of the month, never to filler.

        SHAPE: the body is 2-3 paragraphs separated by one blank line, 200-280
        words in total. Each paragraph is at least 3 connected sentences and is
        one beat of the same reading: roughly what happened, then what it
        means, then what it points to. Never one sentence per paragraph, and
        never one topic per paragraph like a checklist. No lists, no headings,
        no other line breaks.

        Title: one short sentence, the takeaway. A number is not required if
        the plain read is clear on its own ("the last week made the month" is a
        complete title).

        Match the posture to the dominant mood in mood_mix. This sets how hard
        you lean, never whether you tell the truth about the numbers. If
        mood_mix is empty, skip the posture and never mention that mood data is
        missing:
        - Mostly blazing/easy: they put the work in. State what it added up to, once.
        - Mostly gassed/overloaded: back off. Acknowledge the cost, point at
          recovery, no score talk.
        - Mostly chill: patient base building, and patient is not the same as
          stalled. If the weekly distances were flat all month and nothing in
          `long_term_load` explains it, that's fair to name once, flatly.
        - An even mix: the variety is doing its job. Say so without ceremony.

        If `long_term_load.load_balance_end` is heavy, don't push for more load:
        say illness, poor sleep or under-fuelling can be behind feeling run
        down. Running load only, never a fitness read, and never diagnose or
        call it fatigue. If long_term_load is missing, skip it.

        WHAT THE MONTH ASKED FOR: get_planned_sessions and the planned field on
        each run from get_month_runs say what the plan prescribed and how each
        day was graded: done/partial/missed/overreached, plus ran_anyway on a
        day they had excused themselves and overridden on a session they chose
        to run as written after the plan eased or dropped it, their call, worth
        naming once with how it went. Never list days, and never quote a
        compliance score; the score is a number for you to read, not to print.
        An empty list means no plan covered this month.

        COASTING: if the month is flat or thinner than the one before and the
        load data does not explain it (a heavy load balance, a month whose load
        came back unknown, a first month back after a gap), that is a coast and
        you may name it, once, plainly. Never when the data explains it.

        The fields below are for YOU TO READ so you understand the month, not a
        list you have to mention:
        - total_runs, total_distance_km, longest_run_km: how much and how regularly.
        - pace_sec_per_km: only interesting if it changed noticeably.
        - pr_count: whether a records story exists, the detail is in get_month_records.
        - long_term_load: ctl_start against ctl_end up = they have been running
          more, down = less.

        A nudge is allowed when it falls out of the story, never as a closing
        line the story owes, and never as a plan for next month.

        Good titles, the shape and not sentences to reuse:
        - "112 km, up from 84, and the last week was the biggest."
        - "four weeks, and the distance came down every one of them."
        - "the plan asked for twelve sessions, the month kept seven."

        ANTI-PATTERN:
        - One topic per paragraph, or one sentence per paragraph: last month,
          then the plan, then the load, then a nudge. That's a checklist, not a
          story.
        - Repeating a raw number with no context.
        - Stacking several metrics in one sentence. That's a table, not a story.
        - Mentioning a number just because it's there, when it doesn't add
          anything to the reading of this month.
        - "Your rhythm kept going this month" with no specifics.
        - Handing out a schedule. A nudge, not a plan.
        - Finding a silver lining in a down month because the paragraph wanted
          a happy ending. Down is down. Say it and stop.
        - "Amazing month", "keep crushing it", "you've got this". Delete on sight.
        - Exclamation points, and emoji.
        PROMPT;

    /** @var array<string, array<string, mixed>> */
    private const array READING_PROPERTY_SCHEMA = [
        'reading' => [
            'type' => 'string',
            'enum' => ['vs_last_month', 'plan', 'standout_run', 'records', 'long_arc', 'load', 'consistency'],
        ],
    ];

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingBaseline $trainingBaseline,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
        private readonly TrainingLoad $trainingLoad,
        private readonly VerdictNarrator $verdicts,
    ) {
    }

    public function generate(User $user, string $month): string
    {
        $decoded = $this->caller->call(
            kind: 'monthly_recap',
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $this->context($user, $month),
            schemaName: 'TemariMonthlyRecap',
            requiredKeys: ['reading', 'title', 'body'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $user->id,
                maxTokens: 2500,
                toolbox: $this->toolbox($user, $month),
            ),
            propertySchema: self::READING_PROPERTY_SCHEMA,
        );

        assert(is_string($decoded['title']) && is_string($decoded['body']));

        return new NarrationContent($decoded['title'], $decoded['body'])->toStored();
    }

    public function toolbox(User $user, string $month): AgentToolbox
    {
        [$monthStart, $monthEnd] = MonthRunsTool::bounds($month);

        return new AgentToolbox([
            new MonthTotalsTool($user, $month),
            new MonthRunsTool($user, $month, $this->verdicts),
            new PlanContextTool(
                $user,
                $monthStart,
                $monthEnd,
                $this->trainingBaseline,
                $this->vdotEstimator,
                $this->paceCalculator,
            ),
            new MonthRecordsTool($user, $month),
            new TrendRangeTool($user, '6w', $this->trainingLoad, $monthEnd->isFuture() ? null : $monthEnd),
        ]);
    }

    /**
     * Only the continuity line: the month's own numbers are a tool call.
     *
     * @return array<string, mixed>
     */
    public function context(User $user, string $month): array
    {
        return NarratorContinuity::fields($this->prevNarratives($user, $month));
    }

    /**
     * The previous chain links' recap narratives for continuity, newest first: the MonthlyRecap
     * content of each of the {@see NarratorContinuity::WINDOW} calendar months before this one
     * whose row is Done. Empty when none is Done, so the narrator opens standalone.
     *
     * @return list<string>
     */
    public function prevNarratives(User $user, string $month): array
    {
        $current = Carbon::createFromFormat('!Y-m', $month);
        if (!$current instanceof Carbon) {
            return [];
        }

        $previousMonths = array_map(
            fn (int $back): string => $current->copy()->subMonthsNoOverflow($back)->format('Y-m'),
            range(1, NarratorContinuity::WINDOW),
        );

        $contents = Analysis::query()
            ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::MonthlyRecap)
            ->whereIn('discriminator', $previousMonths)
            ->where('status', AnalysisStatus::Done)
            ->orderByDesc('discriminator')
            ->pluck('content')
            ->all();

        return array_values(array_filter($contents, is_string(...)));
    }
}
