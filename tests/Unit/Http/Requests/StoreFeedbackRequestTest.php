<?php

declare(strict_types=1);

use App\Enums\FeedbackReason;
use App\Http\Requests\StoreFeedbackRequest;
use App\Models\AI\Analysis;
use App\Models\Feedback;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

function feedbackRules(array $input = []): array
{
    return feedbackRequest($input, null)->rules();
}

function feedbackRequest(array $input, ?User $user): StoreFeedbackRequest
{
    $request = StoreFeedbackRequest::create('/feedback', 'POST', $input);
    $request->setUserResolver(fn (): ?User => $user);

    return $request;
}

it('rejects a subject the enum does not name', function (mixed $subjectType): void {
    $data = ['subject_type' => $subjectType, 'subject_id' => 1, 'reason' => 'too_hard'];

    expect(Validator::make($data, feedbackRules($data))->fails())->toBeTrue();
})->with([
    'missing' => [null],
    'unknown' => ['weather'],
    'not a string' => [['plan_day']],
]);

it('rejects a note longer than the column', function (): void {
    $data = [
        'subject_type' => 'plan_day',
        'subject_id' => 1,
        'reason' => 'too_hard',
        'note' => str_repeat('a', Feedback::MAX_NOTE_LENGTH + 1),
    ];

    expect(Validator::make($data, feedbackRules($data))->fails())->toBeTrue();
});

it('accepts a flag with no note at all', function (): void {
    $data = ['subject_type' => 'narration', 'subject_id' => 3, 'reason' => 'tone_off'];

    expect(Validator::make($data, feedbackRules($data))->fails())->toBeFalse();
});

it('accepts only the reasons the subject owns', function (string $subjectType, string $reason, bool $fails): void {
    $data = ['subject_type' => $subjectType, 'subject_id' => 3, 'reason' => $reason];

    expect(Validator::make($data, feedbackRules($data))->fails())->toBe($fails);
})->with([
    'a narration reason on a narration' => ['narration', 'facts_wrong', false],
    'a plan day reason on a narration' => ['narration', 'too_hard', true],
    'a plan day reason on a plan day' => ['plan_day', 'wrong_pace', false],
    'a narration reason on a plan day' => ['plan_day', 'too_long', true],
    'a reason the enum does not name' => ['plan_day', 'too_wet', true],
]);

it('requires a reason', function (): void {
    $data = ['subject_type' => 'plan_day', 'subject_id' => 3];

    expect(Validator::make($data, feedbackRules($data))->fails())->toBeTrue();
});

it('reads the chosen reason back as its enum case', function (): void {
    $request = feedbackRequest(
        ['subject_type' => 'plan_day', 'subject_id' => 3, 'reason' => 'too_easy'],
        User::factory()->create(),
    );
    $request->setValidator(Validator::make($request->all(), $request->rules()));

    expect($request->reason())->toBe(FeedbackReason::TooEasy);
});

it('refuses a request that carries no authenticated user', function (): void {
    expect(feedbackRequest(['subject_type' => 'plan_day', 'subject_id' => 1], null)->authorize())->toBeFalse();
});

it('leaves an unrecognised subject type to the validator rather than calling it a permission problem', function (): void {
    $user = User::factory()->create();

    expect(feedbackRequest(['subject_type' => 'weather', 'subject_id' => 1], $user)->authorize())->toBeTrue();
});

it('authorizes a plan day the user owns and refuses one they do not', function (): void {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $day = PlannedSession::factory()->for($user)->create();

    expect(feedbackRequest(['subject_type' => 'plan_day', 'subject_id' => $day->id], $user)->authorize())->toBeTrue()
        ->and(feedbackRequest(['subject_type' => 'plan_day', 'subject_id' => $day->id], $stranger)->authorize())->toBeFalse();
});

it('authorizes a narration through the analysis subject authorizer', function (): void {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $analysis = Analysis::factory()->create([
        'analysis_type' => AnalysisType::PlanDayVoice,
        'subject_type' => User::class,
        'subject_id' => $user->id,
    ]);

    expect(feedbackRequest(['subject_type' => 'narration', 'subject_id' => $analysis->id], $user)->authorize())->toBeTrue()
        ->and(feedbackRequest(['subject_type' => 'narration', 'subject_id' => $analysis->id], $stranger)->authorize())->toBeFalse();
});

it('refuses a narration that does not exist', function (): void {
    $user = User::factory()->create();

    expect(feedbackRequest(['subject_type' => 'narration', 'subject_id' => 9999], $user)->authorize())->toBeFalse();
});
