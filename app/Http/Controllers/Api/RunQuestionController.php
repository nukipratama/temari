<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AskRunQuestionRequest;
use App\Http\Resources\RunQuestionResource;
use App\Jobs\AI\AnswerRunQuestionJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\RunQuestion;
use App\Models\User;
use App\Services\AI\NarrationGate;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\CostCeilingLedger;
use App\Services\AI\RunQuestion\RuleBasedRunAnswer;
use App\Services\AI\RunQuestion\RunQuestionSeeds;
use App\Services\AI\RunQuestion\RunQuestionTopic;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * "Ask about this run" — a conversation about one run and nothing else.
 *
 * A question is bound to one activity at the door and stays bound: the answering
 * toolbox is built from that activity alone, so there is no phrasing that widens
 * it. Answers are generated off the queue and polled from {@see self::index()},
 * because a tool-calling run takes several Azure round trips and can block on the
 * outbound throttle far longer than an HTTP request should live.
 */
class RunQuestionController extends Controller
{
    public function index(Request $request, NarrationGate $gate, int $activity): JsonResponse
    {
        $user = $this->user($request);
        [, $detail] = $this->ownedRun($user, $activity);

        return response()->json([
            'questions' => RunQuestionResource::collection(
                $gate->shouldServeRuleBased($user)
                    ? $this->seededDemoThread($activity, $detail)
                    : RunQuestion::query()->forActivity($activity)->get(),
            ),
            'suggestions' => array_map(
                fn (RunQuestionTopic $topic): string => $topic->question(),
                RunQuestionSeeds::for($detail),
            ),
            'at_run_cap' => ! $gate->shouldServeRuleBased($user) && $this->atRunCap($user, $activity),
        ]);
    }

    public function store(
        AskRunQuestionRequest $request,
        NarrationGate $gate,
        CostCeilingLedger $ledger,
        int $activity,
    ): JsonResponse {
        $user = $this->user($request);
        [, $detail] = $this->ownedRun($user, $activity);
        $question = $request->question();

        // The demo login is public and a question is a real agent run, so the
        // demo is answered from this run's own stored numbers instead — the same
        // stance the "Reread" trigger takes, keyed on is_demo rather than on
        // the route. See docs/decisions/demo-triggers-served-rule-based.md.
        if ($gate->shouldServeRuleBased($user)) {
            return $this->created($this->statelessDemoAnswer($user, $activity, $question, $detail));
        }

        if ($this->atRunCap($user, $activity)) {
            return response()->json(['error' => 'run_cap'], 429);
        }

        if ($gate->costCeilingDegraded($user->id)) {
            $ledger->recordDegradedFill('run_question', $user->id);

            return $this->created($this->record(
                $user,
                $activity,
                $question,
                $this->ruleBasedAnswer($detail, $question, RunQuestion::askedAbout($activity)),
            ));
        }

        if ($gate->generationPaused($user->id)) {
            return response()->json(['error' => 'generation_paused'], 409);
        }

        $row = $this->record($user, $activity, $question, ['status' => AnalysisStatus::Queued]);
        AnswerRunQuestionJob::dispatch($row->id)->afterCommit();

        return $this->created($row);
    }

    /**
     * @param  list<string>  $asked
     * @return array{status: AnalysisStatus, answer: string, follow_ups: list<string>}
     */
    private function ruleBasedAnswer(ActivityDetail $detail, string $question, array $asked): array
    {
        return [
            'status' => AnalysisStatus::Done,
            'answer' => RuleBasedRunAnswer::for($detail, $question),
            'follow_ups' => RunQuestionSeeds::unasked($detail, [...$asked, $question]),
        ];
    }

    private function statelessDemoAnswer(User $user, int $activityId, string $question, ActivityDetail $detail): RunQuestion
    {
        $seeded = $this->seededDemoThread($activityId, $detail)
            ->map(fn (RunQuestion $row): string => $row->question)
            ->all();

        return new RunQuestion()->forceFill([
            'user_id' => $user->id,
            'activity_id' => $activityId,
            'question' => $question,
            ...$this->ruleBasedAnswer($detail, $question, array_values($seeded)),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * @return Collection<int, RunQuestion>
     */
    private function seededDemoThread(int $activityId, ActivityDetail $detail): Collection
    {
        $seeded = DemoRunSeeder::seededExchanges($detail);

        return RunQuestion::query()
            ->forActivity($activityId)
            ->whereIn('question', array_keys($seeded))
            ->get()
            ->filter(fn (RunQuestion $row): bool => ($seeded[$row->question] ?? null) === $row->answer)
            ->values();
    }

    private function atRunCap(User $user, int $activityId): bool
    {
        return RunQuestion::query()
            ->where('user_id', $user->id)
            ->where('activity_id', $activityId)
            ->where('created_at', '>=', Carbon::today())
            ->count() >= (int) config('ai.run_question_daily_cap_per_run');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function record(User $user, int $activityId, string $question, array $state): RunQuestion
    {
        return RunQuestion::query()->create([
            'user_id' => $user->id,
            'activity_id' => $activityId,
            'question' => $question,
            ...$state,
        ]);
    }

    private function created(RunQuestion $row): JsonResponse
    {
        return response()->json(RunQuestionResource::make($row), 201);
    }

    /**
     * The activity and its detail row, or an authorization failure. Ownership is
     * checked against the authenticated user, so an id belonging to someone else
     * never reaches the toolbox.
     *
     * @return array{0: Activity, 1: ActivityDetail}
     *
     * @throws AuthorizationException
     */
    private function ownedRun(User $user, int $activityId): array
    {
        $activity = Activity::query()
            ->with('detail')
            ->whereKey($activityId)
            ->where('user_id', $user->id)
            ->first();

        $detail = $activity?->detail;
        if ($activity === null || $detail === null) {
            throw new AuthorizationException("Activity {$activityId} does not belong to user");
        }

        return [$activity, $detail];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthorizationException('Unauthenticated');
        }

        return $user;
    }
}
