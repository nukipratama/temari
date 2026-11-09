<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Actions\Run\Plan\ResolveSeasonAction;
use App\Models\AI\Analysis;
use App\Models\AI\AnalysisVersion;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\User;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\AnalysisType;
use App\Services\AI\Agent\Tools\PlanAdherenceTool;
use App\Services\AI\Agent\Tools\RaceOutlookTool;
use App\Services\AI\Agent\Tools\TrendRangeTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\AI\TrendReadFingerprint;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Plan\RacePresenter;
use App\Services\Run\Plan\SupportedTimeHistory;
use Illuminate\Support\Carbon;

/**
 * "Temari's read" on the Trends tab: the closed weeks toward the active race, or with no race the
 * last six closed weeks against the six before. Refreshed once a week closes or the race changes
 * (see TrendReadFingerprint), never generated live per page view.
 */
class TrendReadNarrator
{
    private const string SHARED_RULES = <<<'PROMPT'
        NUMBER CEILING: max 7 numbers across title + body combined. This is a
        ceiling, not a target. A comparison is two of them, a time like 1:52:30
        is one, and a count of runs or weeks is a number like any other. A
        number you're not using to tell the reading shouldn't be mentioned at
        all.

        WHERE THE LENGTH COMES FROM. 200-250 words is room for material, not
        for more adjectives. Take it from the data: what the weeks actually
        did, what that means, what it doesn't mean, and what would change it.
        NO PADDING: every sentence carries something from the data that the
        sentences before it didn't. No restating the title, no generic
        training wisdom ("consistency is key", "trust the process"), no
        summing up what you already said, no announcing what you're about to
        look at. If you run short, go back to the tools for more of the weeks,
        never to filler.

        SHAPE: the body is 2-3 paragraphs separated by one blank line, 200-250
        words in total. Each paragraph is at least 3 connected sentences and is
        one beat of the same reading: roughly what the weeks did, then what it
        means, then what it points to. Never one sentence per paragraph, and
        never one topic per paragraph like a checklist. No lists, no headings,
        no other line breaks.

        Title: one short sentence, the takeaway. A number is not required if
        the plain read is clear on its own.

        WORDS FOR THE DATA: long-term load is their running load averaged over
        about six weeks, running only, so say it that way and never call it
        fitness or fatigue. avg_monotony above 2 means the load was unusually
        uniform: call it monotony in your words, and only when it stands out.
        Field names never reach the reader.

        NULLS ARE UNKNOWN, NOT ZERO: trimp_total is null on an unscored
        stretch, which means no reading, not a rest period. A null start or end
        on any figure means not enough history yet: skip that angle, don't
        guess a direction from missing data, and don't announce the gap either.

        Match posture to the direction of the number that drives the reading,
        never softening what it actually says: a real drop is a real drop, a
        flat stretch is flat, a climb is a climb. Nothing here is a verdict on
        the user, it's a reading of the weeks.

