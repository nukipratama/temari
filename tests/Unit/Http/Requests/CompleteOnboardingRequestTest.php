<?php

declare(strict_types=1);

use App\Http\Requests\CompleteOnboardingRequest;
use Illuminate\Support\Facades\Validator;

/**
 * @return array<string, mixed>
 */
function onboardingRequestPayload(array $overrides = []): array
{
    return [
        'race_date' => now()->addWeeks(12)->toDateString(),
        'distance_m' => 10_000,
        'goal_time_sec' => 3_000,
        'name' => 'Jakarta 10K',
        ...$overrides,
    ];
}

function validateOnboarding(array $payload): Illuminate\Validation\Validator
{
    $request = new CompleteOnboardingRequest();
    $validator = Validator::make($payload, $request->rules(), $request->messages());
    $request->setValidator($validator);
    $request->withValidator($validator);

    return $validator;
}

it('authorizes the request', function (): void {
    expect(new CompleteOnboardingRequest()->authorize())->toBeTrue();
});

it('passes a fully filled-in goal', function (): void {
    expect(validateOnboarding(onboardingRequestPayload())->passes())->toBeTrue();
});

it('passes an entirely empty submission, the skip case', function (): void {
    expect(validateOnboarding([])->passes())->toBeTrue();
});

it('rejects a distance with no race date or goal time', function (): void {
    $validator = validateOnboarding(['distance_m' => 10_000]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('race_date'))->toBeTrue()
        ->and($validator->errors()->has('goal_time_sec'))->toBeTrue();
});

it('rejects a race date with no distance or goal time', function (): void {
    $validator = validateOnboarding(['race_date' => now()->addWeeks(12)->toDateString()]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('distance_m'))->toBeTrue()
        ->and($validator->errors()->has('goal_time_sec'))->toBeTrue();
});

it('rejects a goal or preferences step the rules refuse', function (array $overrides): void {
    expect(validateOnboarding(onboardingRequestPayload($overrides))->fails())->toBeTrue();
})->with([
    'a race_date in the past' => [fn () => ['race_date' => now()->subDay()->toDateString()]],
    'a distance below 1 km' => [['distance_m' => 500]],
    'a distance above 300 km' => [['distance_m' => 400_000]],
    'a goal time below 5 minutes' => [['goal_time_sec' => 60]],
    'a goal time above 72 hours' => [['goal_time_sec' => 300_000]],
    'a name longer than 120 characters' => [['name' => str_repeat('a', 121)]],
    'an invalid experience_level' => [['experience_level' => 'pro']],
    'an invalid goal_type' => [['goal_type' => 'glory']],
    'a run_days count that does not match sessions_per_week' => [['sessions_per_week' => 4, 'run_days' => [1, 3, 5]]],
    'a long_run_day that is not one of the chosen run_days' => [['run_days' => [1, 3, 5], 'long_run_day' => 2]],
]);

it('passes a fully filled-in training preferences step', function (): void {
    $payload = onboardingRequestPayload([
        'experience_level' => 'new_to_running',
        'sessions_per_week' => 3,
        'goal_type' => 'consistent',
        'run_days' => [1, 3, 5],
        'long_run_day' => 5,
    ]);

    expect(validateOnboarding($payload)->passes())->toBeTrue();
});
