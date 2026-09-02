<?php

declare(strict_types=1);

use App\Http\Requests\UpdateTrainingPreferencesRequest;
use Illuminate\Support\Facades\Validator;

/**
 * @return array<string, mixed>
 */
function trainingPreferencesPayload(array $overrides = []): array
{
    return [
        'experience_level' => 'returning',
        'sessions_per_week' => 4,
        'goal_type' => 'race',
        'run_days' => [1, 3, 5, 6],
        'long_run_day' => 6,
        ...$overrides,
    ];
}

function validateTrainingPreferences(array $payload): Illuminate\Validation\Validator
{
    $request = new UpdateTrainingPreferencesRequest();
    $validator = Validator::make($payload, $request->rules());
    $request->setValidator($validator);
    $request->withValidator($validator);

    return $validator;
}

it('authorizes the request', function (): void {
    expect(new UpdateTrainingPreferencesRequest()->authorize())->toBeTrue();
});

it('passes a valid full submission', function (): void {
    expect(validateTrainingPreferences(trainingPreferencesPayload())->passes())->toBeTrue();
});

it('passes an entirely empty submission — every field is independently nullable', function (): void {
    expect(validateTrainingPreferences([])->passes())->toBeTrue();
});

it('rejects an invalid experience_level or goal_type value', function (): void {
    expect(validateTrainingPreferences(trainingPreferencesPayload(['experience_level' => 'pro']))->passes())->toBeFalse()
        ->and(validateTrainingPreferences(trainingPreferencesPayload(['goal_type' => 'glory']))->passes())->toBeFalse();
});

it('rejects sessions_per_week outside 2-6', function (): void {
    expect(validateTrainingPreferences(trainingPreferencesPayload(['sessions_per_week' => 1]))->passes())->toBeFalse()
        ->and(validateTrainingPreferences(trainingPreferencesPayload(['sessions_per_week' => 7]))->passes())->toBeFalse();
});

it('rejects a run_days count outside 2-6 or with duplicate/out-of-range entries', function (): void {
    expect(validateTrainingPreferences(trainingPreferencesPayload(['run_days' => [1]]))->passes())->toBeFalse()
        ->and(validateTrainingPreferences(trainingPreferencesPayload(['run_days' => [0, 1, 2, 3, 4, 5, 6]]))->passes())->toBeFalse()
        ->and(validateTrainingPreferences(trainingPreferencesPayload(['run_days' => [1, 1, 3, 6]]))->passes())->toBeFalse()
        ->and(validateTrainingPreferences(trainingPreferencesPayload(['run_days' => [1, 3, 5, 7]]))->passes())->toBeFalse();
});

it('rejects a run_days count that does not match sessions_per_week', function (): void {
    expect(validateTrainingPreferences(trainingPreferencesPayload(['sessions_per_week' => 5, 'run_days' => [1, 3, 5, 6]]))->passes())->toBeFalse();
});

it('rejects a long_run_day that is not one of the chosen run_days', function (): void {
    expect(validateTrainingPreferences(trainingPreferencesPayload(['long_run_day' => 2]))->passes())->toBeFalse();
});

it('skips the structural cross-check when run_days is absent', function (): void {
    expect(validateTrainingPreferences(['sessions_per_week' => 5, 'long_run_day' => 2])->passes())->toBeTrue();
});
