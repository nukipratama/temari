<?php

declare(strict_types=1);

namespace Database\Factories\AI;

use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<Analysis>
 */
class AnalysisFactory extends Factory
{
    protected $model = Analysis::class;

    /** @return array<string, mixed> */
    #[Override]
    public function definition(): array
    {
        return [
            'subject_type' => 'briefing_user_day',
            'subject_id' => 1,
            'analysis_type' => AnalysisType::BriefingHeadline,
            'discriminator' => '2026-05-18',
            'status' => AnalysisStatus::Pending,
            'content' => null,
            'error' => null,
            'generated_at' => null,
            'queued_at' => null,
            'attempts' => 0,
        ];
    }

    public function done(string $content = 'Sample narrative'): self
    {
        return $this->state(fn (): array => [
            'status' => AnalysisStatus::Done,
            'content' => $content,
            'generated_at' => now(),
        ]);
    }

    public function failed(string $error = 'LLM unavailable'): self
    {
        return $this->state(fn (): array => [
            'status' => AnalysisStatus::Failed,
            'error' => $error,
            'attempts' => 1,
        ]);
    }

    public function queued(): self
    {
        return $this->state(fn (): array => [
            'status' => AnalysisStatus::Queued,
            'queued_at' => now(),
        ]);
    }
}
