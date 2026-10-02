<?php

declare(strict_types=1);

use App\Http\Requests\StoreRaceOutcomeRequest;
use App\Models\RaceGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

function validateRaceOutcome(array $payload): Illuminate\Validation\Validator
{
    return Validator::make($payload, new StoreRaceOutcomeRequest()->rules());
}

it('authorizes only the athlete who owns the race', function (): void {
    $owner = User::factory()->create();
    $race = RaceGoal::factory()->for($owner)->completed()->create();
    $request = StoreRaceOutcomeRequest::create("/race/{$race->id}/outcome", 'POST');
    $request->setRouteResolver(fn () => Route::getRoutes()->match($request));

    $request->setUserResolver(fn () => $owner);
    $ownerAllowed = $request->authorize();
    $request->setUserResolver(fn () => User::factory()->create());
    $strangerAllowed = $request->authorize();
    $request->setUserResolver(fn () => null);

    expect($ownerAllowed)->toBeTrue()
        ->and($strangerAllowed)->toBeFalse()
        ->and($request->authorize())->toBeFalse();
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
