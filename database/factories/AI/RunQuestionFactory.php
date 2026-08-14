<?php

declare(strict_types=1);

namespace Database\Factories\AI;

use App\Models\Activity;
use App\Models\AI\RunQuestion;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<RunQuestion>
 */
class RunQuestionFactory extends Factory
{
    /** @return array<string, mixed> */
    #[Override]
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'activity_id' => Activity::factory(),
            'question' => 'why did my HR drift?',
            'answer' => null,
            'status' => AnalysisStatus::Queued,
            'error' => null,
        ];
    }

    public function answered(string $answer = 'your heart rate crept up while pace held.'): self
    {
        return $this->state(fn (): array => [
            'status' => AnalysisStatus::Done,
            'answer' => $answer,
        ]);
    }

    public function failed(string $error = 'LLM unavailable'): self
    {
        return $this->state(fn (): array => [
            'status' => AnalysisStatus::Failed,
            'error' => $error,
        ]);
    }
}
