<?php

declare(strict_types=1);

use App\Actions\Feedback\ResolveFlaggedSubjectsAction;
use App\Enums\FeedbackReason;
use App\Enums\FeedbackSubject;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function flagRow(User $user, FeedbackSubject $subject, int $subjectId): void
{
    Feedback::query()->create([
        'user_id' => $user->id,
        'subject_type' => $subject,
        'subject_id' => $subjectId,
        'reason' => FeedbackReason::TooHard,
    ]);
}

it('reports nothing flagged with no authenticated user', function (): void {
    expect(new ResolveFlaggedSubjectsAction()(FeedbackSubject::PlanDay, 1))->toBeFalse();
});

it('reports nothing flagged for a subject with no id', function (): void {
    $this->actingAs(User::factory()->create());

    expect(new ResolveFlaggedSubjectsAction()(FeedbackSubject::Narration, null))->toBeFalse();
});

it('tells a flagged subject from an unflagged one, and keeps the subjects apart', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    flagRow($user, FeedbackSubject::PlanDay, 5);

    $action = new ResolveFlaggedSubjectsAction();

    expect($action(FeedbackSubject::PlanDay, 5))->toBeTrue()
        ->and($action(FeedbackSubject::PlanDay, 6))->toBeFalse()
        ->and($action(FeedbackSubject::Narration, 5))->toBeFalse();
});

it('ignores another athlete flags', function (): void {
    $stranger = User::factory()->create();
    flagRow($stranger, FeedbackSubject::PlanDay, 5);
    $this->actingAs(User::factory()->create());

    expect(new ResolveFlaggedSubjectsAction()(FeedbackSubject::PlanDay, 5))->toBeFalse();
});

it('reads the athletes flags once however many subjects ask', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    flagRow($user, FeedbackSubject::PlanDay, 5);

    $action = new ResolveFlaggedSubjectsAction();
    DB::enableQueryLog();

    foreach (range(1, 10) as $subjectId) {
        $action(FeedbackSubject::PlanDay, $subjectId);
    }

    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
});
