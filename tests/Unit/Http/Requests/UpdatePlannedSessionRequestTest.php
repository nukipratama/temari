<?php

declare(strict_types=1);

use App\Http\Requests\UpdatePlannedSessionRequest;
use Illuminate\Support\Facades\Validator;

function validatePlannedSessionUpdate(array $payload): Illuminate\Validation\Validator
{
    $request = new UpdatePlannedSessionRequest();

    return Validator::make($payload, $request->rules());
}

it('authorizes the request', function (): void {
    expect(new UpdatePlannedSessionRequest()->authorize())->toBeTrue();
});

it('passes the optional fields it accepts', function (array $payload): void {
    expect(validatePlannedSessionUpdate($payload)->passes())->toBeTrue();
})->with([
    'an empty payload, since every field is optional' => [[]],
    'a valid move (date)' => [['date' => '2026-08-13']],
    'a pin' => [['pinned' => true]],
    'an unpin' => [['pinned' => false]],
    'a skip' => [['skipped' => true]],
    'an unskip' => [['skipped' => false]],
]);

it('rejects an invalid date or a non-boolean flag', function (array $payload): void {
    expect(validatePlannedSessionUpdate($payload)->fails())->toBeTrue();
})->with([
    'an invalid date' => [['date' => 'not-a-date']],
    'a non-boolean pinned value' => [['pinned' => 'yes']],
    'a non-boolean skipped value' => [['skipped' => 'yes']],
]);

it('no longer validates session_type, block having been cut by P23', function (): void {
    expect(validatePlannedSessionUpdate(['session_type' => 'sprint'])->validated())
        ->not->toHaveKey('session_type');
});

it('rejects a move that also sets skipped or pinned', function (string $flag, bool $value): void {
    $validator = validatePlannedSessionUpdate(['date' => now()->addDay()->toDateString(), $flag => $value]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('date'))->toBeTrue();
})->with([
    'skipped' => ['skipped', true],
    'unskipped' => ['skipped', false],
    'pinned' => ['pinned', true],
    'unpinned' => ['pinned', false],
]);

it('leaves which days a move may reach to the edit rules', function (): void {
    expect(validatePlannedSessionUpdate(['date' => now()->toDateString()])->passes())->toBeTrue()
        ->and(validatePlannedSessionUpdate(['date' => now()->subDay()->toDateString()])->passes())->toBeTrue();
});
