<?php

declare(strict_types=1);

use App\Http\Requests\StoreRaceGoalRequest;
use Illuminate\Support\Facades\Validator;

/**
 * @return array<string, mixed>
 */
function raceGoalPayload(array $overrides = []): array
{
    return [
        'race_date' => now()->addWeeks(12)->toDateString(),
        'distance_m' => 10_000,
        'goal_time_sec' => 3_000,
        'name' => 'Jakarta 10K',
        ...$overrides,
    ];
}

function validateRaceGoal(array $payload): Illuminate\Validation\Validator
{
    $request = new StoreRaceGoalRequest();

    return Validator::make($payload, $request->rules(), $request->messages());
}

it('authorizes the request', function (): void {
    expect(new StoreRaceGoalRequest()->authorize())->toBeTrue();
});

it('passes a valid future race', function (): void {
    expect(validateRaceGoal(raceGoalPayload())->passes())->toBeTrue();
});

it('passes without a name, which is optional', function (): void {
    $payload = raceGoalPayload();
    unset($payload['name']);

    expect(validateRaceGoal($payload)->passes())->toBeTrue();
});

it('rejects a race_date in the past', function (): void {
    expect(validateRaceGoal(raceGoalPayload(['race_date' => now()->subDay()->toDateString()]))->fails())->toBeTrue();
});

it('rejects a race_date more than 5 years out', function (): void {
    expect(validateRaceGoal(raceGoalPayload(['race_date' => now()->addYears(6)->toDateString()]))->fails())->toBeTrue();
});

it('rejects a distance below 1 km or above 300 km', function (): void {
    expect(validateRaceGoal(raceGoalPayload(['distance_m' => 500]))->fails())->toBeTrue()
        ->and(validateRaceGoal(raceGoalPayload(['distance_m' => 400_000]))->fails())->toBeTrue();
});

it('accepts distance at the 1 km and 300 km boundaries', function (): void {
    expect(validateRaceGoal(raceGoalPayload(['distance_m' => 1_000]))->passes())->toBeTrue()
        ->and(validateRaceGoal(raceGoalPayload(['distance_m' => 300_000]))->passes())->toBeTrue();
});

it('rejects a goal time below 5 minutes or above 72 hours', function (): void {
    expect(validateRaceGoal(raceGoalPayload(['goal_time_sec' => 60]))->fails())->toBeTrue()
        ->and(validateRaceGoal(raceGoalPayload(['goal_time_sec' => 300_000]))->fails())->toBeTrue();
});

it('rejects a name longer than 120 characters', function (): void {
    expect(validateRaceGoal(raceGoalPayload(['name' => str_repeat('a', 121)]))->fails())->toBeTrue();
});

it('requires the core fields', function (): void {
    $validator = validateRaceGoal(['name' => 'No dates or distance']);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('race_date'))->toBeTrue()
        ->and($validator->errors()->has('distance_m'))->toBeTrue()
        ->and($validator->errors()->has('goal_time_sec'))->toBeTrue();
});
