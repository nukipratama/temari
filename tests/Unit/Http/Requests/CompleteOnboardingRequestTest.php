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

    return Validator::make($payload, $request->rules(), $request->messages());
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

it('rejects a race_date in the past', function (): void {
    expect(validateOnboarding(onboardingRequestPayload(['race_date' => now()->subDay()->toDateString()]))->fails())->toBeTrue();
});

it('rejects a distance below 1 km or above 300 km', function (): void {
    expect(validateOnboarding(onboardingRequestPayload(['distance_m' => 500]))->fails())->toBeTrue()
        ->and(validateOnboarding(onboardingRequestPayload(['distance_m' => 400_000]))->fails())->toBeTrue();
});

it('rejects a goal time below 5 minutes or above 72 hours', function (): void {
    expect(validateOnboarding(onboardingRequestPayload(['goal_time_sec' => 60]))->fails())->toBeTrue()
        ->and(validateOnboarding(onboardingRequestPayload(['goal_time_sec' => 300_000]))->fails())->toBeTrue();
});

it('rejects a name longer than 120 characters', function (): void {
    expect(validateOnboarding(onboardingRequestPayload(['name' => str_repeat('a', 121)]))->fails())->toBeTrue();
});
