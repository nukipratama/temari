<?php

declare(strict_types=1);

use App\Services\AI\Narrators\PlanVerdictRule;

it('makes the judged intent the one effort verdict every run narrator repeats', function (): void {
    expect(PlanVerdictRule::RULE)
        ->toContain('intent_detail')
        ->toContain('Never contradict it')
        ->toContain('Never compare the run\'s overall average pace with target_pace')
        ->toContain('eased_from')
        ->toContain('completed_km');
});

it('sets the rule where a prompt marks its place', function (): void {
    expect(PlanVerdictRule::into("THE PLAN: read it.\n\n{plan_verdict}\n\nRAIN: hedge."))
        ->toBe("THE PLAN: read it.\n\n".PlanVerdictRule::RULE."\n\nRAIN: hedge.");
});
