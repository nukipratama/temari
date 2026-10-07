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

it('passes an empty payload, since every field is optional', function (): void {
    expect(validatePlannedSessionUpdate([])->passes())->toBeTrue();
});

it('passes a valid move (date)', function (): void {
    expect(validatePlannedSessionUpdate(['date' => now()->addDay()->toDateString()])->passes())->toBeTrue();
});

it('rejects an invalid date', function (): void {
    expect(validatePlannedSessionUpdate(['date' => 'not-a-date'])->fails())->toBeTrue();
});

it('no longer validates session_type, block having been cut by P23', function (): void {
    // Not a rule any more, so it neither passes nor fails — it is simply
    // dropped, and PlanController never reads it.
    expect(validatePlannedSessionUpdate(['session_type' => 'sprint'])->validated())
        ->not->toHaveKey('session_type');
});

it('passes an explicit pin/unpin toggle', function (): void {
    expect(validatePlannedSessionUpdate(['pinned' => true])->passes())->toBeTrue()
        ->and(validatePlannedSessionUpdate(['pinned' => false])->passes())->toBeTrue();
});

it('rejects a non-boolean pinned value', function (): void {
    expect(validatePlannedSessionUpdate(['pinned' => 'yes'])->fails())->toBeTrue();
});

it('passes an explicit skip/unskip toggle', function (): void {
    expect(validatePlannedSessionUpdate(['skipped' => true])->passes())->toBeTrue()
        ->and(validatePlannedSessionUpdate(['skipped' => false])->passes())->toBeTrue();
});

it('rejects a non-boolean skipped value', function (): void {
    expect(validatePlannedSessionUpdate(['skipped' => 'yes'])->fails())->toBeTrue();
});

it('rejects a move that also sets skipped', function (bool $skipped): void {
    $validator = validatePlannedSessionUpdate(['date' => now()->addDay()->toDateString(), 'skipped' => $skipped]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('date'))->toBeTrue();
})->with([true, false]);

it('leaves which days a move may reach to the edit rules', function (): void {
    expect(validatePlannedSessionUpdate(['date' => now()->toDateString()])->passes())->toBeTrue()
        ->and(validatePlannedSessionUpdate(['date' => now()->subDay()->toDateString()])->passes())->toBeTrue();
});
