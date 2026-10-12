<?php

declare(strict_types=1);

use App\Enums\RaceIntent;
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

it('rejects a race the rules refuse', function (array $overrides): void {
    expect(validateRaceGoal(raceGoalPayload($overrides))->fails())->toBeTrue();
})->with([
    'a race_date in the past' => [fn () => ['race_date' => now()->subDay()->toDateString()]],
    'a race_date more than 5 years out' => [fn () => ['race_date' => now()->addYears(6)->toDateString()]],
    'a distance below 1 km' => [['distance_m' => 500]],
    'a distance above 300 km' => [['distance_m' => 400_000]],
    'a goal time below 5 minutes' => [['goal_time_sec' => 60]],
    'a goal time above 72 hours' => [['goal_time_sec' => 300_000]],
    'a name longer than 120 characters' => [['name' => str_repeat('a', 121)]],
]);

it('accepts distance at the 1 km and 300 km boundaries', function (): void {
    expect(validateRaceGoal(raceGoalPayload(['distance_m' => 1_000]))->passes())->toBeTrue()
        ->and(validateRaceGoal(raceGoalPayload(['distance_m' => 300_000]))->passes())->toBeTrue();
});

it('requires the core fields', function (): void {
    $validator = validateRaceGoal(['name' => 'No dates or distance']);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('race_date'))->toBeTrue()
        ->and($validator->errors()->has('distance_m'))->toBeTrue()
        ->and($validator->errors()->has('goal_time_sec'))->toBeTrue();
});

it('accepts an update or new-race intent and rejects anything else', function (): void {
    expect(validateRaceGoal(raceGoalPayload(['intent' => 'update']))->passes())->toBeTrue()
        ->and(validateRaceGoal(raceGoalPayload(['intent' => 'new']))->passes())->toBeTrue()
        ->and(validateRaceGoal(raceGoalPayload(['intent' => 'replace']))->fails())->toBeTrue();
});

it('defaults the intent to updating the current race', function (): void {
    $withoutIntent = StoreRaceGoalRequest::create('/race', 'POST', raceGoalPayload());
    $asNew = StoreRaceGoalRequest::create('/race', 'POST', raceGoalPayload(['intent' => 'new']));

    expect($withoutIntent->intent())->toBe(RaceIntent::Update)
        ->and($asNew->intent())->toBe(RaceIntent::New);
});
