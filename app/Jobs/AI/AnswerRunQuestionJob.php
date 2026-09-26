<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\AI\RunQuestion;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\CostCeilingLedger;
use App\Services\AI\Narrators\RunQuestionNarrator;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\RunQuestion\RuleBasedRunAnswer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Answers one {@see RunQuestion} off the `ai` queue.
 *
 * Not an {@see AnalyzeRowJob}: that hierarchy settles Analysis rows and draws on
 * their self-heal budget, neither of which a question has. What it does share is
 * the queue, the tries/backoff shape, the refusal to bill while generation is
 * paused, and the fall back to {@see RuleBasedRunAnswer} when the daily spend
 * ceiling is the only thing stopping it.
 */
class AnswerRunQuestionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    private const string PAUSED_ERROR = 'AI generation is paused.';

    public function __construct(public readonly int $runQuestionId)
    {
        $this->onQueue(AnalyzeBaseJob::QUEUE);
    }

    public function handle(AnalysisService $service, RunQuestionNarrator $narrator): void
    {
        // Always user-initiated: the only way a RunQuestion row exists is
        // somebody typing a question on a run.
        app(NarrationOrigin::class)->set(AnalysisOrigin::User);

        $question = RunQuestion::query()->find($this->runQuestionId);
        if ($question === null || $question->status === AnalysisStatus::Done) {
            return;
        }

        $claimToken = $this->claim($question);
        if ($claimToken === null) {
            return;
        }

        $activity = Activity::query()->with('detail')->find($question->activity_id);
        $detail = $activity?->detail;
        if ($activity === null || $detail === null) {
            $this->settleFailed($question, "Activity {$question->activity_id} not analyzed yet", $claimToken);

            return;
        }

        if ($service->costCeilingDegraded($activity->user_id)) {
            if ($this->settle($question, $claimToken, [
                'status' => AnalysisStatus::Done,
                'answer' => RuleBasedRunAnswer::for($detail, $question->question),
                'error' => null,
            ])) {
                app(CostCeilingLedger::class)->recordDegradedFill();
            }

            return;
        }

        if ($service->generationPaused($activity->user_id)) {
            $this->settleFailed($question, self::PAUSED_ERROR, $claimToken);

            return;
        }

        try {
            $this->settle($question, $claimToken, [
                'status' => AnalysisStatus::Done,
                'answer' => $narrator->generate($activity, $detail, $question->question),
                'error' => null,
            ]);
        } catch (TransientUpstreamException $e) {
            if ($this->attempts() >= $this->tries) {
                $this->settleFailed($question, $e->getMessage(), $claimToken);

                return;
            }

            if ($this->settle($question, $claimToken, ['status' => AnalysisStatus::Queued])) {
                $this->release($e->retryAfterSeconds ?? $this->backoff[0]);
            }
        } catch (UnavailableException $e) {
            $this->settleFailed($question, $e->getMessage(), $claimToken);
        } catch (Throwable $e) {
            $this->settleFailed($question, $e->getMessage(), $claimToken);

            throw $e;
        }
    }

    /**
     * Last resort when the worker dies before handle() can settle the row, so a
     * question never sits in Processing forever with nothing coming.
     */
    public function failed(Throwable $e): void
    {
        $question = RunQuestion::query()->find($this->runQuestionId);
        if ($question === null || $question->status === AnalysisStatus::Done) {
            return;
        }

        $question->update(['status' => AnalysisStatus::Failed, 'error' => $e->getMessage()]);
    }

    /**
     * Takes the row in one conditional UPDATE, so only one delivery ever reaches
     * the narrator. A first delivery never takes a live claim; a retry takes over
     * from its dead predecessor, and any delivery may take a claim whose lease
     * (the queue's retry_after) has run out. Returns the new claim token, or null
     * when another delivery holds the row.
     */
    private function claim(RunQuestion $question): ?string
    {
        $now = Carbon::now();
        $token = (string) Str::uuid();
        $leaseExpiredBefore = $now->copy()->subSeconds((int) config('queue.connections.redis.retry_after'));
        $isRetry = $this->attempts() > 1;

        $claimed = RunQuestion::query()
            ->whereKey($question->id)
            ->where(function (Builder $query) use ($isRetry, $leaseExpiredBefore): void {
                $query->whereIn('status', [AnalysisStatus::Queued, AnalysisStatus::Failed])
                    ->orWhere(function (Builder $processing) use ($isRetry, $leaseExpiredBefore): void {
                        $processing->where('status', AnalysisStatus::Processing);
                        if (! $isRetry) {
                            $processing->where(fn (Builder $lease): Builder => $lease
                                ->whereNull('claimed_at')
                                ->orWhere('claimed_at', '<', $leaseExpiredBefore));
                        }
                    });
            })
            ->update([
                'status' => AnalysisStatus::Processing,
                'claim_token' => $token,
                'claimed_at' => $now,
            ]) === 1;

        return $claimed ? $token : null;
    }

    /**
     * Writes only while this delivery still holds the claim, so a finisher that
     * was taken over lands as a no-op.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function settle(RunQuestion $question, string $claimToken, array $attributes): bool
    {
        return RunQuestion::query()
            ->whereKey($question->id)
            ->where('claim_token', $claimToken)
            ->where('status', AnalysisStatus::Processing)
            ->update($attributes) === 1;
    }

    private function settleFailed(RunQuestion $question, string $error, string $claimToken): void
    {
        $this->settle($question, $claimToken, ['status' => AnalysisStatus::Failed, 'error' => $error]);
    }
}
