<?php

declare(strict_types=1);

namespace App\Services\AI\Agent;

/**
 * The per-block ceiling on an agent run, and the running total it is measured
 * against.
 *
 * The daily cost ceiling is a dispatch-time gate — it is read once before the
 * job is queued and never again — so nothing else stops a tool loop that keeps
 * deciding it needs one more read. This is what bounds a single block.
 *
 * Tokens accumulate across every step of the run *including* an aborted attempt
 * that is being retried: those tokens were billed, so they count.
 */
final class AgentBudget
{
    public const string REASON_STEPS = 'max_steps';

    public const string REASON_TOKENS = 'max_tokens';

    private int $steps = 0;

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    private int $totalTokens = 0;

    private int $cachedTokens = 0;

    private int $reasoningTokens = 0;

    public function __construct(
        private readonly int $maxSteps,
        private readonly int $maxTokens,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (int) config('ai.agent.max_steps'),
            (int) config('ai.agent.max_tokens'),
        );
    }

    /**
     * Fold one model turn's usage into the run. `total` is trusted when the
     * provider reports it, since reasoning tokens land there but in neither
     * input nor output.
     *
     * `cached` and `reasoning` are breakdowns, not additions: cached tokens are
     * a subset of input (billed at a discount), reasoning a subset of output.
     * Neither is added to the totals, and neither counts toward the ceiling on
     * its own — the ceiling already sees them through `total`.
     */
    public function recordStep(
        int $inputTokens,
        int $outputTokens,
        int $totalTokens,
        int $cachedTokens = 0,
        int $reasoningTokens = 0,
    ): void {
        $this->steps++;
        $this->inputTokens += $inputTokens;
        $this->outputTokens += $outputTokens;
        $this->totalTokens += $totalTokens > 0 ? $totalTokens : $inputTokens + $outputTokens;
        $this->cachedTokens += $cachedTokens;
        $this->reasoningTokens += $reasoningTokens;
    }

    /**
     * Whether the model may be offered its tools again. False makes the next
     * turn a forced answer rather than an error: a capped run still returns
     * narration, from whatever it managed to read.
     */
    public function allowsToolStep(): bool
    {
        return $this->exhaustedReason() === null;
    }

    /** Which ceiling was hit, or null while the run is still within budget. */
    public function exhaustedReason(): ?string
    {
        if ($this->steps >= $this->maxSteps) {
            return self::REASON_STEPS;
        }

        return $this->totalTokens >= $this->maxTokens ? self::REASON_TOKENS : null;
    }

    public function steps(): int
    {
        return $this->steps;
    }

    public function inputTokens(): int
    {
        return $this->inputTokens;
    }

    public function outputTokens(): int
    {
        return $this->outputTokens;
    }

    public function totalTokens(): int
    {
        return $this->totalTokens;
    }

    /** Input tokens served from the provider's prompt cache, billed at a discount. */
    public function cachedTokens(): int
    {
        return $this->cachedTokens;
    }

    /** Output tokens spent on reasoning rather than the answer, billed as output. */
    public function reasoningTokens(): int
    {
        return $this->reasoningTokens;
    }
}
