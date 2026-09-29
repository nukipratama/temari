<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Enums\IngestState;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\RunQuestion;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\EffortContextTool;
use App\Services\AI\Agent\Tools\GetThreadTool;
use App\Services\AI\Agent\Tools\HrZonesTool;
use App\Services\AI\Agent\Tools\KmSplitsTool;
use App\Services\AI\Agent\Tools\LapsTool;
use App\Services\AI\Agent\Tools\PlanContextTool;
use App\Services\AI\Agent\Tools\RecentBaselineTool;
use App\Services\AI\Agent\Tools\RunSummaryTool;
use App\Services\AI\Agent\Tools\TerrainTool;
use App\Services\AI\Agent\Tools\TrainingLoadTool;
use App\Services\AI\Agent\Tools\TrainingPacesTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\RunQuestion\RunQuestionSeeds;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Support\Carbon;

/**
 * Answers one question about one run.
 *
 * Scope is enforced by construction, not by instruction: every tool on
 * {@see self::toolbox()} is bound to this activity (or to its owner's history
 * as of this run) and takes no arguments, so no phrasing of a question can
 * reach another run or another account. The prompt below shapes the answer; the
 * toolbox is what makes the boundary real.
 */
class RunQuestionNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: answer the one question the user asked about the one run in front
        of you. Two to four sentences, prose, no lists.

        THREAD: this may not be the first question about this run. When the
        question refers back to something earlier (it, that, why, "what about",
        "and the second half"), call get_thread first and answer in the context
        of what was already said. Build on an earlier answer, never repeat it.

        DATA: the numbers are not handed to you. Fetch what the question needs
        through the tools, and if a result suggests a second read would answer
        better, make it before writing. NEVER state a number you did not fetch.

        SCOPE: you can see this run and the athlete's own history as of this
        run, and nothing else. If the question is about a different run, another
        person, or something outside running, say plainly and briefly that it is
        not what you are looking at, and answer nothing else. Do not speculate
        past your reads to be helpful.

        ANSWER THE QUESTION ASKED. Not the question you would rather answer, and
        not a general tour of the run. If the honest answer is short, it is
        short. Lead with the answer, then the number that backs it.

        KEEP SCORE: wherever a real comparison exists, put a direction on the
        reading -- this run against the 28-day baseline, this session's load
        against the same window, one km against another. Name the number, name
        which way it moved. When it moved the wrong way, say so.

        NO DATA: if the reads come back without what the question needed, answer
        from the closest thing you did fetch and let the rest go. Never tell
        them a number is missing, never narrate your own reads, never apologise
        for what you could not see.

        THE PLAN: get_planned_sessions returns what the plan asked of them on
        the day of this run, and how the day was graded. "was this what I was
        meant to run" is inside your scope, so answer it from that tool rather
        than guessing. Reporting what the plan already prescribed is not
        prescribing: quoting the target pace it set is fine, inventing one is
        not. An empty list means no plan covered that day.

        ATHLETE-SUPPLIED CONTEXT: statements the athlete gives about sleep,
        illness, stress, schedule, or another condition are context they
        supplied, not excuses for you to judge. Accept the stated condition,
        never question it, call it an excuse, invent a measurement, or make a
        diagnosis. Keep measured facts from the run separate from an
        unproven cause. For example, "Ga tidur malam" and "Ini lari dengan
        kondisi ga tidur malam" mean insufficient or no overnight sleep, not a
        night run. "I didn't sleep last night" and "I ran on no sleep" mean the
        same thing. The answer may stay in English.

        NEVER: prescribe a session, a distance or a pace of your own. Never
        diagnose an injury. Never end on a motivational line.

        FOLLOW-UPS: follow_ups holds zero to two short questions, each under ten
        words, written the way the athlete would type them, that this run's data
        can answer and that nobody has asked yet in this thread. Leave it empty
        when nothing worth asking is left.
        PROMPT;

    private const array FOLLOW_UPS_PROPERTY_SCHEMA = [
        'follow_ups' => ['type' => 'array', 'items' => ['type' => 'string']],
    ];

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingLoad $trainingLoad,
        private readonly ResolveRunBaselineAction $baseline,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $trainingPaceCalculator,
        private readonly RelativeEffort $relativeEffort,
        private readonly TrainingBaseline $trainingBaseline,
    ) {
    }

    /**
     * @return array{answer: string, follow_ups: list<string>}
     */
    public function generate(Activity $activity, ActivityDetail $detail, RunQuestion $question): array
    {
        $decoded = $this->caller->call(
            kind: 'run_question',
            systemPrompt: self::SYSTEM_PROMPT,
            context: ['question' => $question->question],
            schemaName: 'TemariRunQuestion',
            requiredKeys: ['answer', 'follow_ups'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $activity->user_id,
                maxTokens: 1200,
                toolbox: $this->toolbox($activity, $detail, $question->id),
            ),
            propertySchema: self::FOLLOW_UPS_PROPERTY_SCHEMA,
        );

        return [
            'answer' => (string) $decoded['answer'],
            'follow_ups' => self::followUps($decoded['follow_ups']),
        ];
    }

    /** @return list<string> */
    private static function followUps(mixed $raw): array
    {
        $questions = array_filter(
            array_map(fn (mixed $item): string => is_string($item) ? trim($item) : '', (array) $raw),
            fn (string $item): bool => $item !== '',
        );

        return array_slice(array_values($questions), 0, RunQuestionSeeds::MAX_FOLLOW_UPS);
    }

    /**
     * The reads this answer may pull, each bound to this activity.
     *
     * A summary-state run has never been through the stream pipeline, so the
     * splits, laps, zone and terrain reads have nothing behind them and are left
     * off entirely rather than offered as tools that answer `{}`. What survives
     * is the run's own summary numbers and the history reads, which stand on
     * their own. Opening the run queues the detail fetch, so a question asked in
     * that window answers from the smaller toolbox and a later one answers from
     * the full set.
     */
    public function toolbox(Activity $activity, ActivityDetail $detail, int $answeringQuestionId): AgentToolbox
    {
        $asOf = $detail->start_date_local ?? Carbon::now();
        $thread = new GetThreadTool($activity, $answeringQuestionId);

        $history = [
            new TrainingLoadTool($activity->user, $asOf, $this->trainingLoad),
            new RecentBaselineTool($activity->user, $asOf, $this->baseline, $activity->id),
            new TrainingPacesTool($activity->user, $asOf, $this->vdotEstimator, $this->trainingPaceCalculator),
            new PlanContextTool($activity->user, $asOf, $asOf, $this->trainingBaseline, $this->vdotEstimator, $this->trainingPaceCalculator),
        ];

        if ($activity->ingest_state !== IngestState::Detailed) {
            return new AgentToolbox([new RunSummaryTool($activity, $detail), $thread, ...$history]);
        }

        return new AgentToolbox([
            new RunSummaryTool($activity, $detail),
            $thread,
            new KmSplitsTool($activity, $detail),
            new LapsTool($activity, $detail),
            new HrZonesTool($activity, $detail),
            new TerrainTool($activity, $detail),
            new WeatherTool($activity, $detail),
            new EffortContextTool($activity, $detail, $this->relativeEffort),
            ...$history,
        ]);
    }
}
