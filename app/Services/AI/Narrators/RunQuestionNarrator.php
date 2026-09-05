<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Enums\IngestState;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\EffortContextTool;
use App\Services\AI\Agent\Tools\HrZonesTool;
use App\Services\AI\Agent\Tools\KmSplitsTool;
use App\Services\AI\Agent\Tools\LapsTool;
use App\Services\AI\Agent\Tools\RecentBaselineTool;
use App\Services\AI\Agent\Tools\RunSummaryTool;
use App\Services\AI\Agent\Tools\TerrainTool;
use App\Services\AI\Agent\Tools\TrainingLoadTool;
use App\Services\AI\Agent\Tools\TrainingPacesTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
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

        NEVER: prescribe a session, a distance or a pace. Never diagnose an
        injury. Never end on a motivational line.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingLoad $trainingLoad,
        private readonly ResolveRunBaselineAction $baseline,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $trainingPaceCalculator,
        private readonly RelativeEffort $relativeEffort,
    ) {
    }

    public function generate(Activity $activity, ActivityDetail $detail, string $question): string
    {
        $decoded = $this->caller->call(
            kind: 'run_question',
            systemPrompt: self::SYSTEM_PROMPT,
            context: ['question' => $question],
            schemaName: 'TemariRunQuestion',
            requiredKeys: ['answer'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $activity->user_id,
                maxTokens: 1200,
                toolbox: $this->toolbox($activity, $detail),
            ),
        );

        return (string) $decoded['answer'];
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
    public function toolbox(Activity $activity, ActivityDetail $detail): AgentToolbox
    {
        $asOf = $detail->start_date_local ?? Carbon::now();

        $history = [
            new TrainingLoadTool($activity->user, $asOf, $this->trainingLoad),
            new RecentBaselineTool($activity->user, $asOf, $this->baseline, $activity->id),
            new TrainingPacesTool($activity->user, $asOf, $this->vdotEstimator, $this->trainingPaceCalculator),
        ];

        if ($activity->ingest_state !== IngestState::Detailed) {
            return new AgentToolbox([new RunSummaryTool($activity, $detail), ...$history]);
        }

        return new AgentToolbox([
            new RunSummaryTool($activity, $detail),
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
