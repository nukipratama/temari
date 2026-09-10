<?php

declare(strict_types=1);

namespace App\Services\Devtools;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\LlmCostCalculator;
use App\Services\AI\NarrationOrigin;

/**
 * Re-narrate one flagged block as a QA replay: same subject, same narrator, a
 * fresh answer to compare against the one the athlete complained about.
 *
 * A replay is the operator's spend, not the athlete's, so it is measured against
 * its own app-wide daily cap rather than the athlete's slice. The cap is enforced
 * again inside {@see AnalysisService} at dispatch; this reads the same numbers so
 * the button can refuse before asking for a confirmation it would not honour.
 */
class ReplayNarrationAction
{
    public function __construct(
        private readonly AnalysisService $service,
        private readonly NarrationOrigin $origin,
        private readonly LlmCostCalculator $costs,
    ) {
    }

    public function cap(): ?float
    {
        $cap = config('azure_openai.replay_daily_cap');

        return is_numeric($cap) ? (float) $cap : null;
    }

    public function spentToday(): float
    {
        return $this->costs->dailyCost(origin: AnalysisOrigin::Replay);
    }

    /** Refused *at* the cap rather than past it: a replay is discretionary. */
    public function capReached(): bool
    {
        $cap = $this->cap();

        return $cap !== null && $this->spentToday() >= $cap;
    }

    /**
     * Re-dispatch the block. The demo athlete is served from the rule-based
     * filler like every other demo trigger, so a public demo account can never
     * be replayed onto the bill.
     */
    public function replay(Analysis $row, User $athlete): void
    {
        $this->origin->set(AnalysisOrigin::Replay);

        if ($this->service->shouldServeRuleBased($athlete)) {
            $this->service->requestRuleBased(
                $row->subject_type,
                $row->subject_id,
                $row->analysis_type,
                $row->discriminator,
            );

            return;
        }

        $this->service->request(
            subjectOrType: $row->subject_type,
            subjectId: $row->subject_id,
            type: $row->analysis_type,
            discriminator: $row->discriminator,
            invalidate: true,
        );
    }
}
