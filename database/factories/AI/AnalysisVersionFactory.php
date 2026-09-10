<?php

declare(strict_types=1);

namespace Database\Factories\AI;

use App\Models\AI\Analysis;
use App\Models\AI\AnalysisVersion;
use App\Services\AI\ServedBy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<AnalysisVersion>
 */
class AnalysisVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    #[Override]
    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'content' => 'Superseded narrative',
            'fingerprint' => null,
            'served_by' => ServedBy::Llm,
            'generated_at' => now(),
        ];
    }
}