        ANTI-PATTERN:
        - One topic per paragraph, or one sentence per paragraph: volume, then
          the plan, then the load. That's a checklist, not a story.
        - Stacking metrics ("32 km across 5 runs, TRIMP 410, long-term load 45
          up from 38, monotony 1.8"), that's a table, not a story.
        - A number with no context ("your monotony was 2.3").
        - Manufacturing a positive spin on a real drop. Down is down, say it
          and stop.
        - Handing out a schedule ("run four times next week"). A pointer that
          falls out of the story, never a plan.
        - Exclamation points, emoji, "great block!", "keep it up!".
        PROMPT;

    private const string ARC_TASK = <<<'PROMPT'
        Task: read the user's last six closed weeks against the six before
        them. Output three fields, in this order: reading (the one angle you
        commit to), title (one short sentence, the headline, the takeaway of
        the last six weeks) and body (2-3 paragraphs, 200-250 words in total,
        explaining it). Title and body read as one idea at two sizes: the title
        is the takeaway, the body is why.

        DATA: none of the numbers are handed to you up front. Fetch them before
        you write a word, don't guess or narrate without them:
        - get_trend_range_totals: the six closed weeks ending last Sunday
          (`current`) against the six weeks before them (`comparison`), with
          long-term load at the start and end of the six.
        - get_plan_adherence: how they held the plan across the same six weeks,
          as counts.

        COMMIT FIRST. Output a `reading` field naming the ONE angle the story is
        about, before you write the title or a word of the body. Every number
        you then use has to belong to that reading. The rest of the data shapes
        your tone, it doesn't show up as figures. If you find yourself wanting
        a number from a different reading, the answer is not to add it, it is
        that you may have picked the wrong reading: go back and change
        `reading`, then write to the new one.

        The three, and `reading` takes exactly one of these values:
        - `volume_arc`: current against comparison distance_km or runs: the six
          weeks got bigger, smaller or held, and by how much.
        - `load_direction`: ctl_start against ctl_end, the long-term load across
          the six weeks, or current against comparison trimp_total: a build, a
          plateau or a slide.
        - `adherence`: get_plan_adherence's counts. A stretch where the missed
          count climbed explains a volume drop better than the volume drop
          does, and a stretch they held session by session while long-term load
          slid is the more interesting reading of the two. The counts cover the
          six recent weeks only, so never pair one with a comparison figure as
          though the two describe the same stretch. Only when prescribed is
          above 0.

        Good titles, the shape and not sentences to reuse:
        - "six weeks bigger than the six before, and the long-term load followed."
        - "the plan held session by session while the volume eased off."
        - "the long-term load slid for six weeks straight."
        PROMPT;

    private const string RACE_TASK = <<<'PROMPT'
        Task: read whether the user is on track for race day. Output three
        fields, in this order: reading (the one angle you commit to), title
        (one short sentence, the headline, the answer to "on track for race
        day?") and body (2-3 paragraphs, 200-250 words in total, explaining
        it). Title and body read as one idea at two sizes: the title is the
        takeaway, the body is why.

        DATA: none of the numbers are handed to you up front. Fetch them before
        you write a word, don't guess or narrate without them:
        - get_race_outlook: the race, the weeks left, the target time against
          the time their recent running supports, and which way that supported
          time has moved this season.
        - get_trend_range_totals: the six closed weeks ending last Sunday
          (`current`) against the six weeks before them (`comparison`), with
          long-term load at the start and end of the six.
        - get_plan_adherence: how they held the plan across this build, as
          counts.

        COMMIT FIRST. Output a `reading` field naming the ONE angle the story is
        about, before you write the title or a word of the body. Every number
        you then use has to belong to that reading. The rest of the data shapes
        your tone, it doesn't show up as figures. If you find yourself wanting
        a number from a different reading, the answer is not to add it, it is
        that you may have picked the wrong reading: go back and change
        `reading`, then write to the new one.

        The five, and `reading` takes exactly one of these values:
        - `on_track`: the plain answer from ambition and the weeks left, told
          with what it means at this distance from race day. ambition is
          already decided: on_track, ambitious, unsupported, low_evidence or
          unknown. low_evidence and unknown mean the evidence is thin, not that
          the target is wrong: say what kind of run would settle it.
        - `gap_to_target`: target_time against supported_time, the size of the
          gap and what it asks of the weeks left. Only when supported_time is
          there.
        - `support_trend`: which way the supported time moved this season, from
          support_trend's from to its to, and how big the gap to the target
          still is. No projection of where it ends up. Only when support_trend
          is there.
        - `adherence`: get_plan_adherence's counts across the build: what was
          prescribed and how much came back done, partial or missed, and what
          that says about the race the build is preparing. The counts cover the
          whole build, so never pair one with a six-week figure as though the
          two describe the same stretch. Only when prescribed is above 0.
        - `load_direction`: ctl_start against ctl_end, the long-term load across
          the six closed weeks: a build heading into the race, a plateau, or a
          slide away from it.

        RACE RULES:
        - supported_time is what their recent running supports today, not a
          prediction and not a promise. Never forecast a finish time.
        - gap.relation target_faster means the target asks for more than recent
          running supports; target_slower means it asks for less. gap.pct is
          only the size.
        - dedicated_preparation false means the distance is past what the plan
          prepares for, so there is no supported time to read: tell the race
          through the weeks left, the load and the plan, never a gap.
        - Inside the last two weeks the work is mostly banked: read what the
          build put away, don't ask for more of it.
        - Name the distance or the race day, never the race's name.

        Good titles, the shape and not sentences to reuse:
        - "eight weeks out, and the target still asks for more than the running shows."
        - "the supported time came down every month of this build."
        - "on track, with the long run doing most of the work."
        PROMPT;

    private const string SYSTEM_PROMPT = self::ARC_TASK."\n\n".self::SHARED_RULES."\n\n".NarratorContinuity::RULE;

    private const string RACE_SYSTEM_PROMPT = self::RACE_TASK."\n\n".self::SHARED_RULES."\n\n".NarratorContinuity::RULE;

    /** @var array<string, array<string, mixed>> */
    private const array READING_PROPERTY_SCHEMA = [
        'reading' => [
            'type' => 'string',
            'enum' => ['volume_arc', 'load_direction', 'adherence'],
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private const array RACE_READING_PROPERTY_SCHEMA = [
        'reading' => [
            'type' => 'string',
            'enum' => ['on_track', 'gap_to_target', 'support_trend', 'adherence', 'load_direction'],
        ],
    ];

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingLoad $trainingLoad,
        private readonly ResolveActiveRaceAction $activeRace,
        private readonly ResolveSeasonAction $seasons,
        private readonly RacePresenter $racePresenter,
        private readonly SupportedTimeHistory $supportedHistory,
    ) {
    }

    public function generate(User $user): string
    {
        $race = ($this->activeRace)($user->id);
        $through = TrendReadFingerprint::closedThrough();

        $decoded = $this->caller->call(
            kind: 'trend_read',
            systemPrompt: $race instanceof RaceGoal ? self::RACE_SYSTEM_PROMPT : self::SYSTEM_PROMPT,
            context: [
                'closed_weeks_through' => $through->toDateString(),
                ...NarratorContinuity::fields($this->previousReads($user)),
            ],
            schemaName: 'TemariTrendRead',
            requiredKeys: ['reading', 'title', 'body'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $user->id,
                maxTokens: 2500,
                toolbox: $this->toolbox($user, $race, $through),
                maxSteps: 8,
            ),
            propertySchema: $race instanceof RaceGoal ? self::RACE_READING_PROPERTY_SCHEMA : self::READING_PROPERTY_SCHEMA,
        );

        assert(is_string($decoded['title']) && is_string($decoded['body']));

        return new NarrationContent($decoded['title'], $decoded['body'])->toStored();
    }

    /**
     * The athlete's earlier trend reads, newest first: the one row's content, which still holds the
     * last read while a regeneration runs, then the versions it superseded.
     *
     * @return list<string>
     */
    public function previousReads(User $user): array
    {
        $row = Analysis::query()
            ->forSubject(AnalysisType::TREND_READ_SUBJECT_TYPE, $user->id, AnalysisType::TrendRead, AnalysisType::TREND_READ_RANGES[0])
            ->first(['id', 'content']);

        if ($row === null) {
            return [];
        }

        $versions = AnalysisVersion::query()
            ->where('analysis_id', $row->id)
            ->orderByDesc('id')
            ->limit(NarratorContinuity::WINDOW)
            ->pluck('content')
            ->all();

        return array_values(array_filter([$row->content, ...$versions], is_string(...)));
    }

    public function toolbox(User $user, ?RaceGoal $race, Carbon $through): AgentToolbox
    {
        $trend = new TrendRangeTool($user, '6w', $this->trainingLoad, $through);

        if (! $race instanceof RaceGoal) {
            return new AgentToolbox([
                $trend,
                new PlanAdherenceTool($user, $through, TrendReadFingerprint::windowStart($through)),
            ]);
        }

        $season = $this->seasons->latest($user->id);
        $buildStart = $season instanceof Season && $season->race_goal_id === $race->id
            ? $season->starts_at
            : TrendReadFingerprint::windowStart($through);

        return new AgentToolbox([
            new RaceOutlookTool($user, Carbon::today(), $race, $this->racePresenter, $this->supportedHistory),
            $trend,
            new PlanAdherenceTool($user, $through, $buildStart),
        ]);
    }
}
