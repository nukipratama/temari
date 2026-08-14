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
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\CostCeilingLedger;
use App\Services\AI\RunQuestion\RuleBasedRunAnswer;
use App\Services\AI\RunQuestion\RunQuestionSeeds;
use App\Services\AI\RunQuestion\RunQuestionTopic;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Ask about this run" — the scoped alternative to a chat surface.
 *
 * A question is bound to one activity at the door and stays bound: the answering
 * toolbox is built from that activity alone, so there is no phrasing that widens
 * it. Answers are generated off the queue and polled from {@see self::index()},
 * because a tool-calling run takes several Azure round trips and can block on the
 * outbound throttle far longer than an HTTP request should live.
 */
class RunQuestionController extends Controller
{
    public function index(Request $request, int $activity): JsonResponse
    {
        [, $detail] = $this->ownedRun($this->user($request), $activity);

        return response()->json([
            'questions' => RunQuestionResource::collection(
                RunQuestion::query()->forActivity($activity)->get(),
            ),
            'suggestions' => array_map(
                fn (RunQuestionTopic $topic): string => $topic->question(),
                RunQuestionSeeds::for($detail),
            ),
        ]);
    }

    public function store(
        AskRunQuestionRequest $request,
        AnalysisService $service,
        CostCeilingLedger $ledger,
        int $activity,
    ): JsonResponse {
        $user = $this->user($request);
        [, $detail] = $this->ownedRun($user, $activity);
        $question = $request->question();

        // The demo login is public and a question is a real agent run, so the
        // demo is answered from this run's own stored numbers instead — the same
        // stance the "Baca ulang" trigger takes, keyed on is_demo rather than on
        // the route. See docs/decisions/demo-triggers-served-rule-based.md.
        if ($service->shouldServeRuleBased($user)) {
            return $this->created($this->ruleBasedRow($user, $activity, $question, $detail));
        }

        if ($service->costCeilingDegraded()) {
            $ledger->recordDegradedFill();

            return $this->created($this->ruleBasedRow($user, $activity, $question, $detail));
        }

        if ($service->generationPaused()) {
            return response()->json(['error' => 'generation_paused'], 409);
        }

        $row = $this->record($user, $activity, $question, ['status' => AnalysisStatus::Queued]);
        AnswerRunQuestionJob::dispatch($row->id)->afterCommit();

        return $this->created($row);
    }

    private function ruleBasedRow(User $user, int $activityId, string $question, ActivityDetail $detail): RunQuestion
    {
        return $this->record($user, $activityId, $question, [
            'status' => AnalysisStatus::Done,
            'answer' => RuleBasedRunAnswer::for($detail, $question),
        ]);
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
