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
