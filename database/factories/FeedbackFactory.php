<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FeedbackReason;
use App\Enums\FeedbackSubject;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    /** @return array<string, mixed> */
    #[Override]
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subject_type' => FeedbackSubject::PlanDay,
            'subject_id' => 1,
            'reason' => FeedbackReason::WrongDay,
            'note' => null,
            'created_at' => now(),
        ];
    }

    public function onNarration(int $subjectId): self
    {
        return $this->state(fn (): array => [
            'subject_type' => FeedbackSubject::Narration,
            'subject_id' => $subjectId,
            'reason' => FeedbackReason::FactsWrong,
        ]);
    }

    public function onPlanDay(int $subjectId): self
    {
        return $this->state(fn (): array => [
            'subject_type' => FeedbackSubject::PlanDay,
            'subject_id' => $subjectId,
            'reason' => FeedbackReason::WrongDay,
        ]);
    }
}
