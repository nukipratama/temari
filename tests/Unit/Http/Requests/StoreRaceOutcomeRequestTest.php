<?php

declare(strict_types=1);

use App\Http\Requests\StoreRaceOutcomeRequest;
use Illuminate\Support\Facades\Validator;

function validateRaceOutcome(array $payload): Illuminate\Validation\Validator
{
    return Validator::make($payload, new StoreRaceOutcomeRequest()->rules());
}

it('authorizes the request', function (): void {
    expect(new StoreRaceOutcomeRequest()->authorize())->toBeTrue();
});

it('accepts every outcome state', function (string $outcome): void {
    expect(validateRaceOutcome(['outcome' => $outcome])->passes())->toBeTrue();
})->with(['pending', 'confirmed', 'did_not_run', 'cancelled']);

it('rejects a missing or unknown outcome', function (): void {
    expect(validateRaceOutcome([])->fails())->toBeTrue()
        ->and(validateRaceOutcome(['outcome' => 'won'])->fails())->toBeTrue();
});

it('bounds a manual finish time to a plausible race', function (): void {
    expect(validateRaceOutcome(['outcome' => 'confirmed', 'finish_time_sec' => 299])->fails())->toBeTrue()
        ->and(validateRaceOutcome(['outcome' => 'confirmed', 'finish_time_sec' => 259_201])->fails())->toBeTrue()
        ->and(validateRaceOutcome(['outcome' => 'confirmed', 'finish_time_sec' => 3_000])->passes())->toBeTrue()
        ->and(validateRaceOutcome(['outcome' => 'confirmed', 'activity_id' => 12])->passes())->toBeTrue();
});
