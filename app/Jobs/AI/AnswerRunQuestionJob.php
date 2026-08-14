<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Models\Activity;
use App\Models\AI\RunQuestion;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\CostCeilingLedger;
use App\Services\AI\Narrators\RunQuestionNarrator;
use App\Services\AI\RunQuestion\RuleBasedRunAnswer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        $question = RunQuestion::query()->find($this->runQuestionId);
        if ($question === null || $question->status === AnalysisStatus::Done) {
            return;
        }

        $activity = Activity::query()->with('detail')->find($question->activity_id);
        $detail = $activity?->detail;
        if ($activity === null || $detail === null) {
            $this->settleFailed($question, "Activity {$question->activity_id} not analyzed yet");

            return;
        }

        if ($service->costCeilingDegraded()) {
            $question->update([
                'status' => AnalysisStatus::Done,
                'answer' => RuleBasedRunAnswer::for($detail, $question->question),
                'error' => null,
            ]);
            app(CostCeilingLedger::class)->recordDegradedFill();

            return;
        }

        if ($service->generationPaused()) {
            $this->settleFailed($question, self::PAUSED_ERROR);

            return;
        }

        $question->update(['status' => AnalysisStatus::Processing]);

        try {
            $question->update([
                'status' => AnalysisStatus::Done,
                'answer' => $narrator->generate($activity, $detail, $question->question),
                'error' => null,
            ]);
        } catch (TransientUpstreamException $e) {
            if ($this->attempts() >= $this->tries) {
                $this->settleFailed($question, $e->getMessage());

                return;
            }

            $question->update(['status' => AnalysisStatus::Queued]);
            $this->release($e->retryAfterSeconds ?? $this->backoff[0]);
        } catch (UnavailableException $e) {
            $this->settleFailed($question, $e->getMessage());
        } catch (Throwable $e) {
            $this->settleFailed($question, $e->getMessage());

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

        $this->settleFailed($question, $e->getMessage());
    }

    private function settleFailed(RunQuestion $question, string $error): void
    {
        $question->update(['status' => AnalysisStatus::Failed, 'error' => $error]);
    }
}
